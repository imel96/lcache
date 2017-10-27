<?php

namespace LCache\l2;

use LCache\Address;
use LCache\Entry;
use LCache\UnserializationException;
use LCache\l1\L1;

class StaticL2 extends L2
{
    protected $events;
    protected $current_event_id;
    protected $hits;
    protected $misses;
    protected $tags;

    public function __construct()
    {
        $this->events = array();
        $this->current_event_id = 0;
        $this->hits = 0;
        $this->misses = 0;
        $this->tags = [];
    }

    public function countGarbage()
    {
        $garbage = 0;
        foreach ($this->events as $event_id => $entry) {
            if ($entry->expiration < $this->created_time) {
                $garbage++;
            }
        }
        return $garbage;
    }

    public function collectGarbage($item_limit = null)
    {
        $deleted = 0;
        foreach ($this->events as $event_id => $entry) {
            if ($entry->expiration < $this->created_time) {
                unset($this->events[$event_id]);
                $deleted++;
            }
            if ($deleted === $item_limit) {
                break;
            }
        }
    }

    // Returns an LCache\Entry
    public function getEntry(Address $address)
    {
        $events = array_filter(
            $this->events,
            function (Entry $entry) use ($address) {
                return $entry->getAddress()->isMatch($address);
            }
        );
        $last_matching_entry = null;
        foreach ($events as $entry) {
            if ($entry->getAddress()->isEntireCache() || $entry->getAddress()->isEntireBin()) {
                $last_matching_entry = null;
            } elseif (!is_null($entry->expiration) && $entry->expiration < $this->created_time) {
                $last_matching_entry = null;
            } else {
                $last_matching_entry = clone $entry;
            }
        }
        // Last event was a deletion, so miss.
        if (is_null($last_matching_entry) || is_null($last_matching_entry->value)) {
            $this->misses++;
            return null;
        }

        $unserialized_value = @unserialize($last_matching_entry->value);

        // If unserialization failed, miss.
        if (false === $unserialized_value && serialize(false) !== $last_matching_entry->value) {
            throw new UnserializationException($address, $last_matching_entry->value);
        }

        $last_matching_entry->value = $unserialized_value;

        $this->hits++;
        return $last_matching_entry;
    }

    public function set($pool, Address $address, $value = null, $expiration = null, array $tags = [], $value_is_serialized = false)
    {
        $this->current_event_id++;

        // Serialize the value if it isn't already. We serialize the values
        // in static storage to make it more similar to other persistent stores.
        if (!$value_is_serialized) {
            $value = serialize($value);
        }
        $this->events[$this->current_event_id] = new Entry($this->current_event_id, $pool, $address, $value, $this->created_time, $expiration, $tags);

        // Delete redundant events to match Database implementation
        if ($address->isEntireBin() || $address->isEntireCache()) {
            foreach ($this->events as $event_id => $event) {
                if ($event->getAddress()->isMatch($address) && $event_id < $this->current_event_id) {
                    unset($this->events[$event_id]);
                }
            }
        }
        // Clear existing tags linked to the item. This is much more
        // efficient with database-style indexes.
        foreach ($this->tags as $tag => $addresses) {
            $addresses_to_keep = [];
            foreach ($addresses as $current_address) {
                if ($address !== $current_address) {
                    $addresses_to_keep[] = $current_address;
                }
            }
            $this->tags[$tag] = $addresses_to_keep;
        }

        // Set the tags on the new item.
        foreach ($tags as $tag) {
            if (isset($this->tags[$tag])) {
                $this->tags[$tag][] = $address;
            } else {
                $this->tags[$tag] = [$address];
            }
        }

        return $this->current_event_id;
    }

    public function delete($pool, Address $address)
    {
        if ($address->isEntireCache()) {
            $this->events = array();
        }
        return $this->set($pool, $address, null, null, [], true);
    }

    public function getAddressesForTag($tag)
    {
        return isset($this->tags[$tag]) ? $this->tags[$tag] : [];
    }

    public function deleteTag(L1 $l1, $tag)
    {
        // Materialize the tag deletion as individual key deletions.
        foreach ($this->getAddressesForTag($tag) as $address) {
            $event_id = $this->delete($l1->getPool(), $address);
            $l1->delete($event_id, $address);
        }
        unset($this->tags[$tag]);
        return $this->current_event_id;
    }

    protected function getCurrentEventId() {
        return $this->current_event_id;
    }

    protected function getLastEvents($last_applied_event_id, $pool) {
        $events = [];

        foreach ($this->events as $event_id => $event) {
            // Skip events that are too old or were created by the local L1.
            if ($event_id > $last_applied_event_id && $event->pool !== $pool) {
                $events[] = $event;
            }
        }
        return $events;
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
