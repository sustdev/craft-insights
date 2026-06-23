<?php

namespace sustdev\insights\jobs;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\queue\BaseJob;
use sustdev\insights\Plugin;

/**
 * The queue heartbeat (canary). When the worker executes this job it stamps
 * the current time in the cache, then re-queues the next heartbeat with a
 * delay so one keeps cycling every few minutes without an external cron. A
 * dead or hung worker never runs it, so the stamp goes stale and
 * QueueCollector reports the queue as down.
 */
class QueueHealthJob extends BaseJob
{
    public const CACHE_KEY = 'insights-queue-heartbeat';

    public const DESCRIPTION = 'Insights queue heartbeat';

    public function execute($queue): void
    {
        Craft::$app->getCache()->set(self::CACHE_KEY, (int) DateTimeHelper::currentUTCDateTime()->format('U'));

        // Keep the chain alive: schedule the next heartbeat. Pushed before
        // this job finishes, so a healthy worker always has the next one
        // queued; if the worker dies the chain stops and the stamp ages.
        Plugin::getInstance()->queueHealth->scheduleNext();
    }

    protected function defaultDescription(): ?string
    {
        return self::DESCRIPTION;
    }
}
