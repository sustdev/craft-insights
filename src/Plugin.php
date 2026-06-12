<?php

namespace sustdev\insights;

use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use sustdev\insights\models\Settings;

/**
 * Sustdev Insights plugin: serves /actions/insights/metrics for the
 * monitoring platform. No CP section; configuration via
 * config/insights.php (see src/config.php for the template).
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = false;

    public bool $hasCpSection = false;

    public static Plugin $plugin;

    public function init(): void
    {
        parent::init();

        self::$plugin = $this;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
}
