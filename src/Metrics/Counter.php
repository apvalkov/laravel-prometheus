<?php

namespace Apvalkov\LaravelPrometheus\Metrics;

use Prometheus\Exception\MetricsRegistrationException;
use Prometheus\Counter as PrometheusCounter;

class Counter extends Metric
{
    /**
     * @param array $labels
     *
     * @return void
     * @throws MetricsRegistrationException
     */
    public function inc(array $labels = []): void
    {
        $this->incBy(1, $labels);
    }

    /**
     * @param float $value
     * @param array $labels
     *
     * @return void
     * @throws MetricsRegistrationException
     */
    public function incBy(float $value, array $labels = []): void
    {
        $this->counter()->incBy($value, $labels);
    }

    /**
     * @return PrometheusCounter
     * @throws MetricsRegistrationException
     */
    private function counter(): PrometheusCounter
    {
        return $this->registry->getOrRegisterCounter($this->namespace, $this->name, $this->help, $this->labels);
    }
}
