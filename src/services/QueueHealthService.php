<?php

namespace sustdev\insights\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\queue\Queue as DatabaseQueue;
use sustdev\insights\jobs\QueueHealthJob;
use yii\base\Component;

/**
 * Keeps the queue heartbeat (canary) running, on any queue driver.
 *
 * On Craft's database queue the canary re-schedules itself every interval
 * (scheduleNext), so the heartbeat stays fresh with no cron, and the queue
 * table gives an exact "is one already pending" check so the chain never
 * forks.
 *
 * Other drivers (Redis, ...) cannot be inspected for a specific pending job,
 * so the canary does NOT self-requeue there; ensure() drives it instead, from
 * the metrics poll and an optional cron. Because nothing self-requeues on
 * those drivers, a duplicate canary is harmless (it just stamps the cache
 * again), so a best-effort cache flag is enough to throttle re-seeding. Such a
 * site should add the cron for a tight 5-minute heartbeat; on the poll alone a
 * healthy site refreshes at the poll cadence.
 *
 * ensure() is mutex-guarded so overlapping callers cannot both seed.
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

    /** Best-effort "a canary is in flight" flag for non-database drivers. */
    private const SCHEDULED_CACHE_KEY = 'insights-queue-heartbeat-scheduled';

    /** Low number = runs first, so the canary jumps ahead of a normal backlog. */
    private const PRIORITY = 1;

    /**
     * Continue the chain on the database queue, where the exact dedup keeps it
     * single. A no-op on other drivers, which are driven by ensure() instead.
     */
    public function scheduleNext(): void
    {
        if ($this->usesDatabaseQueue()) {
            $this->push(self::INTERVAL);
        }
    }

    /**
     * Seed the heartbeat if one is not already pending. Runs immediately (no
     * delay) so the stamp is fresh at once. The database driver checks the
     * queue table exactly; other drivers fall back to the cache flag.
     */
    public function ensure(): void
    {
        // Guard the check-then-push: two overlapping polls (or a poll racing
        // the cron) would otherwise both see nothing pending and both seed it.
        $mutex = Craft::$app->getMutex();

        if (! $mutex->acquire(self::SEED_MUTEX)) {
            return;
        }

        try {
            if (! $this->isPending()) {
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

        // For the non-database fallback in isPending(). It only throttles
        // re-seeding; if it lapses, the worst case is an extra canary that
        // stamps the cache and exits, since non-database drivers do not
        // self-requeue and so cannot fork a chain.
        Craft::$app->getCache()->set(self::SCHEDULED_CACHE_KEY, true, self::INTERVAL);
    }

    private function isPending(): bool
    {
        $queue = Craft::$app->getQueue();

        // The database driver is the source of truth: is a heartbeat row
        // already waiting? Exact, so the self-requeue chain never forks. Scope
        // to the queue's channel so a second queue channel's rows are not
        // mistaken for a pending heartbeat on this one.
        if ($queue instanceof DatabaseQueue) {
            return (new Query())
                ->from(Table::QUEUE)
                ->where(['channel' => $queue->channel ?? 'queue', 'description' => QueueHealthJob::DESCRIPTION, 'fail' => false])
                ->exists();
        }

        return Craft::$app->getCache()->get(self::SCHEDULED_CACHE_KEY) !== false;
    }

    private function usesDatabaseQueue(): bool
    {
        return Craft::$app->getQueue() instanceof DatabaseQueue;
    }
}
