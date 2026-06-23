<?php

use craft\helpers\App;

/**
 * Copy this file to craft/config/insights.php and set INSIGHTS_SECRET
 * in the site's .env. The same secret goes into the Insights platform
 * under Site settings -> Data sources -> Craft plugin.
 */
return [
    'secret' => App::env('INSIGHTS_SECRET'),

    // Fail the queue health check when the worker has not processed the
    // heartbeat job for this many minutes. Raise it on a site with
    // legitimately long-running jobs.
    'queueStallMinutes' => 15,
];
