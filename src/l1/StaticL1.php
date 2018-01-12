<?php

namespace LCache\l1;

use LCache\Entry;
use LCache\state\StateL1Interface;

class StaticL1 extends L1
{
    private static $cacheData = [];
    protected $key_overhead;

    /** @var array Reference to the data array for the instance data pool. */
    protected $storage;

    public function __construct($pool, StateL1Interface $state)
    {
        parent::__construct($pool, $state);

        $this->key_overhead = [];

        if (!isset(self::$cacheData[$this->pool])) {
            self::$cacheData[$this->pool] = [];
        }
        $this->storage = &self::$cacheData[$this->pool];
    }

    public function getKeyOverhead(string $address)
    {
        $local_key = $address;
        if (array_key_exists($local_key, $this->key_overhead)) {
            return $this->key_overhead[$local_key];
        }
        return 0;
    }

    public function setWithExpiration($event_id, string $address, $value, $created, $expiration = null)
    {
        $local_key = $address;

        // If not setting a negative cache entry, increment the key's overhead.
        if (!is_null($value)) {
            if (isset($this->key_overhead[$local_key])) {
                $this->key_overhead[$local_key]++;
            } else {
                $this->key_overhead[$local_key] = 1;
            }
        }

        // Don't overwrite local entries that are even newer or the same age.
        if (isset($this->storage[$local_key]) && $this->storage[$local_key]->event_id >= $event_id) {
            return true;
        }
        $this->storage[$local_key] = new Entry($event_id, $this->getPool(), $address, is_object($value) ? clone $value : $value, $created, $expiration);

        return true;
    }

    public function isNegativeCache(string $address): bool
    {
        return (isset($this->storage[$address]) && is_null($this->storage[$address]->value));
    }

    public function getEntry(string $address)
    {
        // Decrement the key's overhead.
        if (isset($this->key_overhead[$address])) {
            $this->key_overhead[$address]--;
        } else {
            $this->key_overhead[$address] = -1;
        }

        if (!array_key_exists($address, $this->storage)) {
            $this->recordMiss();
            return null;
        }
        $entry = $this->storage[$address];

        if ($entry->getTTL() === 0) {
            unset($this->storage[$address]);
            $this->recordMiss();
            return null;
        }

        $this->recordHit();

        return $entry;
    }

    public function delete($event_id, string $address): bool
    {
        if ($address == '*') {
            $this->storage = [];
            $this->state->clear();

        } else {
            $this->setLastAppliedEventID($event_id);
            // @TODO: Consider adding "race" protection here, like for set.
            unset($this->storage[$address]);
        }
        return true;
    }
}
