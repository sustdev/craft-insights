<?php

namespace sustdev\insights\controllers;

use Craft;
use craft\web\Controller;
use sustdev\insights\collectors\FreeformCollector;
use sustdev\insights\collectors\QueueCollector;
use sustdev\insights\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\ServiceUnavailableHttpException;

/**
 * GET /actions/insights/metrics
 *
 * The contract lives on the platform side (sustdev/insights README);
 * the connector treats any non-2xx as a failed sync, which is exactly
 * what a wrong or missing secret should cause.
 */
class MetricsController extends Controller
{
    protected array|bool|int $allowAnonymous = ['index'];

    public function actionIndex(): Response
    {
        $this->ensureSecretIsValid();

        $freeform = (new FreeformCollector())->collect();

        return $this->asJson([
            'queue' => (new QueueCollector())->collect(),
            'forms' => $freeform['forms'],
            'freeform' => ['errors7d' => $freeform['errors7d']],
        ]);
    }

    private function ensureSecretIsValid(): void
    {
        // Logged-in admins may inspect the payload without a header.
        if (Craft::$app->getUser()->getIdentity()?->admin) {
            return;
        }

        $secret = Plugin::$plugin->secret->get();

        if (! $secret) {
            throw new ServiceUnavailableHttpException('No insights secret available.');
        }

        $header = $this->request->getHeaders()->get('X-Insights-Secret');

        if (! $header || ! hash_equals($secret, $header)) {
            throw new ForbiddenHttpException('Invalid insights secret.');
        }
    }
}
