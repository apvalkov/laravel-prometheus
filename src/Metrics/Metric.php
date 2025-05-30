<?php

namespace Apvalkov\LaravelPrometheus\Metrics;

use Prometheus\CollectorRegistry;

abstract class Metric
{
    protected CollectorRegistry $registry;
    protected string            $namespace;
    protected string            $name;
    protected string            $help;
    protected array             $labels;

    /**
     * @param string            $namespace
     * @param string            $name
     * @param string            $help
     * @param array             $labels
     * @param CollectorRegistry $registry
     */
    public function __construct(CollectorRegistry $registry, string $namespace, string $name, string $help, array $labels)
    {
        $this->registry  = $registry;
        $this->namespace = $namespace;
        $this->name      = $name;
        $this->help      = $help;
        $this->labels    = $labels;
    }
}
