<?php

namespace Apvalkov\LaravelPrometheus\Adapters;

use InvalidArgumentException;
use Prometheus\Counter;
use Prometheus\Exception\MetricJsonException;
use Prometheus\Exception\StorageException;
use Prometheus\Gauge;
use Prometheus\Histogram;
use Prometheus\Math;
use Prometheus\MetricFamilySamples;
use Prometheus\Storage\Adapter;
use Apvalkov\LaravelPrometheus\Clients\Redis;
use Apvalkov\LaravelPrometheus\Clients\Exceptions\RedisClientException;
use Prometheus\Summary;
use RuntimeException;

abstract class AbstractRedis implements Adapter
{
    public const PROMETHEUS_METRIC_KEYS_SUFFIX = '_METRIC_KEYS';
    protected static string $prefix            = 'PROMETHEUS_';

    protected Redis $redis;

    /**
     * @param string $prefix
     *
     * @return void
     */
    public static function setPrefix(string $prefix): void
    {
        self::$prefix = $prefix;
    }

    /**
     * @throws StorageException
     *
     * @deprecated use replacement method wipeStorage from Adapter interface
     */
    public function flushRedis(): void
    {
        $this->wipeStorage();
    }

    /**
     * {@inheritDoc}
     */
    public function wipeStorage(): void
    {
        $this->redis->ensureOpenConnection();

        $searchPattern = '';

        $globalPrefix = $this->redis->getOption(Redis::OPT_PREFIX);
        if (is_string($globalPrefix)) {
            $searchPattern .= $globalPrefix;
        }

        $searchPattern .= self::$prefix;
        $searchPattern .= '*';

        $this->redis->eval(
            <<<'LUA'
redis.replicate_commands()
local cursor = "0"
repeat
    local results = redis.call('SCAN', cursor, 'MATCH', ARGV[1])
    cursor = results[1]
    for _, key in ipairs(results[2]) do
        redis.call('DEL', key)
    end
until cursor == "0"
LUA
            ,
            [$searchPattern],
            0
        );
    }

    /**
     * @return MetricFamilySamples[]
     *
     * @throws StorageException
     */
    public function collect(bool $sortMetrics = true): array
    {
        $this->redis->ensureOpenConnection();
        $metrics = $this->collectHistograms();
        $metrics = array_merge($metrics, $this->collectGauges($sortMetrics));
        $metrics = array_merge($metrics, $this->collectCounters($sortMetrics));
        $metrics = array_merge($metrics, $this->collectSummaries());

        return array_map(
            function (array $metric): MetricFamilySamples {
                return new MetricFamilySamples($metric);
            },
            $metrics
        );
    }

    /**
     * @param mixed[] $data
     *
     * @throws StorageException
     */
    public function updateHistogram(array $data): void
    {
        $this->redis->ensureOpenConnection();
        $bucketToIncrease = '+Inf';
        foreach ($data['buckets'] as $bucket) {
            if ($data['value'] <= $bucket) {
                $bucketToIncrease = $bucket;
                break;
            }
        }
        $metaData = $data;
        unset($metaData['value'], $metaData['labelValues']);

        $this->redis->eval(
            <<<'LUA'
local result = redis.call('hIncrByFloat', KEYS[1], ARGV[1], ARGV[3])
redis.call('hIncrBy', KEYS[1], ARGV[2], 1)
if tonumber(result) >= tonumber(ARGV[3]) then
    redis.call('hSet', KEYS[1], '__meta', ARGV[4])
    redis.call('sAdd', KEYS[2], KEYS[1])
end
return result
LUA
            ,
            [
                $this->toMetricKey($data),
                self::$prefix . Histogram::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX,
                json_encode(['b' => 'sum', 'labelValues' => $data['labelValues']]),
                json_encode(['b' => $bucketToIncrease, 'labelValues' => $data['labelValues']]),
                $data['value'],
                json_encode($metaData),
            ],
            2
        );
    }

    /**
     * @param mixed[] $data
     *
     * @throws StorageException
     */
    public function updateSummary(array $data): void
    {
        $this->redis->ensureOpenConnection();

        // store meta
        $summaryKey = self::$prefix . Summary::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX;
        $metaKey    = $summaryKey . ':' . $this->metaKey($data);
        $json       = json_encode($this->metaData($data));
        if ($json === false) {
            throw new RuntimeException(json_last_error_msg());
        }
        $this->redis->setNx($metaKey, $json);

        // store value key
        $valueKey = $summaryKey . ':' . $this->valueKey($data);
        $json     = json_encode($this->encodeLabelValues($data['labelValues']));
        if ($json === false) {
            throw new RuntimeException(json_last_error_msg());
        }
        $this->redis->setNx($valueKey, $json);

        // trick to handle uniqid collision
        $done = false;
        while (! $done) {
            $sampleKey = $valueKey . ':' . uniqid('', true);
            $done      = $this->redis->set($sampleKey, $data['value'], ['NX', 'EX' => $data['maxAgeSeconds']]);
        }
    }

    /**
     * @param mixed[] $data
     *
     * @throws StorageException
     */
    public function updateGauge(array $data): void
    {
        $this->redis->ensureOpenConnection();
        $metaData = $data;
        unset($metaData['value'], $metaData['labelValues'], $metaData['command']);
        $this->redis->eval(
            <<<'LUA'
local result = redis.call(ARGV[1], KEYS[1], ARGV[2], ARGV[3])
if ARGV[1] == 'hSet' then
    if result == 1 then
        redis.call('hSet', KEYS[1], '__meta', ARGV[4])
        redis.call('sAdd', KEYS[2], KEYS[1])
    end
else
    if result == ARGV[3] then
        redis.call('hSet', KEYS[1], '__meta', ARGV[4])
        redis.call('sAdd', KEYS[2], KEYS[1])
    end
end
LUA
            ,
            [
                $this->toMetricKey($data),
                self::$prefix . Gauge::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX,
                $this->getRedisCommand($data['command']),
                json_encode($data['labelValues']),
                $data['value'],
                json_encode($metaData),
            ],
            2
        );
    }

    /**
     * @param mixed[] $data
     *
     * @throws StorageException
     */
    public function updateCounter(array $data): void
    {
        $this->redis->ensureOpenConnection();
        $metaData = $data;
        unset($metaData['value'], $metaData['labelValues'], $metaData['command']);
        $this->redis->eval(
            <<<'LUA'
local result = redis.call(ARGV[1], KEYS[1], ARGV[3], ARGV[2])
local added = redis.call('sAdd', KEYS[2], KEYS[1])
if added == 1 then
    redis.call('hMSet', KEYS[1], '__meta', ARGV[4])
end
return result
LUA
            ,
            [
                $this->toMetricKey($data),
                self::$prefix . Counter::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX,
                $this->getRedisCommand($data['command']),
                $data['value'],
                json_encode($data['labelValues']),
                json_encode($metaData),
            ],
            2
        );
    }

    /**
     * @param mixed[] $data
     */
    protected function metaKey(array $data): string
    {
        return implode(':', [
            $data['name'],
            'meta',
        ]);
    }

    /**
     * @param mixed[] $data
     */
    protected function valueKey(array $data): string
    {
        return implode(':', [
            $data['name'],
            $this->encodeLabelValues($data['labelValues']),
            'value',
        ]);
    }

    /**
     * @param mixed[] $data
     * @return mixed[]
     */
    protected function metaData(array $data): array
    {
        $metricsMetaData = $data;
        unset($metricsMetaData['value'], $metricsMetaData['command'], $metricsMetaData['labelValues']);

        return $metricsMetaData;
    }

    /**
     * @return mixed[]
     */
    protected function collectHistograms(): array
    {
        $keys = $this->redis->sMembers(self::$prefix . Histogram::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX);
        sort($keys);
        $histograms = [];
        foreach ($keys as $key) {
            $raw = $this->redis->hGetAll(ltrim($key, $this->redis->getOption(Redis::OPT_PREFIX) ?? ''));
            if (! isset($raw['__meta'])) {
                continue;
            }
            $histogram = json_decode($raw['__meta'], true);
            unset($raw['__meta']);
            $histogram['samples'] = [];

            // Add the Inf bucket so we can compute it later on
            $histogram['buckets'][] = '+Inf';

            $allLabelValues = [];
            foreach (array_keys($raw) as $k) {
                $d = json_decode($k, true);
                if ($d['b'] == 'sum') {
                    continue;
                }
                $allLabelValues[] = $d['labelValues'];
            }
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->throwMetricJsonException($key);
            }

            // We need set semantics.
            // This is the equivalent of array_unique but for arrays of arrays.
            $allLabelValues = array_map('unserialize', array_unique(array_map('serialize', $allLabelValues)));
            sort($allLabelValues);

            foreach ($allLabelValues as $labelValues) {
                // Fill up all buckets.
                // If the bucket doesn't exist fill in values from
                // the previous one.
                $acc = 0;
                foreach ($histogram['buckets'] as $bucket) {
                    $bucketKey = json_encode(['b' => $bucket, 'labelValues' => $labelValues]);
                    if (! isset($raw[$bucketKey])) {
                        $histogram['samples'][] = [
                            'name'        => $histogram['name'] . '_bucket',
                            'labelNames'  => ['le'],
                            'labelValues' => array_merge($labelValues, [$bucket]),
                            'value'       => $acc,
                        ];
                    } else {
                        $acc += $raw[$bucketKey];
                        $histogram['samples'][] = [
                            'name'        => $histogram['name'] . '_bucket',
                            'labelNames'  => ['le'],
                            'labelValues' => array_merge($labelValues, [$bucket]),
                            'value'       => $acc,
                        ];
                    }
                }

                // Add the count
                $histogram['samples'][] = [
                    'name'        => $histogram['name'] . '_count',
                    'labelNames'  => [],
                    'labelValues' => $labelValues,
                    'value'       => $acc,
                ];

                // Add the sum
                $histogram['samples'][] = [
                    'name'        => $histogram['name'] . '_sum',
                    'labelNames'  => [],
                    'labelValues' => $labelValues,
                    'value'       => $raw[json_encode(['b' => 'sum', 'labelValues' => $labelValues])],
                ];
            }
            $histograms[] = $histogram;
        }

        return $histograms;
    }

    protected function removePrefixFromKey(string $key): string
    {
        if ($this->redis->getOption(Redis::OPT_PREFIX) === null) {
            return $key;
        }

        return substr($key, strlen($this->redis->getOption(Redis::OPT_PREFIX)));
    }

    /**
     * @return mixed[]
     */
    protected function collectSummaries(): array
    {
        $math       = new Math();
        $summaryKey = self::$prefix . Summary::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX;
        $keys       = $this->redis->keys($summaryKey . ':*:meta');

        $summaries = [];
        foreach ($keys as $metaKeyWithPrefix) {
            $metaKey    = $this->removePrefixFromKey($metaKeyWithPrefix);
            $rawSummary = $this->redis->get($metaKey);
            if ($rawSummary === false) {
                continue;
            }
            $summary  = json_decode($rawSummary, true);
            $metaData = $summary;
            $data     = [
                'name'          => $metaData['name'],
                'help'          => $metaData['help'],
                'type'          => $metaData['type'],
                'labelNames'    => $metaData['labelNames'],
                'maxAgeSeconds' => $metaData['maxAgeSeconds'],
                'quantiles'     => $metaData['quantiles'],
                'samples'       => [],
            ];

            $values = $this->redis->keys($summaryKey . ':' . $metaData['name'] . ':*:value');
            foreach ($values as $valueKeyWithPrefix) {
                $valueKey = $this->removePrefixFromKey($valueKeyWithPrefix);
                $rawValue = $this->redis->get($valueKey);
                if ($rawValue === false) {
                    continue;
                }
                $value              = json_decode($rawValue, true);
                $encodedLabelValues = $value;
                $decodedLabelValues = $this->decodeLabelValues($encodedLabelValues);

                $samples      = [];
                $sampleValues = $this->redis->keys($summaryKey . ':' . $metaData['name'] . ':' . $encodedLabelValues . ':value:*');
                foreach ($sampleValues as $sampleValueWithPrefix) {
                    $sampleValue = $this->removePrefixFromKey($sampleValueWithPrefix);
                    $samples[]   = (float) $this->redis->get($sampleValue);
                }

                if (count($samples) === 0) {
                    try {
                        $this->redis->del($valueKey);
                    } catch (RedisClientException $e) {
                        // ignore if we can't delete the key
                    }

                    continue;
                }

                // Compute quantiles
                sort($samples);
                foreach ($data['quantiles'] as $quantile) {
                    $data['samples'][] = [
                        'name'        => $metaData['name'],
                        'labelNames'  => ['quantile'],
                        'labelValues' => array_merge($decodedLabelValues, [$quantile]),
                        'value'       => $math->quantile($samples, $quantile),
                    ];
                }

                // Add the count
                $data['samples'][] = [
                    'name'        => $metaData['name'] . '_count',
                    'labelNames'  => [],
                    'labelValues' => $decodedLabelValues,
                    'value'       => count($samples),
                ];

                // Add the sum
                $data['samples'][] = [
                    'name'        => $metaData['name'] . '_sum',
                    'labelNames'  => [],
                    'labelValues' => $decodedLabelValues,
                    'value'       => array_sum($samples),
                ];
            }

            if (count($data['samples']) > 0) {
                $summaries[] = $data;
            } else {
                try {
                    $this->redis->del($metaKey);
                } catch (RedisClientException $e) {
                    // ignore if we can't delete the key
                }
            }
        }

        return $summaries;
    }

    /**
     * @return mixed[]
     */
    protected function collectGauges(bool $sortMetrics = true): array
    {
        $keys = $this->redis->sMembers(self::$prefix . Gauge::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX);
        sort($keys);
        $gauges = [];
        foreach ($keys as $key) {
            $raw = $this->redis->hGetAll(ltrim($key, $this->redis->getOption(Redis::OPT_PREFIX) ?? ''));
            if (! isset($raw['__meta'])) {
                continue;
            }
            $gauge = json_decode($raw['__meta'], true);
            unset($raw['__meta']);
            $gauge['samples'] = [];
            foreach ($raw as $k => $value) {
                $gauge['samples'][] = [
                    'name'        => $gauge['name'],
                    'labelNames'  => [],
                    'labelValues' => json_decode($k, true),
                    'value'       => $value,
                ];
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->throwMetricJsonException($key, $gauge['name']);
                }
            }

            if ($sortMetrics) {
                usort($gauge['samples'], function ($a, $b): int {
                    return strcmp(implode('', $a['labelValues']), implode('', $b['labelValues']));
                });
            }

            $gauges[] = $gauge;
        }

        return $gauges;
    }

    /**
     * @return mixed[]
     *
     * @throws MetricJsonException
     */
    protected function collectCounters(bool $sortMetrics = true): array
    {
        $keys = $this->redis->sMembers(self::$prefix . Counter::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX);
        sort($keys);
        $counters = [];
        foreach ($keys as $key) {
            $raw = $this->redis->hGetAll(ltrim($key, $this->redis->getOption(Redis::OPT_PREFIX) ?? ''));
            if (! isset($raw['__meta'])) {
                continue;
            }
            $counter = json_decode($raw['__meta'], true);

            unset($raw['__meta']);
            $counter['samples'] = [];
            foreach ($raw as $k => $value) {
                $counter['samples'][] = [
                    'name'        => $counter['name'],
                    'labelNames'  => [],
                    'labelValues' => json_decode($k, true),
                    'value'       => $value,
                ];

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->throwMetricJsonException($key, $counter['name']);
                }
            }

            if ($sortMetrics) {
                usort($counter['samples'], function ($a, $b): int {
                    return strcmp(implode('', $a['labelValues']), implode('', $b['labelValues']));
                });
            }

            $counters[] = $counter;
        }

        return $counters;
    }

    protected function getRedisCommand(int $cmd): string
    {
        switch ($cmd) {
            case Adapter::COMMAND_INCREMENT_INTEGER:
                return 'hIncrBy';
            case Adapter::COMMAND_INCREMENT_FLOAT:
                return 'hIncrByFloat';
            case Adapter::COMMAND_SET:
                return 'hSet';
            default:
                throw new InvalidArgumentException('Unknown command');
        }
    }

    /**
     * @param mixed[] $data
     */
    protected function toMetricKey(array $data): string
    {
        return implode(':', [self::$prefix, $data['type'], $data['name']]);
    }

    /**
     * @param mixed[] $values
     *
     * @throws RuntimeException
     */
    protected function encodeLabelValues(array $values): string
    {
        $json = json_encode($values);
        if ($json === false) {
            throw new RuntimeException(json_last_error_msg());
        }

        return base64_encode($json);
    }

    /**
     * @return mixed[]
     *
     * @throws RuntimeException
     */
    protected function decodeLabelValues(string $values): array
    {
        $json = base64_decode($values, true);
        if ($json === false) {
            throw new RuntimeException('Cannot base64 decode label values');
        }
        $decodedValues = json_decode($json, true);
        if ($decodedValues === false) {
            throw new RuntimeException(json_last_error_msg());
        }

        return $decodedValues;
    }

    /**
     * @throws MetricJsonException
     */
    protected function throwMetricJsonException(string $redisKey, ?string $metricName = null): void
    {
        $metricName = $metricName ?? 'unknown';
        $message    = 'Json error: ' . json_last_error_msg() . ' redis key : ' . $redisKey . ' metric name: ' . $metricName;
        throw new MetricJsonException($message, 0, null, $metricName);
    }
}
