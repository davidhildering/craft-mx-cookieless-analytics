<?php
/**
 * Settings model for MX Cookieless Analytics.
 *
 * Craft auto-generates the settings page from these public attributes
 * (text inputs for strings, lightswitches for booleans).
 *
 * @license MIT License
 */

namespace metrixs\mxcookielessanalytics\models;

use Craft;
use craft\base\Model;

class Settings extends Model
{
    /**
     * Master switch — the tracker is only injected when the site is
     * connected AND this is on.
     */
    public bool $enabled = false;

    /**
     * Site-scoped API key (mtx_live_…) from the MetriXs dashboard.
     */
    public string $apiKey = '';

    /**
     * MetriXs endpoint. HTTPS only; anything else falls back to the default.
     */
    public string $apiBase = 'https://app.metrixs.eu';

    /**
     * Site domain used for tracking. Blank = auto-detect from the primary
     * site's base URL.
     */
    public string $domain = '';

    /**
     * Skip tracking for users with admin access.
     */
    public bool $excludeAdmins = true;

    /**
     * Internal state — set by the connect flow, not editable by hand.
     */
    public bool $connected = false;

    /**
     * Internal state — the verification challenge, only stored while a
     * handshake is in flight.
     */
    public string $challenge = '';

    /**
     * Internal state — the one-click connect CSRF/state token, only stored
     * between clicking "Connect with MetriXs" and the dashboard callback
     * (single-use, 15-min TTL, cleared before any use).
     */
    public string $oauthState = '';

    /**
     * Internal state — unix timestamp after which $oauthState is invalid.
     */
    public int $oauthExpires = 0;

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'enabled' => Craft::t('mx-cookieless-analytics', 'Enable tracking'),
            'apiKey' => Craft::t('mx-cookieless-analytics', 'Site API key'),
            'apiBase' => Craft::t('mx-cookieless-analytics', 'MetriXs URL'),
            'domain' => Craft::t('mx-cookieless-analytics', 'Site domain'),
            'excludeAdmins' => Craft::t('mx-cookieless-analytics', 'Exclude admins'),
            'connected' => Craft::t('mx-cookieless-analytics', 'Connected'),
            'challenge' => Craft::t('mx-cookieless-analytics', 'Verification challenge'),
            'oauthState' => Craft::t('mx-cookieless-analytics', 'One-click connect state'),
            'oauthExpires' => Craft::t('mx-cookieless-analytics', 'One-click connect expiry'),
        ];
    }

    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            [['enabled', 'excludeAdmins', 'connected'], 'boolean'],
            [['apiKey', 'apiBase', 'domain', 'challenge', 'oauthState'], 'string'],
            [['oauthExpires'], 'integer'],
            [['apiKey'], 'match', 'pattern' => '/^[A-Za-z0-9_\-]*$/'],
            [['apiKey'], 'default', 'value' => ''],
            [['domain'], 'match', 'pattern' => '/^[a-z0-9.\-]*$/'],
        ];
    }
}
