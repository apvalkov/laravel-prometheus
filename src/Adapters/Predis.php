<?php

namespace Apvalkov\LaravelPrometheus\Adapters;

use InvalidArgumentException;
use Predis\Client;
use Apvalkov\LaravelPrometheus\Clients\Predis as PredisClient;

class Predis extends AbstractRedis
{
    private static array $defaultParameters = [
        'scheme'             => 'tcp',
        'host'               => '127.0.0.1',
        'port'               => 6379,
        'timeout'            => 0.1,
        'read_write_timeout' => 10,
        'persistent'         => false,
        'password'           => null,
        'username'           => null,
    ];
    private static array $defaultOptions = [
        'prefix'       => '',
        'throw_errors' => true,
    ];
    private array $parameters = [];
    private array $options    = [];

    /**
     * Predis constructor.
     *
     * @param mixed[] $parameters
     * @param mixed[] $options
     */
    public function __construct(array $parameters = [], array $options = [])
    {
        $this->parameters = array_merge(self::$defaultParameters, $parameters);
        $this->options    = array_merge(self::$defaultOptions, $options);
        $this->redis      = PredisClient::create($this->parameters, $this->options);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromExistingConnection(Client $client): self
    {
        $clientOptions = $client->getOptions();
        $options       = [
            'aggregate'   => $clientOptions->aggregate,
            'cluster'     => $clientOptions->cluster,
            'connections' => $clientOptions->connections,
            'exceptions'  => $clientOptions->exceptions,
            'prefix'      => $clientOptions->prefix,
            'commands'    => $clientOptions->commands,
            'replication' => $clientOptions->replication,
        ];

        $self        = new self();
        $self->redis = new PredisClient(self::$defaultParameters, $options, $client);

        return $self;
    }

    /**
     * @param mixed[] $parameters
     */
    public static function setDefaultParameters(array $parameters): void
    {
        self::$defaultParameters = array_merge(self::$defaultParameters, $parameters);
    }

    /**
     * @param mixed[] $options
     */
    public static function setDefaultOptions(array $options): void
    {
        self::$defaultOptions = array_merge(self::$defaultOptions, $options);
    }
}
