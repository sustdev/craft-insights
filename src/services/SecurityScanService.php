<?php

namespace sustdev\insights\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\queue\Queue as DatabaseQueue;
use sustdev\insights\helpers\Sherlock;
use sustdev\insights\jobs\SecurityScanJob;
use yii\base\Component;

/**
 * Queues a Sherlock scan when the platform asks for one.
 *
 * Deliberately holds no policy: it does not decide when a scan is due, only
 * that a second one should not be queued while the first is still waiting.
 * The platform polls every few minutes, so without that guard a site whose
 * scan is overdue would collect a fresh scan job on every single poll until
 * the worker got around to the first.
 */
class SecurityScanService extends Component
{
    private const ENQUEUE_MUTEX = 'insights-security-scan-enqueue';

    /** Best-effort "a scan is in flight" flag for non-database queue drivers. */
    private const QUEUED_CACHE_KEY = 'insights-security-scan-queued';

    /**
     * How long the fallback flag holds off another scan on a non-database
     * driver. Comfortably longer than a scan takes (seconds), short enough
     * that a scan lost with a dying worker is retried within the hour.
     */
    private const QUEUED_CACHE_TTL = 900;

    /**
     * Queue a scan for the current site unless one is already pending for it.
     *
     * Everything here is scoped per site. Sherlock scans one site at a time,
     * so two sites on one install each need their own scan, and a scan pending
     * for one must not look like a scan pending for the other: that would leave
     * the second site's report stuck at its last scan forever.
     *
     * @return bool Whether a scan was queued. False means one was already
     *              waiting, so the caller should not treat it as a failure.
     */
    public function enqueue(): bool
    {
        if (! Sherlock::isAvailable()) {
            return false;
        }

        // Resolved here, in the request the platform made to this site's own
        // domain, because the queue worker that will run the job cannot resolve
        // it: console context has no current site but the primary one.
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        // Guard the check-then-push, or two overlapping polls would both see
        // nothing pending and both queue a scan.
        $mutex = Craft::$app->getMutex();
        $mutexName = self::ENQUEUE_MUTEX.'-'.$siteId;

        if (! $mutex->acquire($mutexName)) {
            return false;
        }

        try {
            if ($this->isPending($siteId)) {
                return false;
            }

            $queue = Craft::$app->getQueue();
            $queue->push(new SecurityScanJob(['siteId' => $siteId]));

            // Only the non-database drivers read this back; on the database
            // queue the table itself is the source of truth.
            if (! $queue instanceof DatabaseQueue) {
                Craft::$app->getCache()->set($this->cacheKey($siteId), true, self::QUEUED_CACHE_TTL);
            }

            return true;
        } finally {
            $mutex->release($mutexName);
        }
    }

    private function isPending(int $siteId): bool
    {
        $queue = Craft::$app->getQueue();

        // The database driver is the source of truth: is a scan row for this
        // site already waiting? Scoped to the queue's channel so rows on
        // another channel are not mistaken for a pending scan on this one, and
        // filtered on fail = false so a job that gave up cannot block every
        // future scan.
        if ($queue instanceof DatabaseQueue) {
            return (new Query())
                ->from(Table::QUEUE)
                ->where([
                    'channel' => $queue->channel ?? 'queue',
                    'description' => SecurityScanJob::descriptionForSite($siteId),
                    'fail' => false,
                ])
                ->exists();
        }

        // Other drivers cannot be inspected for a specific pending job, so fall
        // back to the flag. A duplicate scan is wasteful but harmless: it just
        // overwrites the last result with an equally fresh one.
        return Craft::$app->getCache()->get($this->cacheKey($siteId)) !== false;
    }

    private function cacheKey(int $siteId): string
    {
        return self::QUEUED_CACHE_KEY.'-'.$siteId;
    }
}
