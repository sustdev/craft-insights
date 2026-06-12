<?php

namespace sustdev\insights\utilities;

use Craft;
use craft\base\Utility;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use sustdev\insights\Plugin;

/**
 * Shows the shared secret and endpoint in the control panel, so
 * connecting a site to the platform never requires server access. A
 * utility (not a settings page) because settings pages are hidden in
 * production when allowAdminChanges is off.
 */
class SecretUtility extends Utility
{
    public static function id(): string
    {
        return 'insights';
    }

    public static function displayName(): string
    {
        return 'Sustdev Insights';
    }

    public static function icon(): ?string
    {
        return 'chart-line';
    }

    public static function contentHtml(): string
    {
        $secret = Plugin::$plugin->secret->get();
        $endpoint = UrlHelper::siteUrl('actions/insights/metrics');

        return Html::tag('div',
            Html::tag('p', 'This site reports queue health, form submissions and Freeform errors to the Sustdev Insights monitoring platform.')
            .Html::tag('h2', 'Connect this site')
            .Html::tag('ol',
                Html::tag('li', 'Copy the shared secret below.')
                .Html::tag('li', 'In the Insights platform, open this site\'s settings, enable the Craft plugin data source and paste the secret.')
            )
            .Html::tag('h3', 'Shared secret')
            .Html::tag('input', '', [
                'type' => 'text',
                'class' => 'text fullwidth code',
                'readonly' => true,
                'value' => $secret,
                'onclick' => 'this.select();',
                'aria-label' => 'Shared secret',
            ])
            .Html::tag('h3', 'Endpoint')
            .Html::tag('p', Html::tag('code', Html::encode($endpoint)))
            .Html::tag('p', 'The endpoint only answers requests carrying this secret in the X-Insights-Secret header.', ['class' => 'light'])
        );
    }
}
