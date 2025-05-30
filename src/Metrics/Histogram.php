<?php

namespace Apvalkov\LaravelPrometheus\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\Exception\MetricsRegistrationException;
use Prometheus\Histogram as PrometheusHistogram;

class Histogram extends Metric
{
    protected array $buckets = [];

    /**
     * @param CollectorRegistry $registry
     * @param string            $namespace
     * @param string            $name
     * @param string            $help
     * @param array             $labels
     * @param array             $buckets
     */
    public function __construct(CollectorRegistry $registry, string $namespace, string $name, string $help, array $labels, array $buckets)
    {
        parent::__construct($registry, $namespace, $name, $help, $labels);

        $this->buckets = $buckets;
    }

    /**
     * @param float $value
     * @param array $labels
     *
     * @return void
     * @throws MetricsRegistrationException
     */
    public function observe(float $value, array $labels = []): void
    {
        $this->histogram()->observe($value, $labels);
    }

    /**
     * @return PrometheusHistogram
     * @throws MetricsRegistrationException
     */
    private function histogram(): PrometheusHistogram
    {
        return $this->registry->getOrRegisterHistogram($this->namespace, $this->name, $this->help, $this->labels, $this->buckets);
    }
}
