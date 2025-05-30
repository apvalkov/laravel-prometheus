<?php

namespace Apvalkov\LaravelPrometheus;

use Apvalkov\LaravelPrometheus\Adapters\Predis;
use Illuminate\Support\ServiceProvider;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\Adapter;

class PrometheusServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register(): void
    {
        $this->app->scoped(CollectorRegistry::class, function () {
            return new CollectorRegistry(
                $this->buildStorageAdapter(),
                false
            );
        });
    }

    /**
     * @return Adapter
     */
    protected function buildStorageAdapter(): Adapter
    {
        return Predis::fromExistingConnection($this->app->get('redis')->client());
    }
}
