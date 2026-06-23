<?php

namespace sustdev\insights\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use sustdev\insights\jobs\QueueHealthJob;
use yii\base\Component;

/**
 * Keeps a single queue heartbeat (canary) cycling. The job re-schedules itself
 * (scheduleNext), and the console command and the metrics endpoint re-seed the
 * chain if it ever stops (ensure), so the heartbeat survives a deploy, a cache
 * clear, or a worker that died and came back. A site needs no cron; an
 * optional cron is just a third caller of ensure(). ensure() is mutex-guarded
 * and the canary never fails, so the chain stays at one in practice rather
 * than forking under concurrent re-seeds.
 */
class QueueHealthService extends Component
{
    /**
     * Seconds between heartbeats, so the stamp stays fresh without a cron.
     * Keep this comfortably below queueStallMinutes * 60, or the check would
     * fail itself: the stamp would always be older than the threshold.
     */
    public const INTERVAL = 300;

    private const SEED_MUTEX = 'insights-queue-heartbeat-seed';

    /** Low number = runs first, so the canary jumps ahead of a normal backlog. */
    private const PRIORITY = 1;

    /**
     * Continue the chain: schedule the next heartbeat after the interval.
     */
    public function scheduleNext(): void
    {
        $this->push(self::INTERVAL);
    }

    /**
     * Seed the chain if it is not already running. Runs the first heartbeat
     * immediately (no delay) so the stamp is fresh at once. A no-op when a
     * heartbeat is already queued, delayed, or being processed.
     */
    public function ensure(): void
    {
        // Guard the check-then-push: two overlapping polls (or a poll racing
        // the cron) would otherwise both see an empty chain and both seed it.
        $mutex = Craft::$app->getMutex();

        if (! $mutex->acquire(self::SEED_MUTEX)) {
            return;
        }

        try {
            if (! $this->isQueued()) {
                $this->push();
            }
        } finally {
            $mutex->release(self::SEED_MUTEX);
        }
    }

    private function push(int $delaySeconds = 0): void
    {
        Craft::$app->getQueue()
            ->delay($delaySeconds)
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
