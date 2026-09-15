# 1.1.1
## 2026-09-16

1. [](#new)
    * Updated the bundled tracker script (v1.2.0): custom-event properties that look like personal data (PII) are now removed automatically before they are stored, and property limits (max 30 props, key <= 300 chars, value <= 2000 chars) are enforced. Privacy: MetriXs still stores no personal data.

# 1.1.0
## 2026-09-14

1. [](#new)
    * One-click connect: a "Connect with MetriXs" alert in the control panel (while not connected) opens the MetriXs dashboard, where you create (or open) your account and the site is added, connected and verified automatically. No API key copying. The manual API-key flow works unchanged.

# 1.0.0
## 2026-09-02

1. [](#new)
    * Initial release: opt-in tracker injection (off until you connect), automatic site verification via challenge/response (no DNS records needed), admin exclusion, Craft 5 support.
