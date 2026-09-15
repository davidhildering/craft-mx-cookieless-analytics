<?php
/**
 * MX Cookieless Analytics for Craft CMS.
 *
 * Cookieless, privacy-friendly web analytics. EU-hosted, GDPR-friendly, no
 * consent banner needed. Tracking is strictly opt-in: OFF until you paste
 * your site API key and enable it in the plugin settings.
 *
 * Connect flow (same contract as the WordPress/Grav/PrestaShop plugins):
 *   1. plugin → POST /api/integrations/craft/challenge (Bearer api_key)
 *                → stores the token in the plugin settings, served at
 *                  GET /actions/mx-cookieless-analytics/challenge/challenge
 *   2. plugin → POST /api/integrations/craft/verify (Bearer api_key)
 *                → the API fetches the challenge URL on the site's public
 *                  domain and compares → site.verified = true
 *
 * The handshake runs ONLY from EVENT_AFTER_SAVE_PLUGIN_SETTINGS (the save
 * handler), never on the page-render path.
 *
 * @license MIT License
 */

namespace metrixs\mxcookielessanalytics;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\PluginEvent;
use craft\services\Plugins;
use metrixs\mxcookielessanalytics\models\Settings;
use yii\base\Event;

class Plugin extends BasePlugin
{
    /**
     * Tracker script version (cache-bust key on /tracker.js?v=).
     * MUST match TRACKER_VERSION in @metrixs/types — bump together with a
     * tracker release and publish a new plugin release so sites fetch the
     * fresh script.
     */
    public const TRACKER_VERSION = '1.2.0';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = false;

    public function init(): void
    {
        parent::init();

        // Defer everything until the app is fully initialized (request +
        // user + sites are all resolved by then).
        Craft::$app->onInit(function () {
            $request = Craft::$app->getRequest();

            // Site requests: inject the tracker (strictly opt-in).
            if ($request->getIsSiteRequest()) {
                $this->maybeInject();
            }

            // Control-panel requests: run the connect handshake right after
            // this plugin's settings are saved (post-write — the result is
            // never clobbered by the form post), and offer the one-click
            // connect while the site is not connected yet.
            if ($request->getIsCpRequest()) {
                Event::on(
                    Plugins::class,
                    Plugins::EVENT_AFTER_SAVE_PLUGIN_SETTINGS,
                    function (PluginEvent $e) {
                        if ($e->plugin === $this) {
                            $this->runHandshake();
                        }
                    }
                );
                $this->registerConnectAlert();
            }
        });
    }

    /**
     * One-click connect (Phase 2, docs/mobile-onboarding-plan.md): an alert
     * banner in the control panel while the site is not connected, linking
     * to the connect controller's start action. Admins only — connecting
     * decides where the site's analytics go.
     *
     * @return void
     */
    private function registerConnectAlert(): void
    {
        if (!class_exists(\craft\services\Cp::class)) {
            return;
        }
        Event::on(
            \craft\services\Cp::class,
            \craft\services\Cp::EVENT_REGISTER_ALERTS,
            function (\craft\events\RegisterCpAlertsEvent $e) {
                if (!Craft::$app->getUser()->getIsAdmin()) {
                    return;
                }
                if ($this->getSettings()->connected) {
                    return;
                }
                $e->alerts[] = \craft\helpers\Html::a(
                    'Connect with MetriXs',
                    \craft\helpers\UrlHelper::actionUrl('mx-cookieless-analytics/connect/start')
                )
                . ' — one-click connect: create (or open) your MetriXs account and this site is added, connected and verified automatically.';
            }
        );
    }

    // ─── Tracker injection ────────────────────────────────────────────────

    /**
     * Inject the tracker into every front-end page head.
     *
     * Strictly opt-in: nothing is registered until the site is connected
     * AND tracking is enabled. Activation alone loads nothing.
     *
     * @return void
     */
    private function maybeInject(): void
    {
        $settings = $this->getSettings();

        if (!$settings->enabled || !$settings->connected) {
            return;
        }

        // Exclude admins (default on) — permission-based, so regular
        // logged-in front-end users are still tracked.
        if ($settings->excludeAdmins) {
            $user = Craft::$app->getUser()->getIdentity();
            if ($user && $user->getIsAdmin()) {
                return;
            }
        }

        $src = $this->apiBase() . '/tracker.js?v=' . self::TRACKER_VERSION;

        Craft::$app->getView()->registerJsFile($src, [
            'position' => \craft\web\View::POS_HEAD,
            'defer' => true,
            'data-domain' => $this->siteDomain(),
        ], 'mxcoan-tracker');
    }

    // ─── Connect flow ─────────────────────────────────────────────────────

    /**
     * Challenge → verify handshake, triggered from the settings-save event
     * only. Throttled (one attempt per 30 s) via the Craft cache; a failing
     * verification can never slow down a page render because it never runs
     * on one.
     *
     * @return void
     */
    private function runHandshake(): void
    {
        $settings = $this->getSettings();
        $apiKey = trim((string) $settings->apiKey);

        if ($apiKey === '' || $settings->connected) {
            return;
        }

        $cache = Craft::$app->getCache();
        if ($cache->get('mxcoan_connect_lock')) {
            return;
        }
        $cache->set('mxcoan_connect_lock', true, 30);

        $session = Craft::$app->getSession();

        // Step 1: request a challenge and store it so the challenge
        // controller can serve it on the public domain.
        $challenge = $this->apiPost('/api/integrations/craft/challenge', [], $apiKey);
        if (empty($challenge['ok']) || empty($challenge['data']['challenge'])) {
            $this->persist(['challenge' => '']);
            $session->setError(Craft::t(
                'mx-cookieless-analytics',
                'MX Cookieless Analytics: could not reach MetriXs to start verification. Check your site API key and save again.'
            ));
            return;
        }

        $token = (string) $challenge['data']['challenge'];
        $this->persist(['challenge' => $token]);

        // Step 2: ask the API to verify. It fetches the challenge back from
        // the site's public domain — only a party controlling this domain
        // can serve the token.
        $verify = $this->apiPost('/api/integrations/craft/verify', [], $apiKey);
        if (!empty($verify['ok']) && !empty($verify['data']['ok'])) {
            // Connected: clear the challenge (it was single-use for the
            // handshake) and persist the state.
            $this->persist(['challenge' => '', 'connected' => true]);
            $session->setNotice(Craft::t(
                'mx-cookieless-analytics',
                'MX Cookieless Analytics: site verified. Tracking starts once "Enable tracking" is on.'
            ));
        } else {
            $this->persist(['challenge' => '']);
            $session->setError(Craft::t(
                'mx-cookieless-analytics',
                'MX Cookieless Analytics: verification failed — the plugin could not serve the challenge token on your public domain (check HTTPS and caching). Saving again retries.'
            ));
        }
    }

    /**
     * POST JSON to a MetriXs API endpoint with the stored API key.
     *
     * @param string $path   API path, e.g. '/api/integrations/craft/verify'.
     * @param mixed  $body   JSON body.
     * @param string|null $apiKey Bearer key; defaults to the stored one.
     * @return array{ok: bool, status: int, data: array|null}
     */
    private function apiPost(string $path, $body = [], ?string $apiKey = null): array
    {
        if ($apiKey === null) {
            $apiKey = (string) $this->getSettings()->apiKey;
        }

        $ch = curl_init($this->apiBase() . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        if ($response === false || $error !== '') {
            return ['ok' => false, 'status' => 0, 'data' => null];
        }

        $data = json_decode((string) $response, true);

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => is_array($data) ? $data : null,
        ];
    }

    // ─── helpers ──────────────────────────────────────────────────────────

    /**
     * Merge values into the plugin settings and persist them (public so the
     * connect controller can store the OAuth state / exchanged API key).
     *
     * @param array<string, mixed> $values
     * @return void
     */
    public function saveSettingsValues(array $values): void
    {
        $settings = $this->getSettings();
        foreach ($values as $key => $value) {
            $settings->$key = $value;
        }
        Craft::$app->getPlugins()->savePluginSettings($this, $values);
    }

    /**
     * POST JSON to a MetriXs API endpoint (public so the connect controller
     * can run the unauthenticated key exchange).
     *
     * @param string $path   API path.
     * @param mixed  $body   JSON body.
     * @param string|null $apiKey Bearer key; null = the stored one.
     * @return array{ok: bool, status: int, data: array|null}
     */
    public function postJson(string $path, $body = [], ?string $apiKey = null): array
    {
        return $this->apiPost($path, $body, $apiKey);
    }

    /**
     * The site's own origin (scheme + host), preferring the primary site's
     * base URL — the same source the domain resolution trusts (NOT the Host
     * header, which a cache in front can poison).
     *
     * @return string
     */
    public function siteOrigin(): string
    {
        try {
            $site = Craft::$app->getSites()->getPrimarySite();
            if ($site) {
                $parts = parse_url($site->baseUrl);
                if (!empty($parts['host'])) {
                    $scheme = $parts['scheme'] ?? 'https';
                    $origin = $scheme . '://' . $parts['host'];
                    if (!empty($parts['port'])) {
                        $origin .= ':' . $parts['port'];
                    }
                    return rtrim($origin . ($parts['path'] ?? ''), '/');
                }
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return 'https://' . $this->siteDomain();
    }

    /**
     * Merge key/values into the plugin settings and persist them.
     *
     * @param array<string, mixed> $values
     * @return void
     */
    private function persist(array $values): void
    {
        $settings = $this->getSettings();
        foreach ($values as $key => $value) {
            $settings->$key = $value;
        }
        Craft::$app->getPlugins()->savePluginSettings($this, $values);
    }

    /**
     * The MetriXs API base. Admin-settable, but receives the API key as a
     * Bearer token — anything that is not plain HTTPS is rejected in favour
     * of the default so the key can never be sent in the clear.
     *
     * @return string
     */
    private function apiBase(): string
    {
        $configured = rtrim((string) $this->getSettings()->apiBase, '/');
        if ($configured !== '' && stripos($configured, 'https://') === 0) {
            return $configured;
        }

        return 'https://app.metrixs.eu';
    }

    /**
     * The site domain for the data-domain attribute, in order of preference:
     * the configured domain, then the primary site's base URL host (NOT the
     * Host header, which a cache in front can poison into cached HTML), then
     * the request host as last resort.
     *
     * @return string
     */
    private function siteDomain(): string
    {
        $configured = strtolower(trim((string) $this->getSettings()->domain));
        if ($configured !== '') {
            return $configured;
        }

        try {
            $site = Craft::$app->getSites()->getPrimarySite();
            if ($site) {
                $host = strtolower((string) parse_url($site->baseUrl, PHP_URL_HOST));
                if ($host !== '') {
                    return $host;
                }
            }
        } catch (\Throwable $e) {
            // fall through
        }

        return strtolower((string) Craft::$app->getRequest()->getHostName());
    }

    // ─── Settings ─────────────────────────────────────────────────────────

    /**
     * @return Settings
     */
    protected function createSettingsModel(): Settings
    {
        return Craft::createObject(Settings::class);
    }
}
