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
        $this->mergeConfigFrom(
            __DIR__.'/../config/prometheus.php',
            'prometheus'
        );

        $this->app->singleton(Predis::class, function () {
            return Predis::fromExistingConnection($this->app->get('redis')->client());
        });

        $this->app->singleton(Adapter::class, function ($app) {
            return (new StorageAdapterManager($app))->driver();
        });

        $this->app->scoped(CollectorRegistry::class, function () {
            return new CollectorRegistry(
                $this->app->get(Adapter::class),
                false
            );
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/prometheus.php' => config_path('prometheus.php'),
        ], 'config');
    }
}
