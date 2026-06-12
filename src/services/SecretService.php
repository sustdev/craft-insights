<?php

namespace sustdev\insights\services;

use craft\db\Query;
use craft\helpers\App;
use craft\helpers\StringHelper;
use sustdev\insights\Plugin;
use yii\base\Component;

/**
 * Resolves the shared secret: an env-configured secret (via
 * config/insights.php) wins, otherwise the one generated at install
 * time. Sites missing a stored secret (installed before it existed)
 * get one on first use.
 */
class SecretService extends Component
{
    public function get(): ?string
    {
        /** @var \sustdev\insights\models\Settings $settings */
        $settings = Plugin::$plugin->getSettings();
        $configured = App::parseEnv($settings->secret);

        if ($configured) {
            return $configured;
        }

        $stored = (new Query())->from('{{%insights}}')->select('secret')->scalar();

        if ($stored) {
            return $stored;
        }

        $generated = StringHelper::randomString(40);

        \Craft::$app->getDb()->createCommand()
            ->insert('{{%insights}}', ['secret' => $generated])
            ->execute();

        return $generated;
    }
}
