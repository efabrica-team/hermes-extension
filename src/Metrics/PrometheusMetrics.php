<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\Counter;
use Prometheus\Exception\MetricsRegistrationException;
use Prometheus\Gauge;
use Prometheus\Histogram;
use Prometheus\MetricFamilySamples;
use Prometheus\RenderTextFormat;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Ramsey\Uuid\Uuid;
use Throwable;
use function array_filter;
use function floor;
use function getmypid;
use function hrtime;
use function memory_get_peak_usage;
use function memory_get_usage;
use function strpos;

final class PrometheusMetrics implements LoggerAwareInterface
{
    protected ?LoggerInterface $logger = null;

    protected CollectorRegistry $registry;

    protected CollectorRegistry $registryTemporary;

    protected string $namespace;

    protected int $timeStart = 0;

    protected int $processStart = 0;

    protected int $pid = 0;

    protected string $uuid;

    public function __construct(
        CollectorRegistry $registry,
        CollectorRegistry $registryTemporary,
        string $namespace
    ) {
        $this->registry = $registry;
        $this->registryTemporary = $registryTemporary;
        $this->namespace = $namespace;
        $this->pid = getmypid() === false ? 0 : getmypid();
        $this->uuid = Uuid::uuid7()->toString();
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function dataReport(bool $onlyFromNamespace = true): string
    {
        $samples = $this->registry->getMetricFamilySamples();
        if ($onlyFromNamespace) {
            $samples = array_filter($samples, function (MetricFamilySamples $sample) {
                return strpos($sample->getName(), $this->namespace . '_') === 0;
            });
        }
        $rendered = new RenderTextFormat();
        try {
            return $rendered->render($samples, true);
        } catch (Throwable $exception) {
            $this->logException($exception);
            return '';
        }
    }

    public function purgeData(): void
    {
        $this->registry->wipeStorage();
    }

    public function workerProcessStart(): void
    {
        $this->processStart = hrtime(true);

        try {
            $gauge = $this->getUptimeSecondsGauge();
            $gauge->set(0, [$this->pid, $this->uuid]);
        } catch (Throwable $exception) {
            $this->logException(
                $exception,
                ['pid' => $this->pid, 'uuid' => $this->uuid],
            );
        }
    }

    public function workerProcessStep(): void
    {
        $elapsed = floor((hrtime(true) - $this->processStart) / 1e9);

        try {
            $uptimeGauge = $this->getUptimeSecondsGauge();
            $uptimeGauge->set($elapsed, [$this->pid, $this->uuid]);
        } catch (Throwable $exception) {
            $this->logException(
                $exception,
                ['pid' => $this->pid, 'uuid' => $this->uuid],
            );
        }

        try {
            $memoryUsageGauge = $this->getMemoryUsageGauge();
            $memoryUsageGauge->set(
                memory_get_usage(),
                [$this->pid, $this->uuid, 'real']
            );
            $memoryUsageGauge->set(
                memory_get_usage(true),
                [$this->pid, $this->uuid, 'total']
            );
        } catch (Throwable $exception) {
            $this->logException(
                $exception,
                ['pid' => $this->pid, 'uuid' => $this->uuid],
            );
        }

        try {
            $peakMemoryUsageGauge = $this->getPeakMemoryUsageGauge();
            $peakMemoryUsageGauge->set(
                memory_get_peak_usage(),
                [$this->pid, $this->uuid, 'real']
            );
            $peakMemoryUsageGauge->set(
                memory_get_peak_usage(true),
                [$this->pid, $this->uuid, 'total']
            );
        } catch (Throwable $exception) {
            $this->logException(
                $exception,
                ['pid' => $this->pid, 'uuid' => $this->uuid],
            );
        }
    }

    public function incrementMessageCounter(string $messageType, bool $success = true): void
    {
        try {
            $counter = $this->getEventsCounter();
            $counter->inc([$messageType, $success ? 'success' : 'error']);
        } catch (Throwable $exception) {
            $this->logException($exception);
        }
    }

    public function startProcessingMessage(string $messageType): void
    {
        $this->timeStart = hrtime(true);
        try {
            $gauge = $this->getEventsInProgressGauge();
            $gauge->inc([$messageType]);
        } catch (Throwable $exception) {
            $this->logException($exception);
        }
    }

    public function finishProcessingMessage(string $messageType): void
    {
        /** @var int $end */
        $end = hrtime(true);
        $seconds = ($end - $this->timeStart) / 1e9;
        try {
            $gauge = $this->getEventsInProgressGauge();
            $gauge->dec([$messageType]);
        } catch (Throwable $exception) {
            $this->logException($exception);
        }

        try {
            $gauge = $this->getEventTimestampGauge();
            $gauge->set(time(), [$messageType]);
        } catch (Throwable $exception) {
            $this->logException($exception);
        }

        try {
            $histogram = $this->getEventDurationHistogram();
            $histogram->observe($seconds, [$messageType]);
        } catch (Throwable $exception) {
            $this->logException($exception);
        }
    }

    /**
     * @throws MetricsRegistrationException
     */
    private function getEventsCounter(): Counter
    {
        return $this->registry->getOrRegisterCounter(
            $this->namespace,
            'hermes_event_processed_total',
            'Total number of processed events',
            ['event_type', 'status']
        );
    }

    /**
     * @throws MetricsRegistrationException
     */
    private function getEventsInProgressGauge(): Gauge
    {
        return $this->registry->getOrRegisterGauge(
            $this->namespace,
            'hermes_events_in_progress',
            'Currently processing events',
            ['event_type']
        );
    }

    /**
     * @throws MetricsRegistrationException
     */
    private function getEventTimestampGauge(): Gauge
    {
        return $this->registry->getOrRegisterGauge(
            $this->namespace,
            'hermes_last_event_timestamp_seconds',
            'Unix timestamp of last processed event',
            ['event_type']
        );
    }

    /**
     * @throws MetricsRegistrationException
     */
    private function getEventDurationHistogram(): Histogram
    {
        return $this->registry->getOrRegisterHistogram(
            $this->namespace,
            'hermes_event_duration_seconds',
            'Event processing duration in seconds',
            ['event_type'],
            [0.01, 0.05, 0.1, 0.25, 0.5, 1, 2, 5]
        );
    }

    /**
     * @throws MetricsRegistrationException
     */
    private function getUptimeSecondsGauge(): Gauge
    {
        return $this->registryTemporary->getOrRegisterGauge(
            $this->namespace,
            'hermes_uptime_seconds',
            'Worker uptime in seconds',
            ['pid', 'uuid']
        );
    }

    /**
     * @throws MetricsRegistrationException
     */
    private function getMemoryUsageGauge(): Gauge
    {
        return $this->registryTemporary->getOrRegisterGauge(
            $this->namespace,
            'hermes_memory_usage_bytes',
            'Current memory usage in bytes',
            ['pid', 'uuid', 'type']
        );
    }

    /**
     * @throws MetricsRegistrationException
     */
    private function getPeakMemoryUsageGauge(): Gauge
    {
        return $this->registryTemporary->getOrRegisterGauge(
            $this->namespace,
            'hermes_peak_memory_usage_bytes',
            'Peak memory usage in bytes',
            ['pid', 'uuid', 'type']
        );
    }

    /**
     * @param array<string, mixed> $context
     * @param string $level {@see LogLevel}::* log level
     */
    protected function logException(Throwable $exception, array $context = [], string $level = LogLevel::ERROR): void
    {
        if ($this->logger === null) {
            return;
        }
        $exceptionContext = [
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ];
        if ($context !== []) {
            $exceptionContext['context'] = $context;
        }
        $this->logger->log($level, $exception->getMessage(), $exceptionContext);
    }
}
