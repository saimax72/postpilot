<?php
/**
 * Admin-editable configuration, stored in the database.
 *
 * app/config.php holds what the *server* needs to boot — database credentials,
 * APP_KEY. This holds what an *administrator* decides while the site is
 * running: the price of the Pro plan, which payment provider is in use, its
 * keys. Those change without a deploy, so they cannot live in a PHP file that
 * git overwrites on every push.
 *
 * Secrets are encrypted with APP_KEY on the way in. A dump of app_settings on
 * its own therefore reveals nothing usable.
 */

/** Setting names whose values are encrypted at rest. */
function setting_secret_names(): array
{
    return [
        'stripe_secret_key', 'stripe_webhook_secret',
        'paypal_client_id', 'paypal_secret', 'paypal_webhook_id',
    ];
}

function setting_is_secret(string $name): bool
{
    return in_array($name, setting_secret_names(), true);
}

/**
 * Every setting, read once per request.
 *
 * plans() asks for the price on nearly every page, so this cannot be a query
 * per lookup. Returns [] if the table is not there yet — migration 006 is run
 * by hand after the deploy that adds this file, and the site has to survive
 * that window.
 */
function settings_all(bool $refresh = false): array
{
    static $cache = null;

    if ($cache === null || $refresh) {
        $cache = [];
        try {
            foreach (db_all('SELECT name, value FROM app_settings') as $row) {
                $cache[$row['name']] = $row['value'];
            }
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return $cache;
}

/** One setting, decrypted if it is a secret. Null when unset or undecryptable. */
function setting(string $name, ?string $default = null): ?string
{
    $all = settings_all();
    if (!array_key_exists($name, $all) || $all[$name] === null || $all[$name] === '') {
        return $default;
    }
    if (setting_is_secret($name)) {
        return decrypt_secret($all[$name]) ?: $default;
    }
    return $all[$name];
}

/** True when a secret is stored, without decrypting or revealing it. */
function setting_has(string $name): bool
{
    $all = settings_all();
    return isset($all[$name]) && $all[$name] !== '';
}

function setting_set(string $name, ?string $value): void
{
    if ($value !== null && setting_is_secret($name)) {
        $value = encrypt_secret($value);
    }
    db_run(
        'INSERT INTO app_settings (name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)',
        [$name, $value]
    );
    settings_all(true);
}

function setting_forget(string $name): void
{
    db_run('DELETE FROM app_settings WHERE name = ?', [$name]);
    settings_all(true);
}

/** Whether migration 006 has been applied. */
function settings_table_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            db_value('SELECT 1 FROM app_settings LIMIT 1');
            $ready = true;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}
