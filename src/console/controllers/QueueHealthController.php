<?php

namespace sustdev\insights\console\controllers;

use sustdev\insights\Plugin;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\BaseConsole;

/**
 * Metronome for the queue heartbeat: `insights/queue-health/run` re-seeds it.
 * On the database queue the chain re-schedules itself so a cron is optional;
 * on other drivers run this every 5 minutes for a tight heartbeat. See the
 * README cron snippet.
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
