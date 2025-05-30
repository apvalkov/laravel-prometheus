<?php

namespace Apvalkov\LaravelPrometheus\Clients;

use InvalidArgumentException;
use Predis\Client;
use Prometheus\Exception\StorageException;

class Predis implements Redis
{
    private const OPTIONS_MAP = [
        Redis::OPT_PREFIX => 'prefix',
    ];
    private ?Client $client;
    private array   $parameters;
    private array   $options;

    /**
     * @param array       $parameters
     * @param array       $options
     * @param Client|null $redis
     */
    public function __construct(array $parameters, array $options, ?Client $redis = null)
    {
        $this->client = $redis;

        $this->parameters = $parameters;
        $this->options    = $options;
    }

    /**
     * @param array $parameters
     * @param array $options
     *
     * @return self
     */
    public static function create(array $parameters, array $options): self
    {
        return new self($parameters, $options);
    }

    /**
     * @param int $option
     *
     * @return mixed
     */
    public function getOption(int $option): mixed
    {
        if (! isset(self::OPTIONS_MAP[$option])) {
            return null;
        }

        $mappedOption = self::OPTIONS_MAP[$option];

        return $this->options[$mappedOption] ?? null;
    }

    /**
     * @param string $script
     * @param array  $args
     * @param int    $num_keys
     *
     * @return void
     */
    public function eval(string $script, array $args = [], int $num_keys = 0): void
    {
        $this->client->eval($script, $num_keys, ...$args);
    }

    /**
     * @param string     $key
     * @param mixed      $value
     * @param mixed|null $options
     *
     * @return bool
     */
    public function set(string $key, mixed $value, mixed $options = null): bool
    {
        $result = $this->client->set($key, $value, ...$this->flattenFlags($options));

        return (string) $result === 'OK';
    }

    /**
     * @param string $key
     * @param mixed  $value
     *
     * @return void
     */
    public function setNx(string $key, mixed $value): void
    {
        $this->client->setnx($key, $value) === 1;
    }

    /**
     * @param string $key
     * @param string $field
     * @param mixed  $value
     *
     * @return bool
     */
    public function hSetNx(string $key, string $field, mixed $value): bool
    {
        return $this->hSetNx($key, $field, $value);
    }

    /**
     * @param string $key
     *
     * @return array
     */
    public function sMembers(string $key): array
    {
        return $this->client->smembers($key);
    }

    /**
     * @param string $key
     *
     * @return array|false
     */
    public function hGetAll(string $key): array|false
    {
        return $this->client->hgetall($key);
    }

    /**
     * @param string $pattern
     *
     * @return array
     */
    public function keys(string $pattern): array
    {
        return $this->client->keys($pattern);
    }

    /**
     * @param string $key
     *
     * @return mixed
     */
    public function get(string $key): mixed
    {
        return $this->client->get($key);
    }

    /**
     * @param array|string $key
     * @param string       ...$other_keys
     *
     * @return void
     */
    public function del(array|string $key, string ...$other_keys): void
    {
        $this->client->del($key, ...$other_keys);
    }

    /**
     * @throws StorageException
     */
    public function ensureOpenConnection(): void
    {
        if ($this->client === null) {
            try {
                $this->client = new Client($this->parameters, $this->options);
            } catch (InvalidArgumentException $e) {
                throw new StorageException('Cannot establish Redis Connection:' . $e->getMessage());
            }
        }
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
