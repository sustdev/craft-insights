<?php

namespace sustdev\insights\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use sustdev\insights\jobs\QueueHealthJob;
use yii\base\Component;

/**
 * Keeps exactly one queue heartbeat (canary) cycling. Three callers feed in,
 * all idempotent: the job re-schedules itself (scheduleNext), and the console
 * command and the metrics endpoint re-seed the chain if it ever stops
 * (ensure), so the heartbeat survives a deploy, a cache clear, or a worker
 * that died and came back. A site needs no cron; an optional cron is just a
 * fourth caller of ensure().
 */
class QueueHealthService extends Component
{
    /** Seconds between heartbeats, so the stamp stays fresh without a cron. */
    public const INTERVAL = 300;

    /** Low number = runs first, so the canary jumps ahead of a normal backlog. */
    private const PRIORITY = 1;

    /**
     * Continue the chain: schedule the next heartbeat after the interval.
     */
    public function scheduleNext(): void
    {
        Craft::$app->getQueue()
            ->delay(self::INTERVAL)
            ->priority(self::PRIORITY)
            ->push(new QueueHealthJob());
    }

    /**
     * Seed the chain if it is not already running. Runs the first heartbeat
     * immediately (no delay) so the stamp is fresh at once. A no-op when a
     * heartbeat is already queued, delayed, or being processed.
     */
    public function ensure(): void
    {
        if ($this->isQueued()) {
            return;
        }

        Craft::$app->getQueue()
            ->priority(self::PRIORITY)
            ->push(new QueueHealthJob());
    }

    private function isQueued(): bool
    {
        return (new Query())
            ->from(Table::QUEUE)
            ->where(['description' => QueueHealthJob::DESCRIPTION, 'fail' => false])
            ->exists();
    }
}
