<?php

namespace LCache\l2;

use LCache\Entry;
use LCache\UnserializationException;
use LCache\l1\L1;

class Database extends L2
{
    protected $hits;
    protected $misses;
    protected $dbh;
    protected $log_locally;
    protected $errors;
    protected $table_prefix;
    protected $address_deletion_patterns;
    protected $event_id_low_water;

    public function __construct($dbh, $table_prefix = '', $log_locally = false)
    {
        $this->hits = 0;
        $this->misses = 0;
        $this->dbh = $dbh;
        $this->log_locally = $log_locally;
        $this->errors = array();
        $this->table_prefix = $table_prefix;
        $this->address_deletion_patterns = [];
        $this->event_id_low_water = null;
    }


    protected function prefixTable($base_name)
    {
        return $this->table_prefix . $base_name;
    }

    public function pruneReplacedEvents()
    {
        // No deletions, nothing to do.
        if (empty($this->address_deletion_patterns)) {
            return true;
        }

        // De-dupe the deletion patterns.
        // @TODO: Have bin deletions replace key deletions?
        $deletions = array_values(array_unique($this->address_deletion_patterns));

        $filler = implode(',', array_fill(0, count($deletions), '?'));
        try {
            $sth = $this->dbh->prepare('DELETE FROM ' . $this->prefixTable('lcache_events') .' WHERE "event_id" < ? AND "address" IN ('. $filler .')');
            $sth->bindValue(1, $this->event_id_low_water, \PDO::PARAM_INT);
            foreach ($deletions as $i => $address) {
                $sth->bindValue($i + 2, $address, \PDO::PARAM_STR);
            }
            $sth->execute();
        } catch (\PDOException $e) {
            $this->logSchemaIssueOrRethrow('Failed to perform batch deletion', $e);
            return false;
        }

        // Clear the queue.
        $this->address_deletion_patterns = [];
        return true;
    }

    public function __destruct()
    {
        $this->pruneReplacedEvents();
    }

    public function countGarbage()
    {
        try {
            $sth = $this->dbh->query('SELECT COUNT(*) garbage FROM ' . $this->prefixTable('lcache_events') . ' WHERE "expiration" < ' . time());
        } catch (\PDOException $e) {
            $this->logSchemaIssueOrRethrow('Failed to count garbage', $e);
            return null;
        }

        $count = $sth->fetchObject();
        return intval($count->garbage);
    }

    public function collectGarbage($item_limit = null)
    {
        $sql = 'DELETE FROM ' . $this->prefixTable('lcache_events') . ' WHERE "expiration" < ' . time();
        // This is not supported by standard SQLite.
        // @codeCoverageIgnoreStart
        if (!is_null($item_limit)) {
            $sql .= ' ORDER BY "event_id" LIMIT :item_limit';
        }
        // @codeCoverageIgnoreEnd
        try {
            $sth = $this->dbh->prepare($sql);
            // This is not supported by standard SQLite.
            // @codeCoverageIgnoreStart
            if (!is_null($item_limit)) {
                $sth->bindValue(':item_limit', $item_limit, \PDO::PARAM_INT);
            }
            // @codeCoverageIgnoreEnd
            $sth->execute();
            return $sth->rowCount();
        } catch (\PDOException $e) {
            $this->logSchemaIssueOrRethrow('Failed to collect garbage', $e);
        }
        return false;
    }

    protected function queueDeletion(string $address)
    {
        $this->address_deletion_patterns[] = $address;
    }

    protected function logSchemaIssueOrRethrow($description, $pdo_exception)
    {
        $log_only = array(/* General error */ 'HY000',
                      /* Unknown column */ '42S22',
                      /* Base table for view not found */ '42S02');

        if (in_array($pdo_exception->getCode(), $log_only, true)) {
            $text = 'LCache Database: ' . $description . ' : ' . $pdo_exception->getMessage();
            if ($this->log_locally) {
                $this->errors[] = $text;
            } else {
                // @codeCoverageIgnoreStart
                trigger_error($text, E_USER_WARNING);
                // @codeCoverageIgnoreEnd
            }
            return;
        }

        // Rethrow anything not whitelisted.
        // @codeCoverageIgnoreStart
        throw $pdo_exception;
        // @codeCoverageIgnoreEnd
    }

    public function getErrors()
    {
        if (!$this->log_locally) {
            // @codeCoverageIgnoreStart
            throw new Exception('Requires setting $log_locally=TRUE on instantiation.');
            // @codeCoverageIgnoreEnd
        }
        return $this->errors;
    }

    // Returns an LCache\Entry
    public function getEntry(string $address)
    {
        $current = time();

        try {
            $sth = $this->dbh->prepare('SELECT e.event_id, "pool", "address", "value", "created", "expiration", GROUP_CONCAT(tag, \',\') AS tags
              FROM ' . $this->prefixTable('lcache_events') .' e LEFT JOIN ' . $this->prefixTable('lcache_tags') . ' t ON t.event_id = e.event_id
              WHERE "address" = :address AND ("expiration" >= ' . $current . ' OR "expiration" IS NULL)
              GROUP BY e.event_id ORDER BY e.event_id DESC LIMIT 1');
            $sth->bindValue(':address', $address, \PDO::PARAM_STR);
            $sth->execute();
        } catch (\PDOException $e) {
            $this->logSchemaIssueOrRethrow('Failed to search database for cache item', $e);
            return null;
        }
        $last_matching_entry = $sth->fetchObject();

        if (false === $last_matching_entry) {
            $this->misses++;
            return null;
        }

        // If last event was a deletion or ttl == 0, miss.
        if (is_null($last_matching_entry->value) || $last_matching_entry->expiration == $current) {
            $this->misses++;
            return null;
        }

        $unserialized_value = @unserialize($last_matching_entry->value);

        // If unserialization failed, raise an exception.
        if (false === $unserialized_value && serialize(false) !== $last_matching_entry->value) {
            throw new UnserializationException($address, $last_matching_entry->value);
        }

        $last_matching_entry->value = $unserialized_value;
        $this->hits++;

        if (is_null($last_matching_entry->tags)) {
            $last_matching_entry->tags = [];
        } else {
            $last_matching_entry->tags = explode(",", $last_matching_entry->tags);
        }

        return $last_matching_entry;
    }

    // Returns the event entry. Currently used only for testing.
    public function getEvent($event_id)
    {
        $sth = $this->dbh->prepare('SELECT * FROM ' . $this->prefixTable('lcache_events') .' WHERE event_id = :event_id');
        $sth->bindValue(':event_id', $event_id, \PDO::PARAM_INT);
        $sth->execute();
        $event = $sth->fetchObject();
        if (false === $event) {
            return null;
        }
        $event->value = unserialize($event->value);
        return $event;
    }

    public function exists(string $address)
    {
        $current = time();

        try {
            $sth = $this->dbh->prepare('SELECT "event_id", ("value" IS NOT NULL) AS value_not_null, "value", "expiration" FROM ' . $this->prefixTable('lcache_events') .' WHERE "address" = :address AND ("expiration" >= ' . $current . ' OR "expiration" IS NULL) ORDER BY "event_id" DESC LIMIT 1');
            $sth->bindValue(':address', $address, \PDO::PARAM_STR);
            $sth->execute();
        } catch (\PDOException $e) {
            $this->logSchemaIssueOrRethrow('Failed to search database for cache item existence', $e);
            return null;
        }
        $result = $sth->fetchObject();
        return ($result !== false && $result->value_not_null && $result->expiration != $current);
    }

    /**
     * @codeCoverageIgnore
     */
    public function debugDumpState()
    {
        echo PHP_EOL . PHP_EOL . 'Events:' . PHP_EOL;
        $sth = $this->dbh->prepare('SELECT * FROM ' . $this->prefixTable('lcache_events') . ' ORDER BY "event_id"');
        $sth->execute();
        while ($event = $sth->fetchObject()) {
            print_r($event);
        }
        echo PHP_EOL;
        echo 'Tags:' . PHP_EOL;
        $sth = $this->dbh->prepare('SELECT * FROM ' . $this->prefixTable('lcache_tags') . ' ORDER BY "tag"');
        $sth->execute();
        $tags_found = false;
        while ($event = $sth->fetchObject()) {
            print_r($event);
            $tags_found = true;
        }
        if (!$tags_found) {
            echo 'No tag data.' . PHP_EOL;
        }
        echo PHP_EOL;
    }

    public function set($pool, string $address, $value = null, $expiration = null, $value_is_serialized = false)
    {
        // Support pre-serialized values for testing purposes.
        if (!$value_is_serialized) {
            $value = is_null($value) ? null : serialize($value);
        }

        try {
            $sth = $this->dbh->prepare('INSERT INTO ' . $this->prefixTable('lcache_events') . ' ("pool", "address", "value", "created", "expiration") VALUES (:pool, :address, :value, ' . time() . ', :expiration)');
            $sth->bindValue(':pool', $pool, \PDO::PARAM_STR);
            $sth->bindValue(':address', $address, \PDO::PARAM_STR);
            $sth->bindValue(':value', $value, \PDO::PARAM_LOB);
            $sth->bindValue(':expiration', $expiration, \PDO::PARAM_INT);
            $sth->execute();
        } catch (\PDOException $e) {
            $this->logSchemaIssueOrRethrow('Failed to store cache event', $e);
            return null;
        }
        $event_id = $this->dbh->lastInsertId();

        if ($address == '*') {
            $sth = $this->dbh->prepare('DELETE FROM ' . $this->prefixTable('lcache_events') .' WHERE "event_id" < :new_event_id');
            $sth->bindValue(':new_event_id', $event_id, \PDO::PARAM_INT);
            $sth->execute();

        } else {

            if (is_null($this->event_id_low_water)) {
                $this->event_id_low_water = $event_id;
            }
        }
        $this->queueDeletion($address);
        return $event_id;
    }

    public function delete($pool, string $address)
    {
        $event_id = $this->set($pool, $address);
        return $event_id;
    }

    public function applyEvents(L1 $l1)
    {
        $last_applied_event_id = $l1->getLastAppliedEventID();

        // If the L1 cache is empty, bump the last applied ID
        // to the current high-water mark.
        if (is_null($last_applied_event_id)) {
            try {
                $sth = $this->dbh->prepare('SELECT "event_id" FROM ' . $this->prefixTable('lcache_events') . ' ORDER BY "event_id" DESC LIMIT 1');
                $sth->execute();
            } catch (\PDOException $e) {
                $this->logSchemaIssueOrRethrow('Failed to initialize local event application status', $e);
                return null;
            }
            $last_event = $sth->fetchObject();
            if (false === $last_event) {
                $l1->setLastAppliedEventID(0);
            } else {
                $l1->setLastAppliedEventID($last_event->event_id);
            }
            return null;
        }

        $applied = 0;
        try {
            $sth = $this->dbh->prepare('SELECT "event_id", "pool", "address", "value", "created", "expiration" FROM ' . $this->prefixTable('lcache_events') . ' WHERE "event_id" > :last_applied_event_id AND "pool" <> :exclude_pool ORDER BY event_id');
            $sth->bindValue(':last_applied_event_id', $last_applied_event_id, \PDO::PARAM_INT);
            $sth->bindValue(':exclude_pool', $l1->getPool(), \PDO::PARAM_STR);
            $sth->execute();
        } catch (\PDOException $e) {
            $this->logSchemaIssueOrRethrow('Failed to fetch events', $e);
            return null;
        }

        //while ($event = $sth->fetchObject('LCacheEntry')) {
        while ($event = $sth->fetchObject()) {
            $address = $event->address;
            if (is_null($event->value)) {
                $l1->delete($event->event_id, $address);
            } else {
                $unserialized_value = @unserialize($event->value);
                if (false === $unserialized_value && serialize(false) !== $event->value) {
                    // Delete the L1 entry, if any, when we fail to unserialize.
                    $l1->delete($event->event_id, $address);
                } else {
                    $event->value = $unserialized_value;
                    $address = $event->address;
                    $l1->setWithExpiration($event->event_id, $address, $event->value, $event->created, $event->expiration);
                }
            }
            $last_applied_event_id = $event->event_id;
            $applied++;
        }

        // Just in case there were skipped events, set the high water mark.
        $l1->setLastAppliedEventID($last_applied_event_id);

        return $applied;
    }

    public function getHits()
    {
        return $this->hits;
    }

    public function getMisses()
    {
        return $this->misses;
    }
}
