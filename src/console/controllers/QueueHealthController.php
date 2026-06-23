<?php

namespace sustdev\insights\console\controllers;

use sustdev\insights\Plugin;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\BaseConsole;

/**
 * Optional metronome for the queue heartbeat. The chain re-schedules itself
 * and the metrics endpoint re-seeds it, so a cron is not required; this gives
 * a site that wants one an independent push:
 *
 *   * /5 * * * * php craft insights/queue-health/run
 */
class QueueHealthController extends Controller
{
    public function actionRun(): int
    {
        Plugin::getInstance()->queueHealth->ensure();

        $this->stdout('Queue heartbeat ensured.'.PHP_EOL, BaseConsole::FG_GREEN);

        return ExitCode::OK;
    }
}
