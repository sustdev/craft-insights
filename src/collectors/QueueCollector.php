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
        $minutesSince = $beat === false ? null : max(0, (int) floor((time() - (int) $beat) / 60));

        [$status, $message] = $this->verdict($pending, $failed, $threshold, $minutesSince);

        return [
            'status' => $status,
            'message' => $message,
            'pending' => $pending,
            'failed' => $failed,
            'minutesSinceHeartbeat' => $minutesSince,
        ];
    }

    /**
     * @return array{0: string, 1: string} The Oh Dear-style status and a short message.
     */
    private function verdict(int $pending, int $failed, int $threshold, ?int $minutesSince): array
    {
        // A stalled worker is the worst case: the canary has not run in time,
        // so nothing in the queue is moving.
        if ($minutesSince !== null && $minutesSince >= $threshold) {
            return ['failed', "Queue worker idle for {$minutesSince}m (limit {$threshold}m); {$pending} pending."];
        }

        if ($failed > 0) {
            return ['failed', "{$failed} failed jobs."];
        }

        // No stamp yet: a fresh install, or the cache was just cleared. The
        // canary will run shortly, so this is a heads-up, not an outage.
        if ($minutesSince === null) {
            return ['warning', 'No queue heartbeat yet (just installed or cache cleared).'];
        }

        return ['ok', "Worker active, last heartbeat {$minutesSince}m ago; {$pending} pending."];
    }
}
