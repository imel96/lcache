<?php

namespace LCache;

use LCache\l1\L1;
use LCache\l2\L2;

final class Integrated
{
    protected $l1;
    protected $l2;

    public function __construct(L1 $l1, L2 $l2)
    {
        $this->l1 = $l1;
        $this->l2 = $l2;
    }

    // mRq.get_msg()
    public function set(string $address, $value, $ttl_or_expiration = null)
    {
        if (!$this->l1->isBusy($address)) {
            $this->l1->get(0, LX::STATE_MODIFY, $address);
            $this->l1->set(0, LX::STATE_MODIFY, $address, $value);
        }
        $expiration = null;
        $current = time();

        if (!is_null($ttl_or_expiration)) {
            if ($ttl_or_expiration < $current) {
                $expiration = $current + $ttl_or_expiration;
            } else {
                $expiration = $ttl_or_expiration;
            }
        }
        $event_id = $this->l2->set($this->l1->getPool(), $address, $value, $expiration);

        if (!is_null($event_id)) {
            $this->l1->get(LX::STATE_MODIFY, $address);
            $this->l1->set($event_id, LX::STATE_MODIFY, $address, $value, $expiration);
        }
        $this->l1->setBusy($address, false);
        return $event_id;
    }

    // mRq.get_msg()
    public function getEntry(string $address)
    {
        if ($this->l1->isBusy($address)) {
            return null;
        }
        if ($this->l1->getState($address) != LX::STATE_INIT) {
            // LoadHit
            $this->l1->get(LX::STATE_MODIFY, $address);

            return $this->l1->getEntry($address);
        }
/*
        $entry = $this->l1->getEntry($address);
        if (!is_null($entry)) {
            return $entry;
        }
*/
        // L1Miss
        $this->l1->setBusy($address, true);
        // p2c.get msg()
        $entry = $this->l2->getEntry($address);
        if (is_null($entry)) {
            // On an L2 miss, construct a negative cache entry that will be
            // overwritten on any update.
            $entry = new Entry(0, $this->l1->getPool(), $address, null, time(), null);
        }
        $this->l1->L2Response($address, false);

        $this->l1->setWithExpiration($entry->event_id, $address, $entry->value, $entry->created, $entry->expiration);

        if (!is_null($entry) && (!is_null($entry->value))) {
            return $entry;
        }
        return null;
    }

    public function get(string $address)
    {
        $entry = $this->getEntry($address);
        if (is_null($entry)) {
            return null;
        }
        return $entry->value;
    }

    public function exists(string $address)
    {
        $exists = $this->l1->exists($address);
        if ($exists) {
            return true;
        }
        return $this->l2->exists($address);
    }

    public function delete(string $address)
    {
        $event_id = $this->l2->delete($this->l1->getPool(), $address);

        if (!is_null($event_id)) {
            $this->l1->delete($event_id, $address);
        }
        return $event_id;
    }

    public function synchronize()
    {
        return $this->l2->applyEvents($this->l1);
    }

    public function getHitsL1()
    {
        return $this->l1->getHits();
    }

    public function getHitsL2()
    {
        return $this->l2->getHits();
    }

    public function getMisses()
    {
        return $this->l2->getMisses();
    }

    public function getLastAppliedEventID()
    {
        return $this->l1->getLastAppliedEventID();
    }

    public function getPool()
    {
        return $this->l1->getPool();
    }

    public function collectGarbage($item_limit = null)
    {
        return $this->l2->collectGarbage($item_limit);
    }
}
