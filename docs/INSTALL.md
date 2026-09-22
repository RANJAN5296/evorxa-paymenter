# Installation guide

This takes about 10 minutes. You need a Paymenter 1.x admin account and an Evorxa account.

## Before you start

| Requirement | Why |
|---|---|
| Paymenter 1.x (PHP 8.3+) | Uses the Paymenter server-extension API |
| `php artisan schedule:run` every minute | Finishes new servers, sends the ready email, checks renewals, stock and the wallet |
| A running queue worker | Paymenter creates servers and sends emails from the queue |
| Evorxa wallet balance | Servers and renewals are paid from your Evorxa wallet |

> **Tip:** create a dedicated project in the Evorxa dashboard (for example "Resale") before you start. Client servers are created there, so they never mix with your own servers.

## 1. Install the extension

**Without SSH:** download [evorxa-paymenter.zip](https://github.com/ScaleBit-Technologies/evorxa-paymenter/releases/latest/download/evorxa-paymenter.zip), then in Paymenter open **Admin > Extensions**, click **Upload** and choose the zip.

**With SSH**, one command downloads the latest release and installs it:

```bash
curl -fsSL https://raw.githubusercontent.com/ScaleBit-Technologies/evorxa-paymenter/master/install.sh | bash -s -- /var/www/paymenter
```

Use your Paymenter folder (the one that contains `artisan`). Add `PHP_BIN=/usr/bin/php8.3` before `bash` if `php` is not the PHP that runs Paymenter.

Either way, only `extensions/Servers/Evorxa` is added. Restart the queue worker afterwards (`systemctl restart paymenter`, or your supervisor program).

## 2. Create an Evorxa API token

In the Evorxa dashboard open **API tokens** and create a token with these abilities:

```
instances:read instances:write analytics:read shield:read shield:write wallet:read
```

Copy the token (it is shown only once).

## 3. Add the Evorxa server

1. Go to **Admin > Servers > New server**.
2. Name it (for example "Evorxa"), choose the extension **Evorxa**, paste the token into **API token** and leave the hostname as `api.evorxa.com`.
3. Save, then click **Test connection**. If a token ability is missing, the message tells you which.

Paymenter stores the token encrypted. If you created the server before the token field was filled, open the server once and save it again so the token is stored encrypted.

## 4. Settings

Open **Admin > Evorxa Cloud > Settings**:

![Settings](screenshots/24-admin-settings.png)

- **Evorxa project for client servers**: the project new servers are created in.
- **Hostname suffix**: your domain, for example `example.com`. Servers are named `vps123.example.com` (or the name the client chose); this also becomes the reverse DNS, so your brand shows instead of Evorxa's.
- **Client control panel**: switch individual features on or off, and the checkout picker.
- **Alerts**: wallet threshold and build timeout. Alerts go to the **System Email Address** (Admin > Settings > Mail).

## 5. Import plans

Open **Admin > Evorxa Cloud > Plans & Import**:

![Plans & Import](screenshots/21-admin-plans.png)

1. Tick the plans you want to sell.
2. Choose a category (or type a new category name).
3. Set your **markup** (for example 40 %) and **rounding** (x.99 is the default).
4. Choose the billing terms to sell (monthly, every 6 months, yearly).
5. Keep *Create products hidden* ticked, then **Import selected plans**.

Each product gets prices in every currency (converted with Evorxa's exchange rates), the Evorxa server settings (plan, default OS, upstream term), stock and upgrade paths to bigger plans. Clients choose the operating system or a one-click app and a hostname at checkout automatically. Review the products in **Admin > Products**, then untick **Hidden** to publish.

You can also make a product by hand: **Admin > Products > New**, set the server to your Evorxa server, then pick the plan and default OS in the server settings.

## 6. Cron and queue

Paymenter needs both (they are part of its own installation guide):

```
* * * * * php /var/www/paymenter/artisan schedule:run >> /dev/null 2>&1
```

and a queue worker (`php artisan queue:work`) kept running by systemd or supervisor. The extension adds its own task (`evorxa:tick`, every minute) to the scheduler automatically.

## Done

Open **Admin > Evorxa Cloud**. When every item under *Setup* is green you are ready to sell.

![Dashboard](screenshots/20-admin-dashboard.png)

## Updating

Upload the new zip in **Admin > Extensions**, or run the install command from step 1 again (it backs up the current copy first). Settings, servers and logs are kept; database changes are applied automatically. Restart the queue worker afterwards.

## Uninstalling

1. Terminate (or unlink) every Evorxa service first, so no server keeps billing your wallet.
2. Delete the Evorxa server in **Admin > Servers** and the `extensions/Servers/Evorxa` folder.
3. The tables `evorxa_services`, `evorxa_log` and `evorxa_settings` are kept on purpose; drop them yourself if you no longer need them.

## Installation troubleshooting

| Symptom | Fix |
|---|---|
| *Test connection* says the token was rejected | The token was mistyped or revoked - create a new one. |
| "token is missing: ..." | Edit the token's abilities in the Evorxa dashboard (step 2). |
| Product server settings show "Could not load Evorxa plans" | Check the server's token with *Test connection*. |
| Dashboard says the scheduler never ran | Add the cron line (step 6). |
| Servers stay "setting up" and no email arrives | The queue worker is not running, or it needs a restart after an update. |
| The client panel shows old texts or styles | Clear caches: `php artisan optimize:clear`, then reload. |
