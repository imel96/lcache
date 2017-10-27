<?php

namespace LCache\l2;

use LCache\Address;
use LCache\Entry;
use LCache\UnserializationException;
use LCache\l1\L1;
use \Redis as PHPRedis;

class Redis extends L2
{
    protected $hits;
    protected $misses;
    protected $redis;
    protected $address_deletion_keys;
    protected $default_ttl;
    protected $prefix;
    private $autoserialize;
    const KEY_DELIMITER = ':';

    public function __construct(PHPRedis $redis, $prefix = '', $default_ttl = 0xffffffff)
    {
        $this->redis = $redis;
        $this->autoserialize = $redis->getOption(PHPRedis::OPT_SERIALIZER) != PHPRedis::SERIALIZER_NONE;
        $this->prefix = $prefix ? : 'lcache';
        $this->hits = 0;
        $this->misses = 0;
        $this->default_ttl = $default_ttl;
        $this->address_deletion_keys = [];
    }

    public function __destruct()
    {
        $this->pruneReplacedEvents();
    }

    public function pruneReplacedEvents()
    {
        // No deletions, nothing to do.
        if (empty($this->address_deletion_keys)) {
            return true;
        }

        // De-dupe the deletion patterns.
        $address_keys = array_unique($this->address_deletion_keys);
        $this->redis->multi()
            ->delete($address_keys)
            ->zRem($this->keyConstruct('event', 'ids'), ...$address_keys)
            ->exec();

        // Clear the queue.
        $this->address_deletion_keys = [];
        return true;
    }

    public function countGarbage()
    {
        return 0;
    }

    public function collectGarbage($item_limit = null)
    {
        return 0;
    }

    public function getHits()
    {
        return $this->hits;
    }

    public function getMisses()
    {
        return $this->misses;
    }

    protected function queueDeletion(Address $address)
    {
        $this->address_deletion_keys[] = $this->keyFromAddress($address);
    }

    public function exists(Address $address)
    {
        return !is_null($this->get($address));
    }

    protected function keyConstruct(...$parts)
    {
        return implode(self::KEY_DELIMITER, array_merge(
            $this->prefix ? [$this->prefix] : [],
            $parts
        ));
    }

    public function keyFromAddress(Address $address)
    {
        return $this->keyConstruct('event', $address->serialize());
    }

    public function keyFromTag($tag)
    {
        return $this->keyConstruct('tag', $tag);
    }

    public function getEntry(Address $address)
    {
        $key = $this->keyFromAddress($address);
        list($exists, $values) = $this->redis->multi()
            ->exists($key)
            ->hGetAll($key)
            ->exec();

        // If key does not exist, miss
        if (false === $exists) {
            $this->misses++;
            return null;
        }

        $entry = $this->entryFromHash($values);
        if (is_null($entry)) {
            $this->misses++;
            return null;
        }
        $this->hits++;
        return $entry;
    }

    private function entryFromHash(array $hash, $miss_on_delete = true)
    {
        return $this->entryFromHashValues(
            $hash['event_id'],
            $hash['address'],
            $hash['pool'],
            $hash['value'],
            $hash['tags'],
            $hash['created'],
            $hash['expire'],
            $miss_on_delete
        );
    }

    protected function entryFromHashValues($event_id, $address, $pool, $value, $tags, $created, $expire, $miss_on_delete)
    {
        $unserialized_value = !$this->autoserialize && $miss_on_delete ? @unserialize($value) : $value;
        $unserialized_tags = is_array($tags) ? $tags : @unserialize($tags);
        $unserialized_address = new Address();
        $unserialized_address->unserialize($address);

        if (!$this->autoserialize && $miss_on_delete) {
            // If last event was a deletion, miss
            if (is_null($unserialized_value)) {
                return null;
            }
            // If unserialization failed, raise an exception.
            if (false === $unserialized_value && serialize(false) !== $value) {
                // @codeCoverageIgnoreStart
                throw new UnserializationException($unserialized_address, $value);
                // @codeCoverageIgnoreEnd
            }
        }

        return new Entry(
            $event_id,
            $pool,
            $unserialized_address,
            $unserialized_value,
            $created,
            $expire,
            $unserialized_tags
        );
    }

    public function getAddressesForTag($tag)
    {
        return array_map(function (string $value) {
            $address = new Address();
            $address->unserialize($value);
            return $address;
        }, $this->redis->sMembers($this->keyFromTag($tag)));
    }

    public function delete($pool, Address $address)
    {
        return $this->set($pool, $address);
    }

    public function deleteTag(L1 $l1, $tag)
    {
        foreach ($this->getAddressesForTag($tag) as $address) {
            $l1->delete(
                $this->delete($l1->getPool(), $address),
                $address
            );
        }
        return $this->redis->delete($this->keyFromTag($tag));
    }

    private function redisSet(Address $address, $pool, $value, array $tags, $expiration)
    {
        $keys = [
            1 => $this->keyFromAddress($address),
            2 => $this->keyConstruct('event'),
            3 => $this->keyConstruct('tag') . self::KEY_DELIMITER,
            4 => $this->keyConstruct('event', 'ids'),
        ];
        $args = [
            1 => $address->serialize(),
            2 => $pool,
            3 => $value,
            4 => serialize($tags),
            5 => $this->created_time,
            6 => $expiration,
        ];
        // 1st stores using address as key, 2nd stores using event_id
        return $this->redis->eval("
            local event_id = redis.call('incr', KEYS[2])
            redis.call('hmset', KEYS[1],
                'pool', ARGV[2],
                'value', ARGV[3],
                'tags', ARGV[4],
                'created', ARGV[5],
                'expire', ARGV[6]
              )
            redis.call('expireat', KEYS[1], ARGV[6])
            for tag = 7, #ARGV do
                redis.call('sadd', KEYS[3] .. ARGV[tag], ARGV[1])
            end
            redis.call('zadd', KEYS[4], event_id, event_id)
            redis.call('hmset', event_id,
                'address', ARGV[1],
                'pool', ARGV[2],
                'value', ARGV[3],
                'tags', ARGV[4],
                'created', ARGV[5],
                'expire', ARGV[6]
              )
            redis.call('expireat', event_id, ARGV[6])

            return event_id
        ", array_merge($keys, $args, $tags), count($keys));
return $ret;
    }

    public function set($pool, Address $address, $value = null, $expiration = null, array $tags = [], $value_is_serialized = false)
    {
        // Handle bin and larger deletions immediately. Queue individual key deletions for shutdown.
        // Clearing an entire bin does not remove the items in that bin
        // from the tags or ids lookup keys which may cause artificial misses later.
        if ($address->isEntireBin() || $address->isEntireCache()) {
            $this->redis->eval(
                "
                  local keys = redis.call('keys', ARGV[1])
                  for i=1, #keys, 5000 do
                    redis.call('del', unpack(keys, i, math.min(i + 4999, #keys)))
                  end
                ",
                [$address->isEntireCache() ? $this->keyConstruct('event', '*') : $this->keyFromAddress($address) . '*']
            );
        } else if (is_null($value)) {
            $this->queueDeletion($address);
        }
        // Support pre-serialized values for testing purposes.
        if (!$value_is_serialized && !$this->autoserialize) {
            $value = serialize($value);
        }
        return $this->redisSet($address, $pool, $value, $tags, $expiration ?? $this->created_time + $this->default_ttl);
    }

    protected function getCurrentEventId() {
        return intval($this->redis->get($this->keyConstruct('event')));
    }

    protected function getLastEvents($last_applied_event_id, $pool) {
        $keys = $this->redis->zRange($this->keyConstruct('event', 'ids'), $last_applied_event_id, -1);
        $transaction = $this->redis->multi(PHPRedis::PIPELINE);
        $events = [];

        foreach ($keys as $key) {
            $transaction = $transaction->hGetAll($key);
        }
        foreach ($transaction->exec() as $values) {
            if (is_array($values) && !empty($values) && $values['pool'] != $pool) {
                $events[] = $this->entryFromHash($values, false);
            }
        }
        return $events;
    }
}
