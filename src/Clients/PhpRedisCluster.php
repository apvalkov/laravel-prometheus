<?php

namespace Apvalkov\LaravelPrometheus\Clients;

use Prometheus\Exception\StorageException;

class PhpRedisCluster implements Redis
{
    private ?\RedisCluster $client = null;

    private array $seeds;
    private float $timeout;
    private float $readTimeout;
    private bool $persistent;
    /** @var string|array|null */
    private mixed $auth;
    private array $options;

    /**
     * @param string[]          $seeds       List of seed nodes in "host:port" format
     * @param float             $timeout     Connection timeout in seconds
     * @param float             $readTimeout Read/write timeout in seconds
     * @param bool              $persistent  Use persistent connections
     * @param string|array|null $auth        Password or [user, password] for ACL
     * @param array             $options     Additional options passed via setOption()
     */
    public function __construct(
        array $seeds,
        float $timeout = 0.1,
        float $readTimeout = 10.0,
        bool $persistent = false,
        mixed $auth = null,
        array $options = []
    ) {
        $this->seeds       = $seeds;
        $this->timeout     = $timeout;
        $this->readTimeout = $readTimeout;
        $this->persistent  = $persistent;
        $this->auth        = $auth;
        $this->options     = $options;
    }

    /**
     * Create an instance wrapping an already-connected \RedisCluster client.
     */
    public static function fromExistingConnection(\RedisCluster $client): self
    {
        $instance         = new self([]);
        $instance->client = $client;

        return $instance;
    }

    /**
     * {@inheritDoc}
     */
    public function getOption(int $option): mixed
    {
        if ($this->client === null) {
            return null;
        }

        return $this->client->getOption($option);
    }

    /**
     * {@inheritDoc}
     *
     * Routing is determined by the first key in $args (cluster-safe when related
     * keys share a hash tag so they land on the same node).
     */
    public function eval(string $script, array $args = [], int $num_keys = 0): void
    {
        $this->client->eval($script, $args, $num_keys);
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, mixed $value, mixed $options = null): bool
    {
        if ($options === null) {
            return (bool) $this->client->set($key, $value);
        }

        // Convert ['NX', 'EX' => 3600] to ext-redis array format: ['nx', 'ex' => 3600]
        $extOptions = [];
        foreach ((array) $options as $k => $v) {
            if (is_int($k)) {
                $extOptions[] = strtolower((string) $v);
            } else {
                $extOptions[strtolower($k)] = $v;
            }
        }

        return (bool) $this->client->set($key, $value, $extOptions);
    }

    /**
     * {@inheritDoc}
     */
    public function setNx(string $key, mixed $value): void
    {
        $this->client->setnx($key, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function hSetNx(string $key, string $field, mixed $value): bool
    {
        return (bool) $this->client->hSetNx($key, $field, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function sMembers(string $key): array
    {
        return $this->client->sMembers($key) ?: [];
    }

    /**
     * {@inheritDoc}
     */
    public function hGetAll(string $key): array|false
    {
        $result = $this->client->hGetAll($key);

        return is_array($result) ? $result : false;
    }

    /**
     * {@inheritDoc}
     *
     * Iterates every master node via SCAN to collect matching keys cluster-wide.
     * Each element from _masters() is a [host, port] array accepted directly by scan().
     */
    public function keys(string $pattern): array
    {
        $keys = [];

        foreach ($this->client->_masters() as $node) {
            $it = null;

            do {
                $result = $this->client->scan($it, $node, $pattern, 100);
                if ($result !== false) {
                    $keys = array_merge($keys, $result);
                }
            } while ($it != 0);
        }

        return $keys;
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key): mixed
    {
        $result = $this->client->get($key);

        return $result === false ? false : $result;
    }

    /**
     * {@inheritDoc}
     *
     * Deletes keys one by one because multi-key DEL requires all keys to be
     * in the same hash slot in cluster mode.
     */
    public function del(array|string $key, string ...$other_keys): void
    {
        $all = is_array($key) ? $key : array_merge([$key], $other_keys);

        foreach ($all as $k) {
            $this->client->del($k);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function ensureOpenConnection(): void
    {
        if ($this->client !== null) {
            return;
        }

        try {
            $this->client = new \RedisCluster(
                null,
                $this->seeds,
                $this->timeout,
                $this->readTimeout,
                $this->persistent,
                $this->auth
            );

            foreach ($this->options as $option => $value) {
                $this->client->setOption($option, $value);
            }
        } catch (\RedisClusterException $e) {
            throw new StorageException('Cannot establish Redis Cluster connection: ' . $e->getMessage());
        }
    }

    /**
     * Returns the underlying \RedisCluster client (after connection is open).
     */
    public function getClient(): \RedisCluster
    {
        return $this->client;
    }
}
