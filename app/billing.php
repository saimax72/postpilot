<?php
/**
 * Taking money for the Pro plan, via Stripe or PayPal.
 *
 * Shared hosting means no Composer, so neither official SDK is available and
 * everything here talks to the REST APIs over cURL directly. That is fine —
 * both flows need three calls each.
 *
 * The shape is deliberately the same for both providers:
 *
 *   billing_checkout_url()  send the user to the provider to pay
 *   billing_grant_pro()     the provider says they paid; upgrade them
 *   billing_revoke_pro()    the provider says they stopped; put them back
 *
 * Granting happens only from a *verified* webhook, never from the URL the user
 * is redirected back to. A return URL is a hint that the browser came back; a
 * signed webhook is the provider's word that money moved.
 */

const BILLING_DEFAULT_PRICE    = '12.00';
const BILLING_DEFAULT_CURRENCY = 'USD';

/* ---------------- Configuration ---------------- */

/** 'stripe', 'paypal', or '' when nobody can pay yet. */
function billing_provider(): string
{
    $p = (string)setting('billing_provider', '');
    return in_array($p, ['stripe', 'paypal'], true) ? $p : '';
}

/** True when the chosen provider has everything it needs to take a payment. */
function billing_enabled(): bool
{
    if (setting('billing_enabled') !== '1') {
        return false;
    }
    return match (billing_provider()) {
        'stripe' => (bool)setting('stripe_secret_key'),
        'paypal' => (bool)setting('paypal_client_id')
                 && (bool)setting('paypal_secret')
                 && (bool)setting('paypal_plan_id'),
        default  => false,
    };
}

/** True when the provider is in its test/sandbox mode. */
function billing_test_mode(): bool
{
    return match (billing_provider()) {
        'stripe' => str_starts_with((string)setting('stripe_secret_key', ''), 'sk_test_'),
        'paypal' => setting('paypal_mode', 'sandbox') !== 'live',
        default  => false,
    };
}

function billing_price(): string
{
    $p = (string)setting('pro_price', BILLING_DEFAULT_PRICE);
    return is_numeric($p) ? number_format((float)$p, 2, '.', '') : BILLING_DEFAULT_PRICE;
}

function billing_currency(): string
{
    return strtoupper((string)setting('pro_currency', BILLING_DEFAULT_CURRENCY));
}

/** 'month' or 'year'. */
function billing_interval(): string
{
    return setting('pro_interval', 'month') === 'year' ? 'year' : 'month';
}

function billing_currencies(): array
{
    return ['USD' => '$', 'CAD' => 'CA$', 'EUR' => '€', 'GBP' => '£', 'AUD' => 'A$'];
}

function billing_symbol(?string $currency = null): string
{
    return billing_currencies()[$currency ?: billing_currency()] ?? '';
}

/** The Pro price as it should be printed, e.g. "$12" or "£9.50". */
function billing_price_label(): string
{
    $amount = billing_price();
    // Whole amounts read better without the trailing zeros on a pricing card.
    if (str_ends_with($amount, '.00')) {
        $amount = substr($amount, 0, -3);
    }
    return billing_symbol() . $amount;
}

function billing_period_label(): string
{
    return billing_interval() === 'year' ? 'per year' : 'per month';
}

/* ---------------- Sending someone to pay ---------------- */

/**
 * Where to send this user to subscribe. Returns [url, error].
 */
function billing_checkout_url(array $user): array
{
    if (!billing_enabled()) {
        return [null, 'Payments are not set up on this installation yet.'];
    }
    return match (billing_provider()) {
        'stripe' => stripe_checkout_url($user),
        'paypal' => paypal_checkout_url($user),
        default  => [null, 'No payment provider selected.'],
    };
}

/**
 * A Stripe Checkout session for a subscription.
 *
 * The price is sent inline as price_data rather than referencing a Price object
 * created in the dashboard, so an administrator can change the number on the
 * settings page and have it take effect without touching Stripe at all.
 */
function stripe_checkout_url(array $user): array
{
    $base = rtrim(APP_URL, '/');

    $res = stripe_post('https://api.stripe.com/v1/checkout/sessions', [
        'mode'                                  => 'subscription',
        'success_url'                           => $base . '/billing-return.php?ok=1',
        'cancel_url'                            => $base . '/pricing.php',
        'customer_email'                        => $user['email'],
        'client_reference_id'                   => (string)$user['id'],
        'metadata[user_id]'                     => (string)$user['id'],
        'subscription_data[metadata][user_id]'  => (string)$user['id'],
        'line_items[0][quantity]'               => '1',
        'line_items[0][price_data][currency]'   => strtolower(billing_currency()),
        'line_items[0][price_data][unit_amount]'=> (string)(int)round((float)billing_price() * 100),
        'line_items[0][price_data][recurring][interval]' => billing_interval(),
        'line_items[0][price_data][product_data][name]'  => APP_NAME . ' Pro',
    ]);

    if (!empty($res['url'])) {
        return [$res['url'], null];
    }
    return [null, $res['error']['message'] ?? 'Stripe did not return a checkout URL.'];
}

/**
 * A PayPal subscription approval link.
 *
 * PayPal cannot price a subscription inline — it needs a Plan created in their
 * dashboard — so the administrator pastes a Plan ID and the price field here is
 * only what we display. That mismatch is worth surfacing in the admin UI.
 */
function paypal_checkout_url(array $user): array
{
    [$token, $err] = paypal_token();
    if (!$token) {
        return [null, $err];
    }

    $base = rtrim(APP_URL, '/');
    $res  = http_json(paypal_host() . '/v1/billing/subscriptions', [
        'plan_id'             => setting('paypal_plan_id'),
        'custom_id'           => (string)$user['id'],
        'subscriber'          => ['email_address' => $user['email']],
        'application_context' => [
            'brand_name'  => APP_NAME,
            'user_action' => 'SUBSCRIBE_NOW',
            'return_url'  => $base . '/billing-return.php?ok=1',
            'cancel_url'  => $base . '/pricing.php',
        ],
    ], ['Authorization: Bearer ' . $token]);

    foreach ($res['links'] ?? [] as $link) {
        if (($link['rel'] ?? '') === 'approve') {
            return [$link['href'], null];
        }
    }
    return [null, $res['message'] ?? 'PayPal did not return an approval link.'];
}

/* ---------------- Provider plumbing ---------------- */

function paypal_host(): string
{
    return setting('paypal_mode', 'sandbox') === 'live'
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com';
}

/** An OAuth token for the PayPal REST API. Returns [token, error]. */
function paypal_token(): array
{
    $ch = curl_init(paypal_host() . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_USERPWD        => setting('paypal_client_id') . ':' . setting('paypal_secret'),
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return [null, 'Could not reach PayPal: ' . $err];
    }
    $body = json_decode($raw, true);
    if (!empty($body['access_token'])) {
        return [$body['access_token'], null];
    }
    return [null, $body['error_description'] ?? 'PayPal rejected those credentials.'];
}

/** A form-encoded POST to Stripe. */
function stripe_post(string $url, array $fields): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . setting('stripe_secret_key'),
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['error' => ['message' => 'Could not reach Stripe: ' . $err]];
    }
    $body = json_decode($raw, true);
    return is_array($body) ? $body : ['error' => ['message' => 'Unexpected response from Stripe.']];
}

/** A read-only GET against Stripe, used to confirm a key works. */
function stripe_get(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . setting('stripe_secret_key')],
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);

    $body = $raw === false ? null : json_decode($raw, true);
    return is_array($body) ? $body : ['error' => ['message' => 'Could not reach the provider.']];
}

/**
 * Confirm the stored credentials actually work, without taking any money.
 * Returns [ok, message].
 */
function billing_test_connection(): array
{
    switch (billing_provider()) {
        case 'stripe':
            if (!setting('stripe_secret_key')) {
                return [false, 'No Stripe secret key saved.'];
            }
            $res = stripe_get('https://api.stripe.com/v1/balance');
            return isset($res['object']) && $res['object'] === 'balance'
                ? [true, 'Stripe accepted the key' . (billing_test_mode() ? ' (test mode).' : ' (live mode).')]
                : [false, $res['error']['message'] ?? 'Stripe rejected the key.'];

        case 'paypal':
            [$token, $err] = paypal_token();
            if (!$token) {
                return [false, $err];
            }
            if (!setting('paypal_plan_id')) {
                return [false, 'Credentials are good, but no Plan ID is saved yet.'];
            }
            return [true, 'PayPal accepted the credentials ('
                        . (billing_test_mode() ? 'sandbox' : 'live') . ').'];
    }
    return [false, 'No payment provider selected.'];
}

/* ---------------- Changing someone's plan ---------------- */

/**
 * Upgrade a user to Pro. Called only from a verified webhook.
 *
 * Writing the event first, with a unique key on (provider, event_id), makes
 * this safe to call twice: providers retry webhooks, and a duplicate delivery
 * must not produce a second charge record or a second log line.
 */
function billing_grant_pro(int $userId, string $provider, array $meta = []): bool
{
    if (!billing_record_event($userId, $provider, $meta)) {
        return false;                       // already processed this delivery
    }

    db_run(
        "UPDATE users
            SET plan = 'pro', plan_since = UTC_TIMESTAMP(),
                billing_provider = ?, billing_customer_id = ?, billing_sub_id = ?
          WHERE id = ?",
        [$provider, $meta['customer_id'] ?? null, $meta['subscription_id'] ?? null, $userId]
    );
    log_activity($userId, 'billing_upgrade', 'Upgraded to Pro via ' . $provider);
    return true;
}

/** Put a user back on the trial tier when their subscription ends. */
function billing_revoke_pro(int $userId, string $provider, array $meta = []): bool
{
    if (!billing_record_event($userId, $provider, $meta)) {
        return false;
    }

    // Their trial is long gone, so they land as an expired trial: everything
    // they made stays, scheduled posts keep publishing, new ones stop.
    db_run(
        "UPDATE users SET plan = 'trial', billing_sub_id = NULL WHERE id = ?",
        [$userId]
    );
    log_activity($userId, 'billing_downgrade', 'Subscription ended (' . $provider . ')');
    return true;
}

/** Returns false when this exact event was already recorded. */
function billing_record_event(?int $userId, string $provider, array $meta): bool
{
    try {
        db_run(
            'INSERT INTO billing_events (user_id, provider, event_type, event_id, amount, detail)
             VALUES (?,?,?,?,?,?)',
            [$userId ?: null, $provider, $meta['type'] ?? 'unknown', $meta['event_id'] ?? null,
             $meta['amount'] ?? null, $meta['detail'] ?? null]
        );
        return true;
    } catch (Throwable $e) {
        return false;                       // unique key rejected a replay
    }
}

/** Recent billing activity for the admin page. */
function billing_recent_events(int $limit = 20): array
{
    try {
        return db_all(
            'SELECT e.*, u.name, u.email
               FROM billing_events e
          LEFT JOIN users u ON u.id = e.user_id
           ORDER BY e.id DESC
              LIMIT ' . (int)$limit
        );
    } catch (Throwable $e) {
        return [];
    }
}

/** How many people are paying right now. */
function billing_pro_count(): int
{
    try {
        return (int)db_value("SELECT COUNT(*) FROM users WHERE plan = 'pro'");
    } catch (Throwable $e) {
        return 0;
    }
}
