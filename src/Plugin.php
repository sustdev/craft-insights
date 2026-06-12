<?php

namespace sustdev\insights;

use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Utilities;
use sustdev\insights\models\Settings;
use sustdev\insights\services\SecretService;
use sustdev\insights\utilities\SecretUtility;
use yii\base\Event;

/**
 * Sustdev Insights plugin: serves /actions/insights/metrics for the
 * monitoring platform. The shared secret is generated on install and
 * shown in the control panel utility; config/insights.php with an env
 * value overrides it.
 *
 * @property-read SecretService $secret
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

        $this->setComponents([
            'secret' => SecretService::class,
        ]);

        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            static function (RegisterComponentTypesEvent $event) {
                $event->types[] = SecretUtility::class;
            },
        );
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
}
