<?php

namespace sustdev\insights\helpers;

use Craft;

/**
 * Sherlock is an optional dependency: the collector, the job and the service
 * all have to ask whether it is there before touching it. One answer, in one
 * place, so the three can never disagree about what "installed" means.
 *
 * Both halves of the check are needed. The class may be autoloadable while the
 * plugin is uninstalled or disabled, and the plugin record may linger as
 * enabled after the package itself has been removed.
 */
class Sherlock
{
    public const HANDLE = 'sherlock';

    public static function isAvailable(): bool
    {
        return class_exists(\putyourlightson\sherlock\Sherlock::class)
            && Craft::$app->getPlugins()->isPluginEnabled(self::HANDLE);
    }
}
