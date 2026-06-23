<?php

namespace sustdev\insights\models;

use craft\base\Model;

class Settings extends Model
{
    /**
     * Shared secret the platform sends in the X-Insights-Secret header.
     * Supports env syntax ("$INSIGHTS_SECRET"); set it in
     * config/insights.php.
     */
    public ?string $secret = null;

    /**
     * Fail the queue health check when the worker has not processed the
     * heartbeat job for this many minutes. The heartbeat refreshes every
     * 5 minutes, so the default leaves room for a short backlog; raise it
     * on a site with legitimately long-running jobs. Set in
     * config/insights.php.
     */
    public int $queueStallMinutes = 15;

    public function defineRules(): array
    {
        return [
            [['secret'], 'string'],
            // Must exceed the 5-minute heartbeat interval, or the stamp would
            // always read as older than the threshold and the check would
            // fail itself.
            [['queueStallMinutes'], 'integer', 'min' => 6],
        ];
    }
}
