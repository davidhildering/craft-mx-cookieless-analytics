<?php
/**
 * Challenge front controller: serves the site-verification token as JSON.
 *
 * Reachable at (explicit controller + action form, no pretty URLs needed):
 *   /actions/mx-cookieless-analytics/challenge/challenge
 *
 * The MetriXs API fetches this URL during verification — only a party
 * controlling the site's domain can serve the stored token, which proves
 * domain ownership (same mechanism as Google site verification).
 *
 * With no token stored the endpoint answers 404: it does not exist until
 * the merchant connects. The token is cleared again once the handshake
 * completes.
 */

namespace metrixs\mxcookielessanalytics\controllers;

use Craft;
use metrixs\mxcookielessanalytics\Plugin;
use craft\web\Controller;
use yii\web\Response;

class ChallengeController extends Controller
{
    /**
     * @inheritdoc
     */
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE;

    /**
     * Serve the JSON challenge and stop. No theme, no templates.
     *
     * @return Response
     */
    public function actionChallenge(): Response
    {
        $response = Craft::$app->getResponse();
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-store');

        $plugin = Plugin::getInstance();
        $token = $plugin ? (string) $plugin->getSettings()->challenge : '';

        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            // No (valid) challenge stored — the endpoint does not exist yet.
            $response->setStatusCode(404);
            $response->content = json_encode(['error' => 'not_found']);
            return $response;
        }

        $response->content = json_encode(['challenge' => $token]);
        return $response;
    }
}
