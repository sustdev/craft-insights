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

    /**
     * Stored verbatim in the queue's description column and used by
     * QueueHealthService to find a pending heartbeat. Must stay a plain
     * literal: do not wrap it in Craft::t()/Translation::prep(), or the stored
     * value would diverge from this constant and the dedup would break.
     */
    public const DESCRIPTION = 'Insights queue heartbeat';

    public function execute($queue): void
    {
        try {
            // Duration 0 = never expire. Craft sets the cache component's
            // defaultDuration from general.cacheDuration (a day by default), so
            // without an explicit 0 the stamp would expire on a worker that has
            // been down longer than that and the outage would read as "no
            // heartbeat" (warning) instead of a stall (failed).
            Craft::$app->getCache()->set(self::CACHE_KEY, (int) DateTimeHelper::currentUTCDateTime()->format('U'), 0);

            // Keep the chain alive: schedule the next heartbeat. Pushed before
            // this job finishes, so a healthy worker always has the next one
            // queued; if the worker dies the chain stops and the stamp ages.
            Plugin::getInstance()->queueHealth->scheduleNext();
        } catch (\Throwable $e) {
            // The canary must never fail: a lingering failed row would pin the
            // queue verdict to "failed" forever, and a retried canary could
            // fork the chain. A genuinely broken queue still surfaces, because
            // the heartbeat then simply goes stale and the watchdog re-seeds.
            // Log it so a broken cache backend stays diagnosable.
            Craft::warning('Queue heartbeat failed: '.$e->getMessage(), 'insights');
        }
    }

    protected function defaultDescription(): ?string
    {
        return self::DESCRIPTION;
    }
}
