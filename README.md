# Sustdev Insights for Craft CMS

Exposes a metrics endpoint for the Sustdev Insights monitoring platform of [Sustainable Developer](https://sust.dev): queue health, form submissions and Freeform errors. No control panel section; the plugin does one thing.

## Endpoint

```
GET /actions/insights/metrics
X-Insights-Secret: {secret}
```

Response:

```json
{
    "queue": { "pending": 2, "failed": 0, "oldestPendingMinutes": 1 },
    "forms": [
        { "handle": "contact", "name": "Contact", "submissions24h": 3, "submissions7d": 18 }
    ],
    "freeform": { "errors7d": 0 }
}
```

- `queue`: counts from Craft's queue. `oldestPendingMinutes` is the age of the oldest waiting job (database queue driver only; other drivers report 0).
- `forms` and `freeform.errors7d`: via Freeform when installed (spam and hidden submissions excluded; errors counted from Freeform's log files over the last 7 days). Sites without Freeform report an empty list and 0.
- Wrong or missing secret returns 403; an unconfigured secret returns 503. The platform treats any non-2xx as a failed sync. Logged-in admins can open the endpoint in the browser without the header.

## Installation

Add the VCS repository and require the plugin:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/sustdev/craft-insights" }
    ]
}
```

```bash
ddev composer require sustdev/craft-insights
ddev craft plugin/install insights
```

## Connecting a site

The plugin generates a shared secret on install. Open **Utilities, Sustdev Insights** in the control panel, copy the secret, and paste it in the Insights platform under Site settings, Data sources, Craft plugin. No server access needed.

The secret lives in the plugin's own database table, deliberately outside plugin settings (those end up in project config and therefore in git).

### Optional: secret via environment

An env-configured secret overrides the generated one. Copy `src/config.php` to `config/insights.php`:

```php
return [
    'secret' => \craft\helpers\App::env('INSIGHTS_SECRET'),
];
```
