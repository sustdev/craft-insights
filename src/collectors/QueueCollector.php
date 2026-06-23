<?php

namespace sustdev\insights\collectors;

use Craft;
use sustdev\insights\jobs\QueueHealthJob;
use sustdev\insights\Plugin;

/**
 * Queue health, decided here so the platform just stores the verdict. The
 * signal is a heartbeat: a canary job stamps the cache every few minutes, so
 * "is the worker draining the queue" becomes "did the canary run recently".
 * That tells a stalled worker apart from a large but healthy backlog, which a
 * pending count or oldest-job age never could.
 */
class QueueCollector
{
    /**
     * @return array{status: string, message: string, pending: int, failed: int, minutesSinceHeartbeat: int|null}
     */
    public function collect(): array
    {
        $queue = Craft::$app->getQueue();

        $pending = method_exists($queue, 'getTotalWaiting') ? (int) $queue->getTotalWaiting() : 0;
        $failed = method_exists($queue, 'getTotalFailed') ? (int) $queue->getTotalFailed() : 0;

        $threshold = (int) Plugin::getInstance()->getSettings()->queueStallMinutes;
        $beat = Craft::$app->getCache()->get(QueueHealthJob::CACHE_KEY);
        $secondsSince = $beat === false ? null : max(0, time() - (int) $beat);

        [$status, $message] = $this->verdict($pending, $failed, $threshold, $secondsSince);
        $minutesSince = $secondsSince === null ? null : intdiv($secondsSince, 60);

        return [
            'status' => $status,
            'message' => $message,
            'pending' => $pending,
            'failed' => $failed,
            'minutesSinceHeartbeat' => $minutesSince,
        ];
    }

    /**
     * @return array{0: string, 1: string} The status (ok/warning/failed) and a short message.
     */
    private function verdict(int $pending, int $failed, int $threshold, ?int $secondsSince): array
    {
        // Compare in seconds for a sharp boundary; report whole minutes.
        $minutes = $secondsSince === null ? null : intdiv($secondsSince, 60);

        // A stalled worker is the worst case: the canary has not run in time,
        // so nothing in the queue is moving.
        if ($secondsSince !== null && $secondsSince >= $threshold * 60) {
            return ['failed', "Queue worker idle for {$minutes}m (limit {$threshold}m); {$pending} pending."];
        }

        if ($failed > 0) {
            return ['failed', "{$failed} failed jobs."];
        }

        // No stamp yet: a fresh install, or the cache was just cleared. The
        // canary will run shortly, so this is a heads-up, not an outage.
        if ($secondsSince === null) {
            return ['warning', 'No queue heartbeat yet (just installed or cache cleared).'];
        }

        return ['ok', "Worker active, last heartbeat {$minutes}m ago; {$pending} pending."];
    }
}
