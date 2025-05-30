<?php

namespace Apvalkov\LaravelPrometheus\Clients;

interface Redis
{
    public const OPT_PREFIX = 2;

    public const OPT_READ_TIMEOUT = 3;

    /**
     * @param int $option
     *
     * @return mixed
     */
    public function getOption(int $option): mixed;

    /**
     * @param string $script
     * @param array  $args
     * @param int    $num_keys
     *
     * @return void
     */
    public function eval(string $script, array $args = [], int $num_keys = 0): void;

    /**
     * @param string     $key
     * @param mixed      $value
     * @param mixed|null $options
     *
     * @return bool
     */
    public function set(string $key, mixed $value, mixed $options = null): bool;

    /**
     * @param string $key
     * @param mixed  $value
     *
     * @return void
     */
    public function setNx(string $key, mixed $value): void;

    /**
     * @param string $key
     * @param string $field
     * @param mixed  $value
     *
     * @return bool
     */
    public function hSetNx(string $key, string $field, mixed $value): bool;

    /**
     * @param string $key
     *
     * @return array
     */
    public function sMembers(string $key): array;

    /**
     * @param string $key
     *
     * @return array|false
     */
    public function hGetAll(string $key): array|false;

    /**
     * @param string $pattern
     *
     * @return mixed
     */
    public function keys(string $pattern): array;

    /**
     * @param string $key
     *
     * @return mixed
     */
    public function get(string $key): mixed;

    /**
     * @param array|string $key
     * @param string       ...$other_keys
     *
     * @return void
     */
    public function del(array|string $key, string ...$other_keys): void;

    /**
     * @return void
     */
    public function ensureOpenConnection(): void;
}
