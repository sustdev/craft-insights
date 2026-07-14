<?php

namespace sustdev\insights\controllers;

use Craft;
use craft\web\Controller;
use sustdev\insights\collectors\FreeformCollector;
use sustdev\insights\collectors\QueueCollector;
use sustdev\insights\collectors\SherlockCollector;
use sustdev\insights\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\ServiceUnavailableHttpException;

/**
 * GET  /actions/insights/metrics
 * POST /actions/insights/metrics/scan
 *
 * The contract lives on the platform side (sustdev/insights README);
 * the connector treats any non-2xx as a failed sync, which is exactly
 * what a wrong or missing secret should cause.
 */
class MetricsController extends Controller
{
    protected array|bool|int $allowAnonymous = ['index', 'scan'];

    public function beforeAction($action): bool
    {
        // The platform authenticates with the shared secret rather than a
        // session, so it has no CSRF token to send. Turning the check off is
        // safe precisely because scan does not honour the admin bypass below:
        // without the secret the request is rejected either way.
        if ($action->id === 'scan') {
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $this->ensureSecretIsValid();

        // Re-seed the heartbeat if its chain has stopped (after a deploy, a
        // cache clear, or a worker that died and came back). The platform
        // polls this endpoint on a schedule, so this keeps the queue check
        // working on a site without a cron.
        Plugin::getInstance()->queueHealth->ensure();

        $freeform = (new FreeformCollector())->collect();

        return $this->asJson([
            'queue' => (new QueueCollector())->collect(),
            'forms' => $freeform['forms'],
            'freeform' => ['errors7d' => $freeform['errors7d']],
            'sherlock' => (new SherlockCollector())->collect(),
        ]);
    }

    /**
     * Queues a security scan. Sherlock has no scheduler of its own, so the
     * platform, which knows how old a scan may be, asks for one when the last
     * scan has aged out.
     *
     * Answers 202 either way: a scan already waiting on the queue is the
     * outcome the caller wanted, not a failure.
     */
    public function actionScan(): Response
    {
        $this->requirePostRequest();
        $this->ensureSecretIsValid(allowAdmin: false);

        return $this->asJson([
            'queued' => Plugin::getInstance()->securityScan->enqueue(),
        ])->setStatusCode(202);
    }

    private function ensureSecretIsValid(bool $allowAdmin = true): void
    {
        // Logged-in admins may inspect the payload without a header. Not for
        // scan: that one has CSRF validation off, so an admin session must not
        // be enough to trigger it from another site.
        if ($allowAdmin && Craft::$app->getUser()->getIdentity()?->admin) {
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
