<?php

declare(strict_types=1);

namespace Distantmagic\Resonance;

use Distantmagic\Resonance\Attribute\Singleton;
use Psr\Log\LoggerInterface;

#[Singleton]
readonly class CronJobRunner
{
    public function __construct(private LoggerInterface $logger) {}

    public function runCronJob(CronRegisteredJob $cronRegisteredJob): void
    {
        SwooleCoroutineHelper::mustGo(function () use ($cronRegisteredJob) {
            $this->logger->info(sprintf('cron_job_start(%s)', $cronRegisteredJob->name));
            $cronRegisteredJob->cronJob->onCronTick();
        });
    }
}
