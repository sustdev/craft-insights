<?php

use craft\helpers\App;

/**
 * Copy this file to craft/config/insights.php and set INSIGHTS_SECRET
 * in the site's .env. The same secret goes into the Insights platform
 * under Site settings -> Data sources -> Craft plugin.
 */
return [
    'secret' => App::env('INSIGHTS_SECRET'),
];
