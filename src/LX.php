<?php

namespace LCache;

/**
 * Base class for the various cache classes.
 */
abstract class LX
{
    const STATE_INIT = 0;
    const STATE_SHARED = 1;
    const STATE_MODIFY = 2;

    abstract public function getEntry(string $address);
    abstract public function getHits();
    abstract public function getMisses();

    /**
     * Fetch a value from the cache.
     * @return string|null
     */
    public function get(string $address)
    {
        $entry = $this->getEntry($address);
        if (is_null($entry)) {
            return null;
        }
        return $entry->value;
    }

    /**
     * Determine whether or not the specified Address exists in the cache.
     * @return boolean
     */
    public function exists(string $address)
    {
        $value = $this->get($address);
        return !is_null($value);
    }

    public function collectGarbage($item_limit = null)
    {
        return 0;
    }
}
