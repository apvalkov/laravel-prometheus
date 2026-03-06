<?php

namespace Apvalkov\LaravelPrometheus;

use Apvalkov\LaravelPrometheus\Adapters\Predis;
use Apvalkov\LaravelPrometheus\Adapters\RedisCluster;
use Apvalkov\LaravelPrometheus\Adapters\PredisCluster;
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
        return $this->config->get('prometheus.storage_driver', 'in_memory');
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
    * @return PredisCluster
    * @throws BindingResolutionException
    */
    public function createPredisClusterDriver(): PredisCluster
    {
        return $this->container->make(PredisCluster::class);
    }

    /**
     * @return RedisCluster
     * @throws BindingResolutionException
     */
    public function createRedisClusterDriver(): RedisCluster
    {
        return $this->container->make(RedisCluster::class);
    }

    /**
     * @return InMemory
     */
    public function createInMemoryDriver(): InMemory
    {
        return new InMemory();
    }
}
