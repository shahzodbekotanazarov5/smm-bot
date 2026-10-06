# SMM Panel Telegram Bot

PHP + MySQL Telegram bot for reselling social-media engagement services (followers/likes/views/reactions) through an upstream "SMM panel" provider API. Built for ordinary shared/cPanel hosting: Telegram **webhook** (no long-running process) + **cPanel cron** for background jobs.

Ko'p tillilik: O'zbekcha / Русский / English. Admin panel — botning o'zida (Telegram orqali), alohida veb-panel yo'q.

## Requirements

- PHP 8.1+ with `pdo_mysql`, `curl`, `json`, `mbstring` extensions.
- MySQL 5.7+ / MariaDB 10.3+ (needs `GET_LOCK`/`RELEASE_LOCK`, standard on shared hosting).
- A domain/subdomain with HTTPS (Telegram requires HTTPS for webhooks).
- A Telegram bot token from [@BotFather](https://t.me/BotFather).
- An upstream provider account using the standard SMM-panel API (`key` + `action`: `services`/`add`/`status`/`balance`).

## Directory layout

```
config/        bot token, DB credentials, admin bootstrap list — NOT web-accessible
src/           application code (Core/, Services/, Handlers/, Support/, Migrations/)
lang/          uz.php / ru.php / en.php — all user-facing strings
logs/          app.log, php_errors.log — NOT web-accessible
public_html/   point your domain's document root here — the only web-accessible folder
```

If your host only lets you use a single `public_html`-style root for the whole account (can't point the domain elsewhere), upload everything as-is anyway — `config/`, `src/`, `lang/`, and `logs/` each ship with a `.htaccess` that denies all HTTP access, so they stay protected either way.

## Local testing (no Telegram account, no hosting needed)

`dev/simulate.php` is a browser-based chat that looks and behaves like Telegram, but feeds updates straight into the bot's real code (`App\Core\UpdatePipeline` — the exact same gate-and-dispatch logic the real webhook uses). It's how this whole bot was verified end-to-end before being handed to you: catalog creation, ordering, wallet math, and the status-sync cron were all driven through it against a real local MySQL.

It only runs under PHP's built-in dev server (refuses to start any other way — see the comment at the top of the file) and lives outside `public_html`, so it's never reachable if you deploy normally. **Never upload the `dev/` folder to production hosting.**

1. You need a local MySQL (e.g. Docker: `docker run -d --name smm-bot-mysql -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=smm_bot -e MYSQL_USER=smm_bot_user -e MYSQL_PASSWORD=smm_bot_pass -p 3307:3306 mysql:8.0`, or XAMPP/Laragon on the default port).
2. `config/config.php` already exists in this project pre-filled for **local testing**: MySQL on `127.0.0.1:3307`, a test admin ID (`777000111`), and a `default_provider` pointing at `dev/fake_provider.php` (a tiny fake upstream that fills in for a real SMM provider so orders/status-sync can be tested without a real provider account). Replace all of it with your real values before deploying — it's clearly marked at the top of the file.
3. Run `php public_html/install.php` once (creates tables, seeds settings/admin/the fake provider).
4. Start two PHP dev servers — one for the bot, one standing in as the "external" provider (they must be on **different ports**; the built-in server is single-threaded, so the bot calling out to a provider running on the *same* server would deadlock):
   ```bash
   php -S 127.0.0.1:8000 -t dev    # the bot + simulator
   php -S 127.0.0.1:8001 -t dev    # the fake provider, standing in for a real one
   ```
5. Open **http://127.0.0.1:8000/simulate.php**. Use "👤 Yangi foydalanuvchi" for a regular user or "🛠 Admin sifatida" (uses the ID from `admin_bootstrap_ids`) to test the admin panel. You'll need to add at least one category → section → service from the admin panel before there's anything to order.
6. To test the status-sync cron: place an order, then drive the fake order's status manually — `http://127.0.0.1:8001/fake_provider.php?action=simulate_progress&order=1&status=Completed` (or `Partial&remains=50`, or `Canceled`) — then run `php public_html/cron_status_sync.php` and watch the refund/notification happen for real in the DB.

## Install

1. Upload the whole `smm-bot/` folder to your hosting account (via FTP/File Manager), or `git clone` it there.
2. Point your domain/subdomain's document root at `smm-bot/public_html`. If that's not possible on your host, just make sure the domain resolves into the `smm-bot/` folder and always link to `public_html/webhook.php` etc. — the `.htaccess` files keep the rest locked down either way.
3. Create a MySQL database and user in your hosting control panel.
4. Copy `config/config.sample.php` to `config/config.php` and fill in:
   - `bot.token` — from @BotFather.
   - `bot.webhook_secret` — any long random string (e.g. generate with `php -r "echo bin2hex(random_bytes(32));"`).
   - `db.*` — your MySQL credentials.
   - `admin_bootstrap_ids` — your own Telegram numeric ID(s) (get it from [@userinfobot](https://t.me/userinfobot)). Whoever is listed here becomes a `super_admin` on install.
   - `default_provider.*` — optional, your SMM provider's API URL/key (you can also add providers later from the bot's admin panel).
   - `cron_token` — another random string, only needed if your host can't run cron via CLI (see below).
5. Visit `https://yourdomain.com/install.php?confirm=yes` once (or run `php public_html/install.php` via SSH/cPanel Terminal if available). This creates all tables and seeds default settings + your admin account.
6. Set the Telegram webhook:
   ```bash
   curl -F "url=https://yourdomain.com/webhook.php" \
        -F "secret_token=YOUR_webhook_secret_FROM_CONFIG" \
        "https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook"
   ```
   Verify with `https://api.telegram.org/bot<YOUR_BOT_TOKEN>/getWebhookInfo`.
7. Message your bot with `/start`. Choose a language, then `/admin` (or the "🛠 Admin panel" button) to open the admin menu.

## Cron jobs (cPanel → Cron Jobs)

Two background jobs need to run periodically. Prefer CLI invocation (not publicly reachable, no HTTP overhead):

```
*/3 * * * * php /home/YOURUSER/smm-bot/public_html/cron_status_sync.php >/dev/null 2>&1
*/2 * * * * php /home/YOURUSER/smm-bot/public_html/cron_broadcast.php  >/dev/null 2>&1
```

If your host only offers URL-based cron (no CLI PHP), use `wget`/`curl` with the `cron_token` from your config instead:

```
*/3 * * * * wget -q -O /dev/null "https://yourdomain.com/cron_status_sync.php?token=YOUR_cron_token"
*/2 * * * * wget -q -O /dev/null "https://yourdomain.com/cron_broadcast.php?token=YOUR_cron_token"
```

Both scripts take a non-blocking MySQL lock, so overlapping runs are safe (a run that's still busy just skips).

## Using the bot

- **Users**: `🛍 Xizmatlar` to browse and order, `🛒 Buyurtmalarim` for order history, `💰 Balansim` for balance + top-up instructions, `☎️ Qo'llab-quvvatlash` to open a support ticket, `🌐 Til` to change language.
- **Admins** (`/admin`): catalog management (categories → sections → services, each with a provider link and computed price), providers, users (search by Telegram ID, ban/unban, manual balance credit/debit — this is the only top-up path in v1), support tickets, broadcast messages, and settings (markup %, USD→local conversion rate, bot on/off).
- Adding a service asks for: provider → provider's own service ID → provider's rate (assumed USD per 1000) → names in uz/ru/en → min/max quantity. The final local price is `rate × usd_to_local_rate × (1 + markup%)`, computed once and stored — the same formula the admin catalog wizard and a future price-sync job would both use, so pricing never drifts between entry points.

## Known v1 limitations (by design, not oversights)

- **No automatic payment gateway.** Balance top-up is admin-reviewed-and-credited only (user sends a payment screenshot, admin taps ➕ on their profile). The `wallet_transactions` ledger already has a `topup` reason and `reference_type`/`reference_id` columns, so wiring in Click/Payme/etc. later doesn't need a schema change — just a new handler that calls `WalletService::credit(..., 'topup', ...)` from the gateway's webhook.
- **`order_type` is `default` or `poll` only** — a `package`-type service (multiple sub-items in one order) is a documented ENUM value but has no order flow built yet, since both reference codebases had this half-implemented too. New services default to `default`/`url`; an admin can flip a service to `poll`/`username` from its detail screen in the admin panel.
- **`admin_contact` and `support_group_chat_id` settings** are seeded from `install.php` but not yet editable from the bot UI — update them directly in the `settings` table (`UPDATE settings SET value = '@your_username' WHERE \`key\` = 'admin_contact';`) until a settings screen for them is added.
- **No web dashboard** — everything is bot-only, as requested. `app.log` / `php_errors.log` under `logs/` are your operational visibility.
- **No virtual-number / SMS-verification-bypass feature.** The reference codebases this project was learned from had a "buy a virtual phone number to receive account-verification SMS codes" feature; it was deliberately left out because it exists specifically to help create bulk fake/verified accounts, bypassing platforms' anti-abuse checks — that's out of scope for a legitimate SMM reselling panel.

## Security notes

- All database queries go through parameterized PDO statements (`src/Core/Database.php`) — never string-concatenated SQL.
- `users.balance` is only ever mutated by `WalletService::credit()/debit()`, each an atomic `UPDATE ... WHERE balance >= ?` inside a transaction, with every change logged to `wallet_transactions`.
- TLS certificate verification is always on for outbound HTTP (`src/Core/Http.php`) — both to Telegram and to your provider.
- The webhook checks Telegram's `X-Telegram-Bot-Api-Secret-Token` header before touching the database.
- `config/`, `src/`, `lang/`, `logs/` are all denied at the web server level via `.htaccess`.
- Rotate `bot.webhook_secret`, `cron_token`, and your provider API key if this codebase (or its `config.php`) is ever exposed publicly.
