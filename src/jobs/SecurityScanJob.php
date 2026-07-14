<?php

namespace sustdev\insights\jobs;

use Craft;
use craft\queue\BaseJob;
use sustdev\insights\helpers\Sherlock;

/**
 * Runs a Sherlock security scan on the queue.
 *
 * Sherlock never scans on its own: it has no cron and no scheduled job, so
 * without something driving it, the "last scan" a site reports can be months
 * old while still reading as a pass. The platform decides when a scan has gone
 * stale and asks for a new one; this job is what carries that out.
 *
 * It belongs on the queue rather than in the request, because a scan makes its
 * own HTTP requests back to the site (headers, HTTPS, the control panel) and
 * takes seconds, which is far too long to hold up a metrics poll.
 */
class SecurityScanJob extends BaseJob
{
    /**
     * The site to scan. Sherlock scans one site at a time and falls back to
     * the current site when given nothing, but a queue worker runs in console
     * context, where "current site" is simply the primary one. So the site has
     * to be carried here from the request that asked for the scan, or a scan
     * requested for the second site would quietly scan the first.
     */
    public ?int $siteId = null;

    /**
     * Stored verbatim in the queue's description column and used by
     * SecurityScanService to find a pending scan for this site. Must stay a
     * plain literal: do not wrap it in Craft::t()/Translation::prep(), or the
     * stored value would diverge from what the dedup looks for.
     */
    public static function descriptionForSite(?int $siteId): string
    {
        return 'Insights security scan (site '.($siteId ?? 0).')';
    }

    public function execute($queue): void
    {
        // Re-checked here, not just at enqueue: the plugin can be removed while
        // the job is still waiting on the queue.
        if (! Sherlock::isAvailable()) {
            return;
        }

        try {
            \putyourlightson\sherlock\Sherlock::$plugin->scans->runScan($this->siteId);
        } catch (\Throwable $e) {
            // A failed scan must not leave a failed row behind: it would pin
            // the site's queue verdict to "failed" and so report a queue
            // problem where there is only a scanner problem. A site that
            // cannot scan surfaces anyway, because its last scan then ages out
            // and the platform reports the scan as expired.
            Craft::warning('Security scan failed: '.$e->getMessage(), 'insights');
        }
    }

    protected function defaultDescription(): ?string
    {
        return self::descriptionForSite($this->siteId);
    }
}
