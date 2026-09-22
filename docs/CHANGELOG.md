# Changelog

## 1.0.0 - 2026-09-22

First release, under the MIT license.

- Paymenter server extension "Evorxa": automatic provisioning with idempotent creates (write-ahead record, no POST retry, adoption by name), suspend/unsuspend/terminate with prorated refund/upgrade, renewal keeper (upstream suspension, end-of-period cancellations, scheduled deletions, auto-renew off), project fence, "server ready" notification.
- Client panel ("Server" view): power, credentials with reveal/reset, IPs with reverse DNS editing, usage graphs, DDoS view and alerts, network firewall with drag-and-drop rule order, reinstall with OS or one-click apps; follows the Paymenter theme (light and dark), English and Arabic.
- Checkout: operating system or one-click app picker with logos, optional hostname.
- Admin > Evorxa Cloud: dashboard with setup checklist and monthly economics, plans & import with markup and rounding (every currency, upgrade paths, stock), servers and per-service actions, activity log, settings, wallet and build alerts, hourly stock sync.
- Installs by zip upload (Admin > Extensions) or `install.sh`; documentation with screenshots.
