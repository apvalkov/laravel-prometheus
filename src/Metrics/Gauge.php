<?php

namespace Apvalkov\LaravelPrometheus\Metrics;

use Prometheus\Exception\MetricsRegistrationException;
use Prometheus\Gauge as PrometheusGauge;

class Gauge extends Metric
{
    /**
     * @param float $value
     * @param array $labels
     *
     * @return void
     * @throws MetricsRegistrationException
     */
    public function set(float $value, array $labels = []): void
    {
        $this->gauge()->set($value, $labels);
    }

    /**
     * @return PrometheusGauge
     * @throws MetricsRegistrationException
     */
    private function gauge(): PrometheusGauge
    {
        return $this->registry->getOrRegisterGauge($this->namespace, $this->name, $this->help, $this->labels);
    }
}
