<?php

namespace LCache;

final class Entry
{
    protected $address;
    public $busy;
    public $created;
    public $event_id;
    public $expiration;
    public $rts;
    public $value;
    public $wts;

    public function __construct($event_id, string $address, $value, $created, $expiration = null)
    {
        $this->event_id = $event_id;
        $this->address = $address;
        $this->value = $value;
        $this->created = $created;
        $this->expiration = $expiration;
    }

    /**
     * Return the Address for this entry.
     */
    public function getAddress(): string
    {
        return $this->address;
    }

    /**
     * Return the time-to-live for this entry.
     * @return integer
     */
    public function getTTL()
    {
        if (is_null($this->expiration)) {
            return null;
        }
        $current = time();

        if ($this->expiration > $current) {
            return $this->expiration - $current;
        }
        return 0;
    }
}
