<?php

namespace sustdev\insights\models;

use craft\base\Model;

class Settings extends Model
{
    /**
     * Shared secret the platform sends in the X-Insights-Secret header.
     * Supports env syntax ("$INSIGHTS_SECRET"); set it in
     * config/insights.php.
     */
    public ?string $secret = null;

    public function defineRules(): array
    {
        return [
            [['secret'], 'string'],
        ];
    }
}
