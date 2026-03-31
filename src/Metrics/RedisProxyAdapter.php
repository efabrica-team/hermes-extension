<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Metrics;

use InvalidArgumentException;
use Prometheus\Counter;
use Prometheus\Exception\MetricJsonException;
use Prometheus\Gauge;
use Prometheus\Histogram;
use Prometheus\Math;
use Prometheus\MetricFamilySamples;
use Prometheus\Storage\Adapter;
use Prometheus\Summary;
use RedisProxy\RedisProxy;
use RedisProxy\RedisProxyException;
use RuntimeException;
use function array_fill_keys;
use function array_keys;
use function array_map;
use function array_merge;
use function array_sum;
use function array_unique;
use function base64_decode;
use function base64_encode;
use function count;
use function implode;
use function json_decode;
use function json_encode;
use function json_last_error;
use function json_last_error_msg;
use function sort;
use function strcmp;
use function uniqid;
use function usort;

final class RedisProxyAdapter implements Adapter
{
    const PROMETHEUS_METRIC_KEYS_SUFFIX = '_METRIC_KEYS';

    /**
     * @var string
     */
    private static $prefix = 'PROMETHEUS_';

    private ?int $ttl = null;

    /**
     * @var RedisProxy
     */
    private $redisProxy;

    public function __construct(RedisProxy $redisProxy, ?int $ttl = null)
    {
        $this->redisProxy = $redisProxy;
        $this->ttl = $ttl;
    }

    public static function setPrefix(string $prefix): void
    {
        self::$prefix = $prefix;
    }

    /**
     * @inheritDoc
     * @throws RedisProxyException
     * @throws MetricJsonException
     */
    public function collect(): array
    {
        $metrics = $this->collectHistograms();
        $metrics = array_merge($metrics, $this->collectGauges());
        $metrics = array_merge($metrics, $this->collectCounters());
        $metrics = array_merge($metrics, $this->collectSummaries());
        return array_map(
            function (array $metric): MetricFamilySamples {
                return new MetricFamilySamples($metric);
            },
            $metrics
        );
    }

    /**
     * @inheritDoc
     * @throws RedisProxyException
     */
    public function updateSummary(array $data): void
    {
        $summaryKey = self::$prefix . Summary::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX;

        $metaKey = $summaryKey . ':' . $this->metaKey($data);
        $json = json_encode($this->metaData($data));
        if ($json === false) {
            throw new RuntimeException(json_last_error_msg());
        }
        $this->redisProxy->setnx($metaKey, $json);

        $valueKey = $summaryKey . ':' . $this->valueKey($data);
        $json = json_encode($this->encodeLabelValues($data['labelValues']));
        if ($json === false) {
            throw new RuntimeException(json_last_error_msg());
        }
        $this->redisProxy->setnx($valueKey, $json);

        $done = false;
        while (!$done) {
            $sampleKey = $valueKey . ':' . uniqid('', true);
            $done = $this->redisProxy->rawCommand(
                'SET',
                $sampleKey,
                $data['value'],
                'NX',
                'EX',
                $data['maxAgeSeconds']
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function updateHistogram(array $data): void
    {
        $bucketToIncrease = '+Inf';
        foreach ($data['buckets'] as $bucket) {
            if ($data['value'] <= $bucket) {
                $bucketToIncrease = $bucket;
                break;
            }
        }
        $metaData = $data;
        unset($metaData['value'], $metaData['labelValues']);

        $this->redisProxy->rawCommand(
            'EVAL',
            <<<LUA
local result = redis.call('hIncrByFloat', KEYS[1], ARGV[1], ARGV[3])
redis.call('hIncrBy', KEYS[1], ARGV[2], 1)
local ttl = tonumber(ARGV[5])
if ttl > 0 then
    redis.call('expire', KEYS[1], ttl)
end
if tonumber(result) >= tonumber(ARGV[3]) then
    redis.call('hSet', KEYS[1], '__meta', ARGV[4])
    redis.call('sAdd', KEYS[2], KEYS[1])
    if ttl > 0 then
        redis.call('sAdd', KEYS[3], KEYS[1])
    end
end
return result
LUA,
            3,
            $this->toMetricKey($data),
            self::$prefix . Histogram::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX,
            self::$prefix . Histogram::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX . ':volatile',
            json_encode(['b' => 'sum', 'labelValues' => $data['labelValues']]),
            json_encode(['b' => $bucketToIncrease, 'labelValues' => $data['labelValues']]),
            $data['value'],
            json_encode($metaData),
            $this->ttl === null ? 0 : $this->ttl,
        );
    }

    /**
     * @inheritDoc
     */
    public function updateGauge(array $data): void
    {
        $metaData = $data;
        unset($metaData['value'], $metaData['labelValues'], $metaData['command']);
        $this->redisProxy->rawCommand(
            'EVAL',
            <<<LUA
local ttl = tonumber(ARGV[5])
local result = redis.call(ARGV[1], KEYS[1], ARGV[2], ARGV[3])
if ttl > 0 then
    redis.call('hExpire', KEYS[1], ttl, 'FIELDS', 1, ARGV[2])
end

if ARGV[1] == 'hSet' then
    if result == 1 then
        redis.call('hSet', KEYS[1], '__meta', ARGV[4])
        redis.call('sAdd', KEYS[2], KEYS[1])
        if ttl > 0 then
            redis.call('sAdd', KEYS[3], KEYS[1])
        end
    end
else
    if tonumber(result) == tonumber(ARGV[3]) then
        redis.call('hSet', KEYS[1], '__meta', ARGV[4])
        redis.call('sAdd', KEYS[2], KEYS[1])
        if ttl > 0 then
            redis.call('sAdd', KEYS[3], KEYS[1])
        end
    end
end
LUA,
            3,
            $this->toMetricKey($data),
            self::$prefix . Gauge::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX,
            self::$prefix . Gauge::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX . ':volatile',
            $this->getRedisCommand($data['command']),
            json_encode($data['labelValues']),
            $data['value'],
            json_encode($metaData),
            $this->ttl === null ? 0 : $this->ttl,
        );
    }

    /**
     * @inheritDoc
     */
    public function updateCounter(array $data): void
    {
        $metaData = $data;
        unset($metaData['value'], $metaData['labelValues'], $metaData['command']);
        $this->redisProxy->rawCommand(
            'EVAL',
            <<<LUA
local ttl = tonumber(ARGV[5])
local result = redis.call(ARGV[1], KEYS[1], ARGV[3], ARGV[2])
if ttl > 0 then
    redis.call('hExpire', KEYS[1], ttl, 'FIELDS', 1, ARGV[3])
    redis.call('sAdd', KEYS[3], KEYS[1])
end
local added = redis.call('sAdd', KEYS[2], KEYS[1])
if added == 1 then
    redis.call('hSet', KEYS[1], '__meta', ARGV[4])
end
return result
LUA,
            3,
            $this->toMetricKey($data),
            self::$prefix . Counter::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX,
            self::$prefix . Counter::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX . ':volatile',
            $this->getRedisCommand($data['command']),
            $data['value'],
            json_encode($data['labelValues']),
            json_encode($metaData),
            $this->ttl === null ? 0 : $this->ttl,
        );
    }

    /**
     * @inheritDoc
     */
    public function wipeStorage(): void
    {
        $searchPattern = self::$prefix . '*';

        $this->redisProxy->rawCommand(
            'EVAL',
            <<<LUA
--!df flags=allow-undeclared-keys
redis.replicate_commands()
local cursor = "0"
repeat
    local results = redis.call('SCAN', cursor, 'MATCH', ARGV[1])
    cursor = results[1]
    for _, key in ipairs(results[2]) do
        redis.call('DEL', key)
    end
until cursor == "0"
LUA,
            0,
            $searchPattern
        );
    }

    /**
     * @return array<int, mixed>
     * @throws MetricJsonException
     */
    private function collectHistograms(): array
    {
        $keys = $this->redisProxy->smembers(self::$prefix . Histogram::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX);
        $volatileKeys = array_fill_keys(
            $this->redisProxy->smembers(
                self::$prefix . Histogram::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX . ':volatile'
            ),
            true
        );
        sort($keys);
        $histograms = [];
        foreach ($keys as $key) {
            $raw = $this->redisProxy->hgetall($key);
            if (!isset($raw['__meta'])) {
                if (isset($volatileKeys[$key])) {
                    $this->redisProxy->rawCommand(
                        'EVAL',
                        <<<LUA
local result = redis.call('hLen', KEYS[1])
local metaExists = redis.call('hExists', KEYS[1], '__meta')
if (tonumber(result) == 1 and metaExists == 1) or tonumber(result) == 0 then
    redis.pcall('sRem', KEYS[2], KEYS[1])
    redis.pcall('sRem', KEYS[3], KEYS[1])
    redis.pcall('del', KEYS[1])
end
LUA,
                        3,
                        $key,
                        self::$prefix . Histogram::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX,
                        self::$prefix . Histogram::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX . ':volatile',
                    );
                }
                continue;
            }
            $histogram = json_decode($raw['__meta'], true);
            unset($raw['__meta']);
            $histogram['samples'] = [];

            $histogram['buckets'][] = '+Inf';

            $allLabelValues = [];
            foreach (array_keys($raw) as $arrayKey) {
                $decoded = json_decode($arrayKey, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->throwMetricJsonException($key);
                }
                if ($decoded['b'] == 'sum') {
                    continue;
                }
                $allLabelValues[] = $decoded['labelValues'];
            }

            // We need set semantics.
            // This is the equivalent of array_unique but for arrays of arrays.
            $allLabelValues = array_map('unserialize', array_unique(array_map('serialize', $allLabelValues)));
            sort($allLabelValues);

            foreach ($allLabelValues as $labelValues) {
                // Fill up all buckets.
                // If the bucket doesn't exist fill in values from
                // the previous one.
                $acc = 0;
                foreach ($histogram['buckets'] as $bucket) {
                    $bucketKey = json_encode(['b' => $bucket, 'labelValues' => $labelValues]);
                    if (!isset($raw[$bucketKey])) {
                        $histogram['samples'][] = [
                            'name' => $histogram['name'] . '_bucket',
                            'labelNames' => ['le'],
                            'labelValues' => array_merge($labelValues, [$bucket]),
                            'value' => $acc,
                        ];
                    } else {
                        $acc += $raw[$bucketKey];
                        $histogram['samples'][] = [
                            'name' => $histogram['name'] . '_bucket',
                            'labelNames' => ['le'],
                            'labelValues' => array_merge($labelValues, [$bucket]),
                            'value' => $acc,
                        ];
                    }
                }

                $histogram['samples'][] = [
                    'name' => $histogram['name'] . '_count',
                    'labelNames' => [],
                    'labelValues' => $labelValues,
                    'value' => $acc,
                ];

                $histogram['samples'][] = [
                    'name' => $histogram['name'] . '_sum',
                    'labelNames' => [],
                    'labelValues' => $labelValues,
                    'value' => $raw[json_encode(['b' => 'sum', 'labelValues' => $labelValues])],
                ];
            }
            $histograms[] = $histogram;
        }
        return $histograms;
    }

    /**
     * @return array<int, mixed>
     * @throws RedisProxyException
     */
    private function collectSummaries(): array
    {
        $math = new Math();
        $summaryKey = self::$prefix . Summary::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX;
        $keys = $this->redisProxy->keys($summaryKey . ':*:meta');

        $summaries = [];
        foreach ($keys as $metaKey) {
            $rawSummary = $this->redisProxy->get($metaKey);
            if ($rawSummary === null) {
                continue;
            }
            $summary = json_decode($rawSummary, true);
            $metaData = $summary;
            $data = [
                'name' => $metaData['name'],
                'help' => $metaData['help'],
                'type' => $metaData['type'],
                'labelNames' => $metaData['labelNames'],
                'maxAgeSeconds' => $metaData['maxAgeSeconds'],
                'quantiles' => $metaData['quantiles'],
                'samples' => [],
            ];

            $values = $this->redisProxy->keys($summaryKey . ':' . $metaData['name'] . ':*:value');
            foreach ($values as $valueKey) {
                $rawValue = $this->redisProxy->get($valueKey);
                if ($rawValue === null) {
                    continue;
                }
                $value = json_decode($rawValue, true);
                $encodedLabelValues = $value;
                $decodedLabelValues = $this->decodeLabelValues($encodedLabelValues);

                $samples = [];
                $sampleValues = $this->redisProxy->keys($summaryKey . ':' . $metaData['name'] . ':' . $encodedLabelValues . ':value:*');
                foreach ($sampleValues as $sampleValueKey) {
                    $samples[] = (float) $this->redisProxy->get($sampleValueKey);
                }

                if (count($samples) === 0) {
                    try {
                        $this->redisProxy->del($valueKey);
                    } catch (RedisProxyException $e) {
                        // Do nothing.
                    }

                    continue;
                }

                sort($samples);
                foreach ($data['quantiles'] as $quantile) {
                    $data['samples'][] = [
                        'name' => $metaData['name'],
                        'labelNames' => ['quantile'],
                        'labelValues' => array_merge($decodedLabelValues, [$quantile]),
                        'value' => $math->quantile($samples, $quantile),
                    ];
                }

                $data['samples'][] = [
                    'name' => $metaData['name'] . '_count',
                    'labelNames' => [],
                    'labelValues' => $decodedLabelValues,
                    'value' => count($samples),
                ];

                $data['samples'][] = [
                    'name' => $metaData['name'] . '_sum',
                    'labelNames' => [],
                    'labelValues' => $decodedLabelValues,
                    'value' => array_sum($samples),
                ];
            }
            if (count($data['samples']) > 0) {
                $summaries[] = $data;
            } else {
                try {
                    $this->redisProxy->del($metaKey);
                } catch (RedisProxyException $e) {
                    // Do nothing.
                }
            }
        }
        return $summaries;
    }

    /**
     * @return array<int, mixed>
     * @throws MetricJsonException
     */
    private function collectCounters(bool $sortMetrics = true): array
    {
        $keys = $this->redisProxy->smembers(self::$prefix . Counter::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX);
        $volatileKeys = array_fill_keys(
            $this->redisProxy->smembers(
                self::$prefix . Counter::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX . ':volatile'
            ),
            true
        );
        sort($keys);
        $counters = [];
        foreach ($keys as $key) {
            $raw = $this->redisProxy->hgetall($key);
            if (!isset($raw['__meta']) || count($raw) === 1) {
                if (isset($volatileKeys[$key])) {
                    $this->redisProxy->rawCommand(
                        'EVAL',
                        <<<LUA
local result = redis.call('hLen', KEYS[1])
local metaExists = redis.call('hExists', KEYS[1], '__meta')
if (tonumber(result) == 1 and metaExists == 1) or tonumber(result) == 0 then
    redis.pcall('sRem', KEYS[2], KEYS[1])
    redis.pcall('sRem', KEYS[3], KEYS[1])
    redis.pcall('del', KEYS[1])
end
LUA,
                        3,
                        $key,
                        self::$prefix . Counter::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX,
                        self::$prefix . Counter::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX . ':volatile',
                    );
                }
                continue;
            }
            $counter = json_decode($raw['__meta'], true);
            unset($raw['__meta']);
            $counter['samples'] = [];
            foreach ($raw as $k => $value) {
                $counter['samples'][] = [
                    'name' => $counter['name'],
                    'labelNames' => [],
                    'labelValues' => json_decode($k, true),
                    'value' => $value,
                ];
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->throwMetricJsonException($key, $counter['name']);
                }
            }

            if ($sortMetrics) {
                usort($counter['samples'], function ($a, $b): int {
                    return strcmp(implode('', $a['labelValues']), implode('', $b['labelValues']));
                });
            }

            $counters[] = $counter;
        }
        return $counters;
    }

    /**
     * @return array<int, mixed>
     * @throws MetricJsonException
     */
    private function collectGauges(bool $sortMetrics = true): array
    {
        $keys = $this->redisProxy->smembers(self::$prefix . Gauge::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX);
        $volatileKeys = array_fill_keys(
            $this->redisProxy->smembers(
                self::$prefix . Gauge::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX . ':volatile'
            ),
            true
        );
        sort($keys);
        $gauges = [];
        foreach ($keys as $key) {
            $raw = $this->redisProxy->hgetall($key);
            if (!isset($raw['__meta']) || count($raw) === 1) {
                if (isset($volatileKeys[$key])) {
                    $this->redisProxy->rawCommand(
                        'EVAL',
                        <<<LUA
local result = redis.call('hLen', KEYS[1])
local metaExists = redis.call('hExists', KEYS[1], '__meta')
if (tonumber(result) == 1 and metaExists == 1) or tonumber(result) == 0 then
    redis.pcall('sRem', KEYS[2], KEYS[1])
    redis.pcall('sRem', KEYS[3], KEYS[1])
    redis.pcall('del', KEYS[1])
end
LUA,
                        3,
                        $key,
                        self::$prefix . Gauge::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX,
                        self::$prefix . Gauge::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX . ':volatile',
                    );
                }
                continue;
            }
            $gauge = json_decode($raw['__meta'], true);
            unset($raw['__meta']);
            $gauge['samples'] = [];
            foreach ($raw as $k => $value) {
                $gauge['samples'][] = [
                    'name' => $gauge['name'],
                    'labelNames' => [],
                    'labelValues' => json_decode($k, true),
                    'value' => $value,
                ];
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->throwMetricJsonException($key, $gauge['name']);
                }
            }

            if ($sortMetrics) {
                usort($gauge['samples'], function ($a, $b): int {
                    return strcmp(implode('', $a['labelValues']), implode('', $b['labelValues']));
                });
            }

            $gauges[] = $gauge;
        }
        return $gauges;
    }

    /**
     * @param array<string, mixed> $data
     * @return string
     */
    private function toMetricKey(array $data): string
    {
        return implode(':', [self::$prefix, $data['type'], $data['name']]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return string
     */
    private function metaKey(array $data): string
    {
        return implode(':', [
            $data['name'],
            'meta',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return string
     */
    private function valueKey(array $data): string
    {
        return implode(':', [
            $data['name'],
            $this->encodeLabelValues($data['labelValues']),
            'value',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function metaData(array $data): array
    {
        $metricsMetaData = $data;
        unset($metricsMetaData['value'], $metricsMetaData['command'], $metricsMetaData['labelValues']);
        return $metricsMetaData;
    }

    /**
     * @param array<string, mixed> $values
     * @return string
     * @throws RuntimeException
     */
    private function encodeLabelValues(array $values): string
    {
        $json = json_encode($values);
        if (false === $json) {
            throw new RuntimeException(json_last_error_msg());
        }
        return base64_encode($json);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    protected function decodeLabelValues(string $values): array
    {
        $json = base64_decode($values, true);
        if ($json === false) {
            throw new RuntimeException('Cannot base64 decode label values');
        }
        $decodedValues = json_decode($json, true);
        if ($decodedValues === false) {
            throw new RuntimeException(json_last_error_msg());
        }

        return $decodedValues;
    }

    private function getRedisCommand(int $cmd): string
    {
        switch ($cmd) {
            case Adapter::COMMAND_INCREMENT_INTEGER:
                return 'hIncrBy';
            case Adapter::COMMAND_INCREMENT_FLOAT:
                return 'hIncrByFloat';
            case Adapter::COMMAND_SET:
                return 'hSet';
            default:
                throw new InvalidArgumentException('Unknown command');
        }
    }

    /**
     * @param string $redisKey
     * @param string|null $metricName
     * @return void
     * @throws MetricJsonException
     */
    private function throwMetricJsonException(string $redisKey, ?string $metricName = null): void
    {
        $metricName = $metricName ?? 'unknown';
        $message = 'Json error: ' . json_last_error_msg() . ' redis key : ' . $redisKey . ' metric name: ' . $metricName;
        throw new MetricJsonException($message, 0, null, $metricName);
    }
}
