<?php

namespace LCache\l1;

use LCache\LX;
use LCache\state\StateL1Interface;

abstract class L1 extends LX
{
    protected $pool;

    /** @var StateL1Interface */
    protected $state;

    /**
     * Constructor for all the L1 implementations.
     *
     * @param string $pool
     *   Pool ID to group the cache data in.
     * @param \LCache\StateL1Interface $state
     *   State manager class. Used to collect hit/miss statistics as well as
     *   the ID of the last cache mutation event.
     */
    public function __construct($pool, StateL1Interface $state)
    {
        $this->pool = $pool;
        $this->state = $state;
    }

    public function getLastAppliedEventID()
    {
        return $this->state->getLastAppliedEventID();
    }

    public function setLastAppliedEventID($event_id)
    {
        return $this->state->setLastAppliedEventID($event_id);
    }

    public function getPool()
    {
        return $this->pool;
    }

    public function set($event_id, string $address, $value = null, $expiration = null)
    {
        return $this->setWithExpiration($event_id, $address, $value, time(), $expiration);
    }

    abstract public function isNegativeCache(string $address): bool;

    abstract public function getKeyOverhead(string $address);
    abstract public function setWithExpiration($event_id, string $address, $value, $created, $expiration = null);
    abstract public function delete($event_id, string $address);

    public function getHits()
    {
        return $this->state->getHits();
    }

    public function getMisses()
    {
        return $this->state->getMisses();
    }

    protected function recordHit()
    {
        $this->state->recordHit();
    }

    protected function recordMiss()
    {
        $this->state->recordMiss();
    }
}
