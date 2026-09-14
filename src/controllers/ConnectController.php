<?php
/**
 * One-click connect controller (Phase 2, docs/mobile-onboarding-plan.md).
 *
 * CP-only, admin-gated:
 *   /actions/mx-cookieless-analytics/connect/start
 *     → store a one-time `state` in the plugin settings and redirect to the
 *       MetriXs dashboard's /connect/craft page.
 *   /actions/mx-cookieless-analytics/connect/callback
 *     ← dashboard redirect with ?code=…&state=…: validate the single-use
 *       state (hash_equals, cleared before any use), exchange the pair for
 *       a site-scoped API key (returned once), save it — the normal
 *       challenge → verify handshake then runs via the
 *       EVENT_AFTER_SAVE_PLUGIN_SETTINGS hook in Plugin.php.
 *
 * GET actions (no CSRF token needed) + the one-time `state` (which this
 * plugin generated before leaving, so a hostile link can never connect the
 * site to a third party's MetriXs account) are the security boundary — the
 * dashboard cannot carry Craft's CSRF token across the redirect.
 */

namespace metrixs\mxcookielessanalytics\controllers;

use Craft;
use metrixs\mxcookielessanalytics\Plugin;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\web\Response;

class ConnectController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Admin-only: connecting decides where the site's analytics go.
        if (!Craft::$app->getUser()->getIsAdmin()) {
            return false;
        }

        return true;
    }

    /**
     * Step 1: one-time state + redirect to the MetriXs dashboard.
     *
     * @return Response
     */
    public function actionStart(): Response
    {
        $plugin = Plugin::getInstance();
        $state = bin2hex(random_bytes(16));
        $plugin->saveSettingsValues([
            'oauthState' => $state,
            'oauthExpires' => time() + 900,
        ]);

        $domain = (string) $plugin->getSettings()->domain;
        if ($domain === '') {
            $domain = $plugin->siteDomain();
        }

        $url = $plugin->apiBase() . '/connect/craft?' . http_build_query([
            'state' => $state,
            'site' => $domain,
            // UrlHelper builds the action URL with the CP's own scheme/host —
            // the API validates it against the site's domain.
            'back' => UrlHelper::actionUrl('mx-cookieless-analytics/connect/callback', false),
        ]);

        return Craft::$app->getResponse()->redirect($url);
    }

    /**
     * Step 2 (dashboard callback): state check → key exchange → handshake.
     *
     * @return Response
     */
    public function actionCallback(): Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $session = Craft::$app->getSession();
        $redirect = 'settings/plugins/mx-cookieless-analytics';

        $code = preg_replace('/[^0-9a-f]/', '', (string) Craft::$app->getRequest()->getQueryParam('code'));
        $state = preg_replace('/[^A-Za-z0-9]/', '', (string) Craft::$app->getRequest()->getQueryParam('state'));

        $stored = (string) $settings->oauthState;
        $expires = (int) $settings->oauthExpires;
        // Clear BEFORE any use — the state is single-use.
        $plugin->saveSettingsValues(['oauthState' => '', 'oauthExpires' => 0]);

        if ($code === '' || $stored === '' || !hash_equals($stored, $state) || time() > $expires) {
            $session->setError(Craft::t(
                'mx-cookieless-analytics',
                'MX Cookieless Analytics: one-click connect did not complete (the request expired or was already used). Please try again.'
            ));
            return Craft::$app->getResponse()->redirect($redirect);
        }

        // Exchange { code, state } → full API key (returned once). No key
        // exists yet — the pair itself is the proof.
        $exchange = $plugin->postJson('/api/integrations/craft/exchange', [
            'code' => $code,
            'state' => $state,
        ], '');
        if (empty($exchange['ok']) || empty($exchange['data']['apiKey'])) {
            $session->setError(Craft::t(
                'mx-cookieless-analytics',
                'MX Cookieless Analytics: one-click connect did not complete. Please try again.'
            ));
            return Craft::$app->getResponse()->redirect($redirect);
        }

        $apiKey = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $exchange['data']['apiKey']);
        // Saving the settings fires EVENT_AFTER_SAVE_PLUGIN_SETTINGS, which
        // runs the normal challenge → verify handshake (Plugin.php).
        $plugin->saveSettingsValues(['apiKey' => $apiKey]);

        return Craft::$app->getResponse()->redirect($redirect);
    }
}
