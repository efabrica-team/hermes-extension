<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Metrics;

use Prometheus\CollectorRegistry;
use RedisProxy\RedisProxy;

final class PrometheusMetricsFactory
{
    private RedisProxy $redisProxy;

    public function __construct(RedisProxy $redisProxy)
    {
        $this->redisProxy = $redisProxy;
    }

    public function create(string $namespace, int $temporaryStatisticsTTL): PrometheusMetrics
    {
        $adapter = new RedisProxyAdapter($this->redisProxy, null);
        $adapterTemporary = new RedisProxyAdapter($this->redisProxy, $temporaryStatisticsTTL);

        return new PrometheusMetrics(
            new CollectorRegistry($adapter, false),
            new CollectorRegistry($adapterTemporary, false),
            $namespace,
        );
    }
}
