<?php

namespace sustdev\insights\collectors;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\queue\Queue as DatabaseQueue;

/**
 * Queue health: pending and failed counts work on any driver exposing
 * Craft's queue interface; the age of the oldest waiting job needs the
 * database driver (which all monitored sites use).
 */
class QueueCollector
{
    /**
     * @return array{pending: int, failed: int, oldestPendingMinutes: int}
     */
    public function collect(): array
    {
        $queue = Craft::$app->getQueue();

        return [
            'pending' => method_exists($queue, 'getTotalWaiting') ? (int) $queue->getTotalWaiting() : 0,
            'failed' => method_exists($queue, 'getTotalFailed') ? (int) $queue->getTotalFailed() : 0,
            'oldestPendingMinutes' => $this->oldestPendingMinutes($queue),
        ];
    }

    private function oldestPendingMinutes(mixed $queue): int
    {
        if (! $queue instanceof DatabaseQueue) {
            return 0;
        }

        try {
            $oldest = (new Query())
                ->from(Table::QUEUE)
                ->where([
                    'channel' => $queue->channel,
                    'fail' => false,
                    'dateReserved' => null,
                ])
                ->min('[[dateCreated]]');

            if (! $oldest) {
                return 0;
            }

            // Craft stores dates in UTC.
            $age = time() - (new \DateTime($oldest, new \DateTimeZone('UTC')))->getTimestamp();

            return max(0, (int) floor($age / 60));
        } catch (\Throwable) {
            return 0;
        }
    }
}
