<?php

namespace Apvalkov\LaravelPrometheus\Adapters;

use Apvalkov\LaravelPrometheus\Clients\PredisCluster as PredisClusterClient;
use Apvalkov\LaravelPrometheus\Clients\Redis;
use Predis\Client;
use Prometheus\Exception\StorageException;

/**
 * Predis Cluster storage adapter using Predis library with cluster support.
 *
 * All metric keys are tagged with {PROMETHEUS_} so that a metric's data hash
 * and its type-index set always land on the same cluster slot, making the
 * multi-key Lua scripts in AbstractRedis cluster-safe.
 *
 * Key layout (example):
 *   {PROMETHEUS_}:counter:namespace_name   — metric data hash
 *   {PROMETHEUS_}counter_METRIC_KEYS       — set tracking all counter hashes
 */
class PredisCluster extends AbstractRedis
{
    public function __construct(PredisClusterClient $client)
    {
        $this->redis = $client;
    }

    /**
     * Create an instance wrapping an existing Predis cluster connection
     * (e.g. from Laravel's Redis manager when using the predis driver).
     */
    public static function fromExistingConnection(Client $client): self
    {
        return new self(PredisClusterClient::fromExistingConnection($client));
    }

    /**
     * Returns the key prefix wrapped in a Redis hash tag so that all
     * Prometheus keys hash to the same cluster slot.
     */
    protected function getKeyPrefix(): string
    {
        return '{' . self::$prefix . '}';
    }

    /**
     * {@inheritDoc}
     *
     * Overrides the Lua-based wipeStorage from AbstractRedis because that Lua
     * script uses SCAN with 0 keys and cannot be routed in cluster mode.
     * Instead, we find all matching keys via PHP-level SCAN across all master
     * nodes and delete them individually.
     *
     * @throws StorageException
     */
    public function wipeStorage(): void
    {
        $this->redis->ensureOpenConnection();

        $searchPattern = '';

        $globalPrefix = $this->redis->getOption(Redis::OPT_PREFIX);
        if (is_string($globalPrefix)) {
            $searchPattern .= $globalPrefix;
        }

        $searchPattern .= $this->getKeyPrefix() . '*';

        foreach ($this->redis->keys($searchPattern) as $key) {
            $this->redis->del($key);
        }
    }
}
