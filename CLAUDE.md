# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

Install dependencies:
```bash
composer install
```

There are no test scripts defined in `composer.json`. Check for a test runner manually if tests are added.

## Architecture

This is a Laravel package (`apvalkov/laravel-prometheus`) that integrates the `promphp/prometheus_client_php` library into Laravel with Redis-backed storage.

### Layer structure

**Service Provider** ([src/PrometheusServiceProvider.php](src/PrometheusServiceProvider.php))
- Registers `Adapter` (storage backend), `CollectorRegistry`, `Predis`, and `RedisCluster` adapters as singletons
- `CollectorRegistry` is scoped (per-request), while `Adapter` is a singleton
- Publishes `config/prometheus.php` (single key: `PROMETHEUS_STORAGE_DRIVER`, default `in_memory`)

**Storage driver selection** ([src/StorageAdapterManager.php](src/StorageAdapterManager.php))
- Extends Laravel's `Manager` class; driver is resolved from `prometheus.storage_driver` config
- Supported drivers: `in_memory`, `predis`, `redis_cluster`

**Adapter layer** (`src/Adapters/`)
- `AbstractRedis` — implements `Prometheus\Storage\Adapter` and all metric update/collect logic using Lua scripts for atomic Redis operations. Uses `getKeyPrefix()` (overridable) for all key construction; all metric state is stored in Redis hashes/sets under that prefix.
- `Predis` extends `AbstractRedis` — wraps a Predis client. Can be instantiated standalone or via `fromExistingConnection()` to reuse Laravel's existing Redis connection.
- `RedisCluster` extends `AbstractRedis` — cluster-safe adapter using PHP ext-redis (`\RedisCluster`). Overrides `getKeyPrefix()` to wrap the prefix in a hash tag (`{PROMETHEUS_}`), forcing all metric keys onto the same cluster slot so multi-key Lua scripts work correctly. Overrides `wipeStorage()` to use PHP-level SCAN across all master nodes instead of the Lua SCAN (which cannot route without a key).

**Redis client abstraction** (`src/Clients/`)
- `Redis` interface — thin abstraction over Redis operations used by `AbstractRedis`
- `Clients\Predis` — implements `Redis` interface by delegating to `\Predis\Client`; handles lazy connection via `ensureOpenConnection()`
- `Clients\PhpRedisCluster` — implements `Redis` interface using PHP's `\RedisCluster` extension. `keys()` iterates all master nodes via `SCAN` (cluster-wide pattern search). `del()` deletes keys one at a time (multi-key `DEL` is not cluster-safe across slots). Docs: https://github.com/phpredis/phpredis/blob/develop/cluster.md

**Metric wrappers** (`src/Metrics/`)
- `Metric` (abstract base), `Counter`, `Gauge`, `Histogram` — thin wrappers around `prometheus_client_php` types that delegate to `CollectorRegistry::getOrRegister*()` methods

### Key design decisions

- The package does NOT provide HTTP routes for the `/metrics` endpoint — consumers must expose it themselves using `\Prometheus\RenderTextFormat` and the `CollectorRegistry`.
- When using `predis` or `redis_cluster` driver, the adapter reuses Laravel's configured Redis connection (`app('redis')->client()`), inheriting its prefix and cluster options.
- Regular Redis key layout: `PROMETHEUS_:<type>:<name>` (metric hash) and `PROMETHEUS_<TYPE>_METRIC_KEYS` (index set). Cluster layout: same but wrapped in hash tag — `{PROMETHEUS_}:<type>:<name>` and `{PROMETHEUS_}<TYPE>_METRIC_KEYS`.
- The hash-tag strategy (`{PROMETHEUS_}`) routes all Prometheus keys to a single cluster slot. This is intentional: it ensures correctness of multi-key Lua scripts at the cost of not distributing metric data across nodes.
- `AbstractRedis` uses `getKeyPrefix()` in all key construction so subclasses can control the prefix without duplicating logic.
