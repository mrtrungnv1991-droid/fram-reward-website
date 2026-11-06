# Rewards Farm Manager — website source

This repository contains the PHP, JavaScript, CSS and HTML source mirrored from `game4win.net/public_html` on 2026-11-06. It does not include hosting credentials, database contents, account credentials, browser profiles, or the private Controller configuration.

The V7 dashboard uses `bridge-v7.php` to call a separate HTTPS Controller. Deploying these files alone does not start that Controller or a physical host agent. See the architecture and deployment documents in the local `Fram Reward/website` folder before configuring a new environment.

Do not commit `config.php`, the hosting `secure` directory, `data`, exports, logs, or database backups.

The legacy V4/V5 dashboard files and `bridge-v5.php` have been removed. `index.php` remains the PHP login and mandatory password-change entry point, and redirects signed-in users to `dashboard-v7.php`. The `api_v4_*` and `api_v5_*` files remain because they are data endpoints, not the old interface. Run `node scripts/check-legacy-ui.mjs` to verify the legacy UI cannot reappear; GitHub Actions also checks JavaScript and PHP syntax.
