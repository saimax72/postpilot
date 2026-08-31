# PostPilot

Schedule social media posts across every platform from one calendar.

A self-hosted PHP/MySQL app: users sign up, connect their social accounts, write a
post once, tick the channels it should go to, and drop it on a calendar. A cron
worker publishes each post when its moment arrives. Administrators get a separate
backend covering every user, post, connected account and action taken in the app.

Built for Hostinger shared hosting — no Composer, no Node build step, no framework.
Plain PHP 8, PDO/MySQL, one CSS file and one JS file.

---

## What's in the box

| Area | Files |
|---|---|
| Marketing / landing page | `index.php` |
| Auth | `login.php`, `register.php`, `logout.php` |
| Calendar (month + week, drag & drop) | `dashboard.php` |
| Queue / list view with status filters | `queue.php` |
| Instagram-style grid preview | `grid.php` |
| Connect & manage social accounts | `accounts.php` |
| Profile, timezone, password | `settings.php` |
| Admin backend | `admin/index.php`, `admin/users.php`, `admin/user.php`, `admin/posts.php`, `admin/activity.php` |
| JSON API used by the composer | `api/posts.php` |
| Publishing worker (cron) | `cron/publish.php` |
| Installer | `install.php` |
| Core library | `app/*.php` |
| Design system | `assets/css/app.css`, `assets/js/app.js` |

### Supported networks

Facebook, Instagram, X (Twitter), LinkedIn, Threads, TikTok, YouTube and Pinterest.
Each has its character limit and media rules enforced in the composer.

Publishing **drivers** are built in for Facebook, Instagram, Threads, LinkedIn and X.
TikTok, YouTube and Pinterest are registered in the UI and schedule normally, but need
a driver written in `app/publisher.php` before they will post for real — each one's API
docs link is in the platform registry.

---

## Install on Hostinger

### 1. Create the database

hPanel → **Databases → MySQL Databases**. Create a database and a user, and note down
the database name, username and password (Hostinger prefixes both with `u0000000_`).

### 2. Upload the files

hPanel → **Files → File Manager**, or FTP. Everything goes in `public_html`:

```
public_html/
├── index.php  login.php  register.php  dashboard.php  queue.php …
├── admin/  api/  app/  assets/  cron/  storage/  uploads/
└── schema.sql  install.php  .htaccess
```

Make sure `uploads/` and `storage/` are writable (permissions `755`).

### 3. Fill in `app/config.php`

```php
define('DB_NAME', 'u123456789_postpilot');
define('DB_USER', 'u123456789_ppuser');
define('DB_PASS', 'your-database-password');
define('APP_URL', 'https://yourdomain.com');   // no trailing slash

define('APP_KEY',     '…32+ random characters…');  // encrypts stored API tokens
define('CRON_SECRET', '…another random string…');  // guards the HTTP cron trigger
```

Generate the two secrets with any password generator, or run:

```bash
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
```

Set `APP_DEBUG` to `false` once everything works.

### 4. Run the installer

Visit `https://yourdomain.com/install.php`. It checks the server, imports
`schema.sql`, and creates the first administrator account.

**Delete `install.php` when it is finished.**

### 5. Add the cron job

hPanel → **Advanced → Cron Jobs** → every minute:

```
/usr/bin/php /home/uXXXXXXX/domains/yourdomain.com/public_html/cron/publish.php
```

Without this, posts sit on the calendar and never go out. The admin overview warns you
when posts are piling up past their due time, and `Run publisher now` on that page
triggers a run by hand.

If you would rather trigger it over HTTP:

```
curl -s "https://yourdomain.com/cron/publish.php?key=YOUR_CRON_SECRET"
```

---

## Connecting a real social account

Every network requires its own developer app before it will accept posts from
third-party software. The flow is the same for each:

1. Create an app on the network's developer portal.
2. Request the publishing permission (`accounts.php` lists which one per network).
3. Generate a long-lived access token for the page or profile.
4. In PostPilot → **Accounts → Connect account**, expand *Add API credentials* and paste
   the token plus the page / account ID.

Tokens are encrypted with `APP_KEY` before they are written to the database.

**Demo mode.** An account connected without a token still works everywhere in the app —
it appears in the composer, posts schedule against it, and the worker marks them
published at the right moment. Nothing is sent to the network. This lets you build out a
calendar while your developer apps are still in review.

---

## How scheduling works

* Times are stored in **UTC** and displayed in each user's own timezone (set at signup,
  changeable in Settings). MySQL's session timezone is pinned to `+00:00` so `NOW()`
  agrees with PHP.
* `cron/publish.php` takes a file lock, then claims each due post with a conditional
  `UPDATE … WHERE status='scheduled'` before touching any API — two overlapping runs
  cannot double-post.
* A post that fails retries on the next run, up to three attempts, then lands in the
  **Failed** filter with the network's error message attached. Admins can requeue it.
* A post going to three networks succeeds or fails per network. It is only marked
  published once every target has gone out.

---

## Security notes

* Passwords hashed with `password_hash()` (bcrypt), rehashed on login when the
  algorithm's cost changes.
* CSRF token on every state-changing form and API call.
* Login throttled to 8 attempts per email/IP per 15 minutes.
* Session cookie is `HttpOnly`, `SameSite=Lax`, and `Secure` over HTTPS; the session ID
  is regenerated on login.
* Every query is a prepared statement.
* `app/` and `storage/` are denied by `.htaccess`; `uploads/` refuses to serve
  executable extensions and uploaded files are validated by MIME type, not filename.
* Media paths are namespaced per user and checked against the session user on save.
* Suspending a user blocks sign-in on their next request, not just at the login form.

---

## Local development

Any PHP 8 environment with MySQL works. From the project root:

```bash
php -S localhost:8000
```

Then open `http://localhost:8000/install.php`. Set `APP_URL` to
`http://localhost:8000` while developing.

---

## Extending it

**Add a network:** add one entry to `platforms()` in `app/platforms.php` (label, colour,
character limit, whether media is required) and one `drive_*()` function in
`app/publisher.php`, then wire it into the `switch` in `publish_to_platform()`. The UI,
composer, calendar and admin pages pick it up automatically.

**Add a driver for TikTok / YouTube / Pinterest:** follow the shape of `drive_facebook()` —
take `$post`, `$target` and `$token`, return
`['ok' => bool, 'id' => ?string, 'url' => ?string, 'error' => ?string]`.
