<?php

namespace sustdev\insights\collectors;

use Craft;
use sustdev\insights\helpers\Sherlock;

/**
 * The last Sherlock security scan, passed through as-is. Sites without
 * Sherlock report `available: false` and nothing else.
 *
 * Unlike QueueCollector, this one reaches no verdict. Sherlock mixes security
 * tests with "you are behind on updates", and only the platform knows which of
 * those it wants to act on, how old a scan may be before it stops counting,
 * and which findings a given site has chosen to ignore. Deciding any of that
 * here would freeze the policy into fifteen plugin installs. So this reports
 * facts and the platform interprets them.
 *
 * Monitoring must never break a site, so every failure path degrades to a
 * scan-less payload rather than throwing.
 */
class SherlockCollector
{
    /**
     * @return array{available: bool, scannedAt: string|null, pass?: bool, warning?: bool, results?: array{fail: array<string, string>, warning: array<string, string>, pass: array<string, string>}}
     */
    public function collect(): array
    {
        if (! Sherlock::isAvailable()) {
            return ['available' => false, 'scannedAt' => null];
        }

        try {
            // No site ID: Sherlock scans and reports per site, and the platform
            // polls this endpoint on the site's own domain, so the current site
            // is already the one being asked about.
            $scan = \putyourlightson\sherlock\Sherlock::$plugin->scans->getLastScan();

            // Installed but never scanned. The platform reads a null scannedAt
            // the same way it reads an expired one, so it will ask for a scan.
            if ($scan === null) {
                return ['available' => true, 'scannedAt' => null];
            }

            return [
                'available' => true,
                'scannedAt' => $scan->dateCreated?->format(DATE_ATOM),
                'pass' => (bool) $scan->pass,
                'warning' => (bool) $scan->warning,
                'results' => [
                    'fail' => $this->tests($scan, 'fail'),
                    'warning' => $this->tests($scan, 'warning'),
                    'pass' => $this->tests($scan, 'pass'),
                ],
            ];
        } catch (\Throwable $e) {
            // Sherlock stores its results as a serialised blob, so its shape is
            // an implementation detail rather than a promised contract. If that
            // ever changes, the security block goes quiet but queue and forms
            // keep reporting. Log it, because a permanently scan-less site is
            // otherwise indistinguishable from one that was never scanned.
            Craft::warning('Could not read the Sherlock scan: '.$e->getMessage(), 'insights');

            return ['available' => true, 'scannedAt' => null];
        }
    }

    /**
     * One bucket of the scan, as test handle => detail. The handle (`devMode`,
     * `cpTrigger`) is the only stable identifier Sherlock gives us, so the
     * platform fingerprints its findings on it. The detail is Sherlock's own
     * text and may contain markup, so it is data to be escaped, never HTML to
     * be rendered.
     *
     * @return array<string, string>
     */
    private function tests(object $scan, string $bucket): array
    {
        $tests = $scan->results[$bucket] ?? [];

        if (! is_array($tests)) {
            return [];
        }

        return array_map(static fn ($value): string => (string) $value, $tests);
    }
}
