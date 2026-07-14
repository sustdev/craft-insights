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
    "queue": {
        "status": "ok",
        "message": "Worker active, last heartbeat 2m ago; 0 pending.",
        "pending": 0,
        "failed": 0,
        "minutesSinceHeartbeat": 2
    },
    "forms": [
        { "handle": "contact", "name": "Contact", "submissions24h": 3, "submissions7d": 18 }
    ],
    "freeform": { "errors7d": 0 },
    "sherlock": {
        "available": true,
        "scannedAt": "2026-07-14T22:40:48+00:00",
        "pass": false,
        "warning": true,
        "results": {
            "fail": { "devMode": "" },
            "warning": { "cpTrigger": "", "contentSecurityPolicy": "Neither Content-Security-Policy header nor meta tag are set" },
            "pass": { "httpsFrontEnd": "" }
        }
    }
}
```

- `queue`: the plugin decides queue health and reports the verdict (`status`: `ok`, `warning` or `failed`) plus a `message`. The platform stores that directly. `pending`/`failed` are Craft's counts; `minutesSinceHeartbeat` is the age of the last heartbeat (null until the first one runs). See **Queue health** below.
- `forms` and `freeform.errors7d`: via Freeform when installed (spam and hidden submissions excluded; errors counted from Freeform's log files over the last 7 days). Sites without Freeform report an empty list and 0.
- `sherlock`: the last security scan, via Sherlock when installed. See **Security scan** below.
- Wrong or missing secret returns 403; an unconfigured secret returns 503. The platform treats any non-2xx as a failed sync. Logged-in admins can open the endpoint in the browser without the header.

## Security scan

Sherlock runs around 45 security tests (updates, HTTPS, file permissions, headers, control panel settings). This plugin reports its last scan and reaches no verdict of its own, because Sherlock mixes security tests with "you are behind on updates" and only the platform knows which of those it wants to act on, how old a scan may be before it stops counting, and which findings a site has chosen to ignore.

- `available` is `false` on a site without Sherlock, and nothing else is reported. The free Lite edition runs the full scan; only Sherlock's own notification emails need a paid edition.
- `scannedAt` is when the scan ran, or `null` on a site that has never been scanned. **A pass with an old `scannedAt` is not a healthy site**, so the platform must weigh the age, not just the verdict.
- `pass` and `warning` are Sherlock's own booleans, passed through untouched.
- `results` groups the tests into `fail`, `warning` and `pass` as test handle => detail. The handle (`devMode`, `cpTrigger`) is the stable identifier to fingerprint a finding on. The detail is Sherlock's own text and may contain markup, so escape it; never render it as HTML.

### Requesting a scan

```
POST /actions/insights/metrics/scan
X-Insights-Secret: {secret}
```

Sherlock has no scheduler: it only scans when asked. So the platform, which knows how old a scan may be, asks for one when the last scan has aged out. The scan runs on the queue, because it makes its own HTTP requests back to the site and takes seconds.

Answers `202` with `{"queued": true|false}`. `false` means a scan was already waiting on the queue, which is the outcome the caller wanted, not a failure. Unlike the metrics endpoint, this one always requires the secret: an admin session is not enough.

## Queue health

A pending count or the age of the oldest job cannot tell a stalled worker apart from a large but healthy backlog (a Blitz cache warm queues hundreds of jobs the worker is steadily clearing). So health is a heartbeat instead: a lightweight, high-priority canary job stamps the cache when the worker runs it. If the worker is dead or hung, the stamp goes stale and the check fails.

- `status` is `failed` when the worker has not run the heartbeat for `queueStallMinutes` (default 15), or when there are failed jobs; `warning` when there is no heartbeat yet (fresh install or a just-cleared cache); otherwise `ok`.
- The threshold is per site. Copy `src/config.php` to `config/insights.php` and set `queueStallMinutes`. Raise it on a site with legitimately long-running jobs.
- Needs a cache shared between web and worker (file, database, or Redis, not the per-request array cache).

### Cron

On Craft's **database queue** (the default) no cron is needed: the canary re-schedules itself every 5 minutes, and the metrics endpoint re-seeds it if the chain ever stops (after a deploy, a cache clear, or a worker that died and came back).

On **another queue driver** (Redis, ...) the canary does not self-requeue, because those drivers cannot be inspected to dedup a pending heartbeat safely. There the heartbeat is driven by the metrics poll, so a healthy site refreshes only at the poll cadence; add the cron for a tight 5-minute heartbeat:

```
*/5 * * * * php craft insights/queue-health/run
```

It calls an idempotent re-seed, so it is also a harmless extra metronome on the database queue.

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
