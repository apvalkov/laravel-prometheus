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
- Registers `Adapter` (storage backend), `CollectorRegistry`, and `Predis` adapter as singletons
- `CollectorRegistry` is scoped (per-request), while `Adapter` is a singleton
- Publishes `config/prometheus.php` (single key: `PROMETHEUS_STORAGE_DRIVER`, default `in_memory`)

**Storage driver selection** ([src/StorageAdapterManager.php](src/StorageAdapterManager.php))
- Extends Laravel's `Manager` class; driver is resolved from `prometheus.storage_driver` config
- Supported drivers: `in_memory` (uses `Prometheus\Storage\InMemory`) and `predis`

**Adapter layer** (`src/Adapters/`)
- `AbstractRedis` — implements `Prometheus\Storage\Adapter` and all metric update/collect logic using Lua scripts for atomic Redis operations. All metric state is stored in Redis hashes/sets under the `PROMETHEUS_` prefix.
- `Predis` extends `AbstractRedis` — wraps a Predis client. Can be instantiated standalone or via `fromExistingConnection()` to reuse Laravel's existing Redis connection (used by the service provider).

**Redis client abstraction** (`src/Clients/`)
- `Redis` interface — thin abstraction over Redis operations used by `AbstractRedis`
- `Clients\Predis` — implements `Redis` interface by delegating to `\Predis\Client`; handles lazy connection via `ensureOpenConnection()`

**Metric wrappers** (`src/Metrics/`)
- `Metric` (abstract base), `Counter`, `Gauge`, `Histogram` — thin wrappers around `prometheus_client_php` types that delegate to `CollectorRegistry::getOrRegister*()` methods

### Key design decisions

- The package does NOT provide HTTP routes for the `/metrics` endpoint — consumers must expose it themselves using `\Prometheus\RenderTextFormat` and the `CollectorRegistry`.
- When using `predis` driver in Laravel, the adapter reuses Laravel's configured Redis connection (`app('redis')->client()`), inheriting its prefix and cluster/replication options.
- Redis keys use the pattern `PROMETHEUS_:<type>:<name>` for metric hashes and `PROMETHEUS_<TYPE>_METRIC_KEYS` for the sets tracking which metric keys exist.
