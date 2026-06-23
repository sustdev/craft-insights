<?php

namespace sustdev\insights\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\queue\Queue as DatabaseQueue;
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

    /**
     * Fallback "a canary is in flight" flag for non-database queue drivers,
     * where the queue table cannot be inspected. Expires on its own so a
     * stopped chain re-seeds.
     */
    private const SCHEDULED_CACHE_KEY = 'insights-queue-heartbeat-scheduled';

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
     * heartbeat is already scheduled or in flight.
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
            if (! $this->isScheduled()) {
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

        // For the non-database fallback below: outlive this canary's delay plus
        // a full interval, so the flag holds while a healthy chain re-pushes
        // but lapses if it stops.
        Craft::$app->getCache()->set(self::SCHEDULED_CACHE_KEY, true, $delaySeconds + self::INTERVAL * 2);
    }

    private function isScheduled(): bool
    {
        $queue = Craft::$app->getQueue();

        // The database driver lets us check exactly whether a heartbeat is
        // already pending. That self-heals (a dropped chain re-seeds) without
        // accumulating duplicates that would fork the chain on recovery.
        if ($queue instanceof DatabaseQueue) {
            return (new Query())
                ->from(Table::QUEUE)
                ->where(['description' => QueueHealthJob::DESCRIPTION, 'fail' => false])
                ->exists();
        }

        // Other drivers (Redis, ...) store jobs elsewhere, so the table query
        // would never see the heartbeat and every poll would push another.
        // Fall back to the cache flag set when a canary is pushed.
        return Craft::$app->getCache()->get(self::SCHEDULED_CACHE_KEY) !== false;
    }
}
