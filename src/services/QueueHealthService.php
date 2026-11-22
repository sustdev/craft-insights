<?php

namespace sustdev\insights\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use craft\queue\Queue as DatabaseQueue;
use sustdev\insights\jobs\QueueHealthJob;
use yii\base\Component;

/**
 * Keeps the queue heartbeat (canary) running, on any queue driver.
 *
 * On Craft's database queue the canary re-schedules itself every interval
 * (scheduleNext), so the heartbeat stays fresh with no cron. The queue table
 * gives an exact "is one waiting" check on both paths, so a pile-up collapses
 * back to one.
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
     * Continue the chain on the database queue. A no-op on other drivers,
     * which are driven by ensure() instead.
     *
     * Only pushes when no other heartbeat is waiting. Without that check a
     * second canary (a re-run after a killed worker, an extra seed) would
     * start a second chain that re-schedules itself forever; with it, every
     * surplus chain ends the next time it runs. The job calling this is
     * reserved, so it does not count as waiting.
     */
    public function scheduleNext(): void
    {
        if ($this->usesDatabaseQueue() && ! $this->onPrimary(fn () => $this->waitingHeartbeats()->exists())) {
            $this->push(self::INTERVAL);
        }
    }

    /**
     * Seed the heartbeat if none is waiting. Runs immediately (no delay) so the
     * stamp is fresh at once. The database driver checks the queue table
     * exactly; other drivers fall back to the cache flag.
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
        if (! $this->usesDatabaseQueue()) {
            return Craft::$app->getCache()->get(self::SCHEDULED_CACHE_KEY) !== false;
        }

        // The database driver is the source of truth. Keep the heartbeat that
        // was pushed first and delete the other waiting ones, so a queue that
        // piled up canaries is cleaned on the next poll instead of the surplus
        // starving real jobs. A heartbeat running right now is not counted:
        // if it also schedules a successor, the extra one collapses on its
        // next run.
        return $this->onPrimary(function (): bool {
            $firstId = $this->waitingHeartbeats()->min('id');

            if ($firstId === null) {
                return false;
            }

            $deleted = Db::delete(Table::QUEUE, ['and', $this->waitingCondition(), ['>', 'id', $firstId]]);

            // The kept row may have been reserved since the min() above, and
            // its run may have skipped scheduling because it still saw the
            // rows just deleted. Re-check, so this ensure() seeds instead of
            // the chain ending.
            return $deleted === 0 || $this->waitingHeartbeats()->exists();
        });
    }

    private function waitingHeartbeats(): Query
    {
        return (new Query())->from(Table::QUEUE)->where($this->waitingCondition());
    }

    /**
     * Heartbeat rows waiting to run. Craft marks a reserved (running) row by
     * setting timeUpdated and only reserves rows where it is null. Scoped to
     * the queue's channel so a second channel's rows are not mistaken for a
     * heartbeat on this one.
     *
     * @return array<string, mixed>
     */
    private function waitingCondition(): array
    {
        /** @var DatabaseQueue $queue */
        $queue = Craft::$app->getQueue();

        return [
            'channel' => $queue->channel ?? 'queue',
            'description' => QueueHealthJob::DESCRIPTION,
            'fail' => false,
            'timeUpdated' => null,
        ];
    }

    /**
     * Read the queue table from the primary, as Craft's own reserve() does: a
     * lagging read replica could still show a running heartbeat as waiting,
     * and the chain would then never schedule its successor.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function onPrimary(callable $callback): mixed
    {
        return Craft::$app->getDb()->usePrimary($callback);
    }

    private function usesDatabaseQueue(): bool
    {
        return Craft::$app->getQueue() instanceof DatabaseQueue;
    }
}
