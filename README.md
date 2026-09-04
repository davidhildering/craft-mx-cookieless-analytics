# MX Cookieless Analytics for Craft CMS

Cookieless, privacy-friendly web analytics for [Craft CMS](https://craftcms.com). The official MetriXs plugin.

- **No cookies, no consent banner.** MetriXs sets no cookies and stores no personal data or persistent identifiers, so no consent is required under the ePrivacy Directive / GDPR cookie rules.
- **Opt-in, off by default.** Installing the plugin loads nothing and sends nothing. Tracking starts only after you paste your site API key and enable tracking.
- **Automatic verification.** No DNS records, no template edits: the plugin proves domain ownership to MetriXs itself.
- **EU-hosted.** Data lives on MetriXs servers in Germany and never leaves the EU.
- **Admin exclusion.** Users with admin access are excluded by default, so your own visits never pollute your stats. Regular logged-in users are still tracked.

## Installation

Install via Composer from your Craft project root:

```sh
composer require metrixs/mx-cookieless-analytics
```

or install it from the **Plugin Store** inside the Craft control panel.

Then install the plugin itself:

```sh
php craft plugin/install mx-cookieless-analytics
```

## Setup

1. Create a free account at [app.metrixs.eu](https://app.metrixs.eu) and add your site.
2. Create a site API key in the MetriXs dashboard: **Settings → Sites → API keys**.
3. In the Craft control panel, go to **Settings → Plugins → MX Cookieless Analytics**:
   - paste the **Site API key**
   - switch **Enable tracking** on
   - **Save**
4. The plugin verifies your site automatically during the save. When the page shows "site verified", tracking is live.

Your dashboard is at [app.metrixs.eu](https://app.metrixs.eu): visitors, pageviews, sources, geography, devices, bounce rate, and more.

## Configuration

| Setting | Default | Description |
|---|---|---|
| `enabled` | `false` | Master switch. Nothing is injected until this is on **and** the site is connected. |
| `apiKey` | — | Site-scoped API key (`mtx_live_…`) from the MetriXs dashboard. Grants analytics access for this one site only, revocable at any time. |
| `apiBase` | `https://app.metrixs.eu` | MetriXs endpoint. HTTPS only; only change for self-hosted setups. |
| `domain` | *(auto)* | Site domain used for tracking. Leave empty to auto-detect from the primary site's URL. |
| `excludeAdmins` | `true` | Skip tracking for users with admin access. Regular logged-in users are tracked. |
| `connected` | `false` | Internal state, set by the verification flow. |
| `challenge` | — | Internal state, set by the verification flow. |

The tracker script is served from `app.metrixs.eu` with a versioned URL, registered in the page head and loaded asynchronously (`defer`). It works fine alongside caching and CDN setups.

## Disconnecting

Turn off **Enable tracking** to pause instantly. To disconnect the site entirely, revoke the API key in the MetriXs dashboard (**Settings → Sites → API keys**); ingestion stops immediately.

## Privacy

What MetriXs does **not** do: no cookies, no visitor IP storage (daily-rotating salted hash only), no cross-day visitor identification, no data outside the EU. Details: [metrixs.eu/data-policy](https://metrixs.eu/data-policy).

## License

MIT. See [LICENSE.md](LICENSE.md).
