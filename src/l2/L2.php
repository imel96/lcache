<?php

namespace LCache\l2;

use LCache\LX;
use LCache\l1\L1;

abstract class L2 extends LX
{
    abstract public function applyEvents(L1 $l1);
    abstract public function set(string $address, $value = null, $expiration = null, $value_is_serialized = false);
    abstract public function delete(string $address);
    abstract public function countGarbage();
}
