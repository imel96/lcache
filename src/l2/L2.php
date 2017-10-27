<?php

namespace LCache\l2;

use LCache\Address;
use LCache\LX;
use LCache\l1\L1;

abstract class L2 extends LX
{
    abstract public function set($pool, Address $address, $value = null, $expiration = null, array $tags = [], $value_is_serialized = false);
    abstract public function delete($pool, Address $address);
    abstract public function deleteTag(L1 $l1, $tag);
    abstract public function getAddressesForTag($tag);
    abstract public function countGarbage();
    abstract protected function getCurrentEventId();
    abstract protected function getLastEvents($last_applied_event_id, $pool);

    /**
     * @param L1 $l1
     * @return null|int if L1 cache is empty or number of applied events
     */
    public function applyEvents(L1 $l1) {
        $last_applied_event_id = $l1->getLastAppliedEventID();

        // If the L1 cache is empty, bump the last applied ID
        // to the current high-water mark.
        if (is_null($last_applied_event_id)) {
            $l1->setLastAppliedEventID($this->getCurrentEventId());
            return null;
        }
        $applied = 0;

        foreach ($this->getLastEvents($last_applied_event_id, $l1->getPool()) as $event) {
            if (is_null($event->value)) {
                $l1->delete($event->event_id, $event->getAddress());
            } else {
                $unserialized_value = @unserialize($event->value);
                if (false === $unserialized_value && serialize(false) !== $event->value) {
                    // Delete the L1 entry, if any, when we fail to unserialize.
                    $l1->delete($event->event_id, $event->getAddress());
                } else {
                    $l1->setWithExpiration($event->event_id, $event->getAddress(), $unserialized_value, $event->created, $event->expiration);
                }
            }
            $last_applied_event_id = $event->event_id;
            $applied++;
        }

        // Just in case there were skipped events, set the high water mark.
        $l1->setLastAppliedEventID($last_applied_event_id);
        return $applied;
    }

    public function dumpEvents()
    {
        return $this->getLastEvents(0, 'xxx');
    }
}
