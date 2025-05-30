<?php

namespace Apvalkov\LaravelPrometheus;

use Apvalkov\LaravelPrometheus\Adapters\Predis;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Manager;
use Prometheus\Storage\InMemory;

class StorageAdapterManager extends Manager
{
    /**
     * @return string
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('prometheus.storage_adapter', 'in_memory');
    }

    /**
     * @return Predis
     * @throws BindingResolutionException
     */
    public function createPredisDriver(): Predis
    {
        return $this->container->make(Predis::class);
    }

    /**
     * @return InMemory
     */
    public function createInMemoryDriver(): InMemory
    {
        return new InMemory();
    }
}
