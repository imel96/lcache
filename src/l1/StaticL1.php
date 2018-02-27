<?php

namespace LCache\l1;

use LCache\Entry;
use LCache\state\StateL1Interface;

class StaticL1 extends L1
{
    private static $cacheData = [];

    /** @var array Reference to the data array for the instance data pool. */
    protected $storage;

    public function __construct($pool, StateL1Interface $state)
    {
        parent::__construct($pool, $state);

        if (!isset(self::$cacheData)) {
            self::$cacheData = [];
        }
        $this->storage = &self::$cacheData;
    }

    public function setWithExpiration($event_id, string $address, $value, $created, $expiration = null)
    {
        $local_key = $address;

        // Don't overwrite local entries that are even newer or the same age.
        if (isset($this->storage[$local_key]) && $this->storage[$local_key]->event_id >= $event_id) {
            return true;
        }
        $this->storage[$local_key] = new Entry($event_id, $address, is_object($value) ? clone $value : $value, $created, $expiration);

        return true;
    }

    public function getEntry(string $address)
    {
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
var_dump(self::$cacheLine);
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
