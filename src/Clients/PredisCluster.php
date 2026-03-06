<?php

namespace Apvalkov\LaravelPrometheus\Clients;

use Predis\Client;
use Prometheus\Exception\StorageException;

class PredisCluster implements Redis
{
    private const OPTIONS_MAP = [
        Redis::OPT_PREFIX => 'prefix',
    ];

    private ?Client $client = null;

    private array $seeds;
    private array $options;

    /**
     * @param array $seeds   List of seed nodes in "host:port" or ['host' => ..., 'port' => ...] format
     * @param array $options Additional Predis options (prefix, cluster, etc.)
     */
    public function __construct(array $seeds, array $options = [])
    {
        $this->seeds = $seeds;
        $this->options = array_merge([
            'cluster' => 'redis',
        ], $options);
    }

    /**
     * Create an instance wrapping an already-connected Predis cluster client.
     */
    public static function fromExistingConnection(Client $client): self
    {
        $clientOptions = $client->getOptions();
        $options = [
            'aggregate'   => $clientOptions->aggregate,
            'cluster'     => $clientOptions->cluster,
            'connections' => $clientOptions->connections,
            'exceptions'  => $clientOptions->exceptions,
            'prefix'      => $clientOptions->prefix,
            'commands'    => $clientOptions->commands,
            'replication' => $clientOptions->replication,
        ];

        $instance = new self([], $options);
        $instance->client = $client;

        return $instance;
    }

    /**
     * {@inheritDoc}
     */
    public function getOption(int $option): mixed
    {
        if (!isset(self::OPTIONS_MAP[$option])) {
            return null;
        }

        $mappedOption = self::OPTIONS_MAP[$option];

        return $this->options[$mappedOption] ?? null;
    }

    /**
     * {@inheritDoc}
     *
     * Routing is determined by the first key in $args (cluster-safe when related
     * keys share a hash tag so they land on the same node).
     */
    public function eval(string $script, array $args = [], int $num_keys = 0): void
    {
        $this->client->eval($script, $num_keys, ...$args);
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, mixed $value, mixed $options = null): bool
    {
        $result = $this->client->set($key, $value, ...$this->flattenFlags($options ?? []));

        return (string) $result === 'OK';
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
        return (bool) $this->client->hsetnx($key, $field, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function sMembers(string $key): array
    {
        $result = $this->client->smembers($key);

        // Predis может вернуть объект, приводим к массиву
        if (is_object($result) && method_exists($result, 'getArrayCopy')) {
            return $result->getArrayCopy();
        }

        return is_array($result) ? $result : [];
    }

    /**
     * {@inheritDoc}
     */
    public function hGetAll(string $key): array|false
    {
        $result = $this->client->hgetall($key);

        if ($result === null || $result === false) {
            return false;
        }

        return is_array($result) ? $result : false;
    }

    /**
     * {@inheritDoc}
     *
     * Iterates every master node to collect matching keys cluster-wide.
     */
    public function keys(string $pattern): array
    {
        $keys = [];

        // Get all cluster nodes
        $connection = $this->client->getConnection();

        if (method_exists($connection, 'executeCommand')) {
            // Predis cluster connection
            foreach ($connection as $node) {
                $it = 0;
                do {
                    $result = $node->scan($it, 'MATCH', $pattern, 'COUNT', 100);
                    if (isset($result[1]) && is_array($result[1])) {
                        $keys = array_merge($keys, $result[1]);
                        $it = (int) $result[0];
                    } else {
                        break;
                    }
                } while ($it != 0);
            }
        } else {
            // Fallback to KEYS command (less efficient but works)
            $keys = $this->client->keys($pattern);
        }

        return $keys;
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key): mixed
    {
        $result = $this->client->get($key);

        return $result === null ? false : $result;
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
     *
     * @throws StorageException
     */
    public function ensureOpenConnection(): void
    {
        if ($this->client !== null) {
            return;
        }

        try {
            $this->client = new Client($this->seeds, $this->options);
        } catch (\Exception $e) {
            throw new StorageException('Cannot establish Predis Cluster connection: ' . $e->getMessage());
        }
    }

    /**
     * Returns the underlying Predis client (after connection is open).
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * @param array<int|string, mixed> $flags
     * @return mixed[]
     */
    private function flattenFlags(array $flags): array
    {
        $result = [];

        foreach ($flags as $key => $value) {
            if (is_int($key)) {
                $result[] = $value;
            } else {
                $result[] = $key;
                $result[] = $value;
            }
        }

        return $result;
    }
}
