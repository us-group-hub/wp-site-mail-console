# WP Site Mail Console

[![WordPress 6.0+](https://img.shields.io/badge/WordPress-6.0%2B-21759b.svg)](https://wordpress.org)
[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)](https://www.php.net)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](LICENSE)

Ultra-lightweight, single-file WordPress plugin that replaces bloated SMTP suites (`WP Mail SMTP`) with a **zero-bloat Brevo REST API v3 `pre_wp_mail` transport** plus a built-in **WordPress Admin Inbox / Sent / Trash Mail Console** for receiving and replying to domain emails directly inside `/wp-admin`.

---

## Key Features

- **Zero-Bloat Outbound Delivery (`pre_wp_mail`)**: Intercepts WordPress `wp_mail()` and sends transactional emails directly over HTTPS via **Brevo Transactional API v3** (`https://api.brevo.com/v3/smtp/email`) — no PHP SMTP sockets, no blocked port `587`/`465`, no upsell banners, no extra database bloat.
- **Built-In Admin Mail Inbox**: Full **Inbox / Sent / Trash** interface right inside WordPress Admin with search, reply threading (`In-Reply-To`), unread badges, and automatic **Contact Form 7** submission capture.
- **HMAC-SHA256 Signed Inbound Webhook**: Exposes `POST /wp-json/site-mail/v1/inbound` authenticated with constant-time `hash_equals()` verification on the `X-Site-Mail-Sig` header — pairs seamlessly with [`cloudflare-email-webhook-router`](https://github.com/us-group-hub/cloudflare-email-webhook-router) for **free inbound email on your custom domain**.
- **Clean Architecture**: Single PHP file, automatic custom DB table creation on activation, WordPress nonces + `manage_options` capability checks on every admin action.

---

## Installation

1. Download `wp-site-mail-console-1.2.0.zip` from **[Releases](https://github.com/us-group-hub/wp-site-mail-console/releases)**.
2. In WordPress Admin go to **Plugins → Add New → Upload Plugin**, upload the ZIP, and click **Activate**.
3. Open **Site Mail** in the admin sidebar:
   - Paste your **Brevo API v3 key** (`<your-brevo-api-v3-key>`) and sender address (e.g. `support@yourdomain.com`).
   - Copy the generated **Webhook Secret** into your Cloudflare Email Worker (`SECRET_PRIMARY`) to receive inbound emails directly in WP Admin.

---

## Companion Inbound Router

Pair this plugin with **[`us-group-hub/cloudflare-email-webhook-router`](https://github.com/us-group-hub/cloudflare-email-webhook-router)** to route `support@yourdomain.com` and catch-all `*@yourdomain.com` emails into your WordPress Inbox for `$0/month` via Cloudflare Email Routing.

---

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
