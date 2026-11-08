# 2. Installation

Install SEO Control Center on a **dedicated subdomain**, for example **seo.example.com**. Keep the folder cPanel creates for it. The guided installer handles the application layout automatically; you do not rename an application folder or change the domain path.

## Before you begin

Back up your hosting account. You need PHP 8.5 or newer, MySQL 8+ or MariaDB 10.6+, a valid HTTPS certificate and Cron Jobs access. The launcher also requires cURL, ZIP, sodium, PDO MySQL, mbstring, OpenSSL, filter and zlib, outbound HTTPS, private PHP temporary storage and at least 400 MB free temporary space.

The default-folder flow supports Apache or compatible LiteSpeed hosting with .htaccess and mod_rewrite. The launcher verifies public routing and private-file protection over HTTPS before installing application files. It does not overwrite an existing site. Installation in a website folder such as example.com/seocc is not supported.

## Step 1: Create your subdomain

In **cPanel → Domains → Create a New Domain**:

1. Enter your chosen subdomain, such as **seo.example.com**.
2. Turn off **Share document root** if it appears.
3. Keep the suggested document root and click **Submit**.
4. Note the folder listed next to this subdomain. That is where you upload the installer.

Older cPanel versions may have a separate Subdomains tool. If DNS is managed elsewhere, configure the subdomain at that provider using your hosting server's address. Wait until DNS resolves.

## Step 2: Enable PHP and HTTPS

1. Open **MultiPHP Manager**, select the subdomain, choose **PHP 8.5** or newer and click **Apply**.
2. Your host may use **Select PHP Version** instead. Enable required extensions there; the installer lists anything missing.
3. Open **SSL/TLS Status** or **SSL/TLS Certificates**. Enable AutoSSL or ask your host to install a valid certificate.
4. Open your subdomain over HTTPS. Continue once the browser accepts the certificate.

cPanel may create a PHP-handler .htaccess and a .well-known SSL challenge folder. The launcher preserves recognized PHP handlers and SSL challenge files; it rejects unrelated custom routing rules.

## Step 3: Create an empty database and user

Open **Database Wizard** (older label: MySQL Database Wizard):

1. Create a new database, for example with suffix **seocc**.
2. Create a dedicated database user. Generate a strong password and keep it privately.
3. Select **ALL PRIVILEGES** for that user on this database and finish the wizard.
4. Keep the **full database name**, **full user name** and **password**, including any cPanel prefix such as youruser_.

If you use Manage My Databases instead, also add the user to the database and grant privileges. Use a new database dedicated to SEOcc.

## Step 4: Upload SEOcc

1. Download **scc-<version>-install.zip** from the [latest release](https://github.com/seoccnet/seo-control-center/releases/latest). The file ending in `-install.zip` is the one for new installations.
2. In File Manager, open the document root shown for your new subdomain. The folder must be empty apart from recognized hosting files. Remove a host's placeholder page only after confirming it belongs to this new subdomain.
3. Upload the zip into that folder, select it and choose **Extract**.
4. Open the new **seo-control-center** folder. Turn on **Show hidden files** in File Manager's settings, select everything, and **Move** it up one level into the subdomain's folder.
5. Delete the zip and the empty **seo-control-center** folder.
6. Open **https://seo.example.com/version.json**. It must answer **403 Forbidden**. If you see the file's contents, stop: your host isn't applying `.htaccess`, and private files could be reachable. Ask your host to enable `.htaccess` and `mod_rewrite`.
7. Open **https://seo.example.com**. The installation wizard appears.

There is no document-root change. The `.htaccess` file sends visitors to the `public` folder and blocks the application's private folders.

## Step 5: Follow the workspace wizard

1. **Welcome:** review the hosting checks.
2. **Site & access:** confirm the detected HTTPS subdomain address, and enter the **server setup key**. Copy it from `storage/setup-key.txt` in File Manager. It proves that you control the server's files.
3. **Database:** enter the full database name, database user and password. Usually the host is localhost and the port is 3306.
4. **Your account:** enter your name, email, a password of at least 12 characters and your company or agency name for the first workspace.
5. **Review & install:** review your details and choose the cron interval your host allows. Every five minutes is recommended. Click **Install SEO Control Center** and keep the page open.

The database is checked before setup writes configuration or creates tables. If validation fails, non-password fields are kept; enter passwords again before retrying. Without JavaScript, all sections remain available in one form.

## Step 6: Add cron and finish

1. Keep the completion page open. It shows the schedule and command generated for your actual installation path.
2. Open **cPanel → Cron Jobs**. Choose the same interval you selected in SEOcc. Every five minutes is **Minute: */5**, with **Hour, Day, Month and Weekday: ***.
3. Copy the **command from the completion page** into Command and click **Add New Cron Job**. If your host uses a different PHP executable, ask for its PHP 8.5 command-line path. Do not add duplicate jobs.
4. The wizard saves CRON_INTERVAL_MINUTES automatically. No .env edit is needed for this new-install flow.
5. Download the encryption key from the exact path shown on the completion page and keep an offline copy. Database backups do not contain it.
6. Keep the application's .htaccess files.
7. Sign in.
8. After the first scheduled run, check **System → Health → Cron**. For five-minute cron, allow about 5–10 minutes.

## Recommended: Configure email

Under **System → E-mail**, enter your SMTP settings and send a test message. Your host's Email Accounts → Connect Devices screen provides the outgoing mail settings. This enables invitations and password resets.

Then connect your integrations and add your first project.

## Existing installations

Existing SEOcc workspaces use **System → Updates**, following the [backup and update guide](10-backups-and-updates.md).
