# Clash Cards Version 8.43 — Production Deployment

This is a FULL production package for a small friends-and-family beta.

## Recommended directory layout

Best layout if Network Solutions lets you point a domain/subdomain to a folder:

    clash-cards/
        config.php                 <-- PRIVATE
        config.example.php
        src/                       <-- PRIVATE
        sql/                       <-- PRIVATE
        storage/                   <-- PRIVATE
        public/                    <-- WEB ROOT
            index.php
            admin.php
            js/
            assets/

Point the domain or subdomain document root at:

    clash-cards/public/

That is strongly preferred because config.php, PHP service classes, SQL files,
and logs are outside the public web root.

## If you cannot use a subdomain

You can use the same idea with the primary domain: point the domain's document
root at the `public/` directory if the hosting control panel permits it.

If Network Solutions only lets the site serve directly from `/htdocs`, do NOT
blindly copy config.php or src/ into a publicly reachable directory. First check
whether your hosting account gives you a private directory outside `/htdocs`.
Put this project there and point the web directory at its `public/` folder.

If the control panel will not permit either arrangement, stop there and we can
make a Network-Solutions-specific fallback package after you see exactly what
directory controls your account provides.

## 1. Choose PHP

Use PHP 8.1 or newer. PHP 8.2/8.3 is a good choice if offered.

## 2. Create the production database

Create a NEW MySQL database in Network Solutions.

Record:
- database host
- database name
- database username
- database password
- port, normally 3306

Then import:

    sql/001-bootstrap-clean-database.sql

The bootstrap creates:
- players
- cards
- player_cards
- trade_proposals
- all 60 current card definitions

It deliberately does NOT include your local test players.

If you decide you want the local player/inventory data online, export your local
database instead of using the clean bootstrap.

## 3. Create config.php

Copy:

    config.example.php -> config.php

Fill in the database values.

### Clan name / site banner

Version 8.43 adds a clan banner to the player site and Admin pages. Set the displayed
clan name in `config.php`:

    'app' => [
        'clan_name' => 'Whiskey Morning',
        'debug' => false,
        'error_log' => __DIR__ . '/storage/logs/php-error.log',
    ],

Change `Whiskey Morning` to the exact clan name you want displayed.

If `clan_name` is an empty string, the banner is hidden.

Because `config.php` is intentionally excluded from Git/FTP deployments, changing
the clan name on the hosted site is a production configuration change and will
not be overwritten by normal application deployments.

Generate the separate Admin password hash on your Mac:

    php -r "echo password_hash('YOUR-STRONG-ADMIN-PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"

Paste the resulting hash into:

    'admin' => [
        'password_hash' => '...'
    ]

Never place the plaintext admin password in config.php.

## 4. Permissions

PHP needs write access to:

    storage/logs/

It should NOT need write access to application PHP files.

Do not make the whole project world-writable.

## 5. Password-protect the beta

For the friends-and-family beta, password-protect the entire web directory using
the hosting control panel.

That outer password is for your trusted testers.

The app now has a SECOND, separate admin password protecting:
- player deletion
- player impersonation
- global optimizer admin tools

Do not give the admin password to ordinary testers.

## 6. HTTPS

Enable SSL/HTTPS before inviting testers.

After HTTPS is confirmed working, edit:

    public/.user.ini

and uncomment:

    session.cookie_secure = 1

Then allow a few minutes for PHP user-ini changes to refresh, or restart PHP if
your hosting panel provides that option.

## 7. Production error behavior

Version 8.43:
- disables PHP error display
- logs errors instead
- replaces uncaught exception details with a generic error page
- avoids showing database exception details in normal trade/admin failures

Default log location:

    storage/logs/php-error.log

If no log appears, verify that storage/logs is writable.

## 8. First live test

Before giving the URL to friends:

1. Open the HTTPS site from your phone using cellular data.
2. Confirm the outer directory password is required.
3. Select/create a normal player.
4. Scan five screenshots.
5. Confirm 5/5 pages parse and save.
6. Confirm My Cards displays the correct player.
7. Confirm Trades shows the optimized per-player graph.
8. Create one disposable trade proposal.
9. Open Admin and confirm the separate admin password is required.
10. Test "Log in as this player".
11. Confirm Optimized Trading loads.
12. Delete a disposable test player.
13. Confirm direct URLs cannot expose config.php, src/, sql/, or storage/.

## 9. Tesseract dependency

The scanner currently loads Tesseract.js from jsDelivr over HTTPS.

This means testers' browsers need internet access to that CDN. The actual card
screenshots are processed client-side by the scanner code; Version 8.43 does not add a
server-side screenshot-upload archive.

If you later want the scanner to work without a third-party CDN, we can vendor
the Tesseract browser files locally in a future release.

## 10. Updating after Version 8.43

Version 8.43 is a full baseline production package.

Future releases can go back to the small patch ZIP workflow:
only changed files, with their correct project-relative paths.

Do not overwrite production config.php with a template during updates.

## Build versions

Production package: Version 8.43