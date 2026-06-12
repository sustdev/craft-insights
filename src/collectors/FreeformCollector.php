<?php

namespace sustdev\insights\collectors;

use Craft;

/**
 * Form metrics via Freeform when it is installed; sites without
 * Freeform report an empty form list and zero errors. Monitoring must
 * never break a site, so every failure path degrades to zeros.
 */
class FreeformCollector
{
    /**
     * @return array{forms: list<array{handle: string, name: string, submissions24h: int, submissions7d: int}>, errors7d: int}
     */
    public function collect(): array
    {
        if (! class_exists(\Solspace\Freeform\Freeform::class)
            || ! Craft::$app->getPlugins()->isPluginEnabled('freeform')) {
            return ['forms' => [], 'errors7d' => 0];
        }

        try {
            $freeform = \Solspace\Freeform\Freeform::getInstance();

            $counts24h = $freeform->submissions->getSubmissionCountByForm(
                false,
                \Carbon\Carbon::now()->subDay(),
                \Carbon\Carbon::now(),
            );
            $counts7d = $freeform->submissions->getSubmissionCountByForm(
                false,
                \Carbon\Carbon::now()->subDays(7),
                \Carbon\Carbon::now(),
            );

            $forms = [];

            foreach ($freeform->forms->getAllForms() as $form) {
                $forms[] = [
                    'handle' => $form->getHandle(),
                    'name' => $form->getName(),
                    'submissions24h' => (int) ($counts24h[$form->getId()] ?? 0),
                    'submissions7d' => (int) ($counts7d[$form->getId()] ?? 0),
                ];
            }

            return ['forms' => $forms, 'errors7d' => $this->errorCount7d()];
        } catch (\Throwable) {
            return ['forms' => [], 'errors7d' => 0];
        }
    }

    /**
     * Counts ERROR-or-worse lines in Freeform's monolog files for the
     * last 7 days. Reading the files directly is sturdier than relying
     * on Freeform's log reader internals.
     */
    private function errorCount7d(): int
    {
        try {
            $cutoff = (new \DateTimeImmutable('-7 days'))->format('Y-m-d H:i:s');
            $count = 0;

            foreach (glob(Craft::$app->getPath()->getLogPath().'/freeform*.log') ?: [] as $file) {
                $handle = fopen($file, 'r');

                if (! $handle) {
                    continue;
                }

                while (($line = fgets($handle)) !== false) {
                    if (! preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})[^\]]*\] [\w.-]*\.(ERROR|CRITICAL|ALERT|EMERGENCY):/', $line, $match)) {
                        continue;
                    }

                    if ($match[1] >= $cutoff) {
                        $count++;
                    }
                }

                fclose($handle);
            }

            return $count;
        } catch (\Throwable) {
            return 0;
        }
    }
}
