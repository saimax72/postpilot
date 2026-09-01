<?php
/**
 * Payment provider webhooks.
 *
 * This is the only place a user's plan changes to 'pro'. The browser coming
 * back to billing-return.php proves nothing — anyone can visit a URL — whereas
 * a webhook carries the provider's signature over the exact bytes they sent.
 *
 * Unverified requests are dropped with a 400 and never touch the database.
 *
 *   Stripe:  https://your-domain/webhook.php?p=stripe
 *   PayPal:  https://your-domain/webhook.php?p=paypal
 */
require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: text/plain');

$provider = $_GET['p'] ?? '';
$raw      = file_get_contents('php://input') ?: '';

/** Log and stop. The body is never echoed back — it can contain customer data. */
function webhook_fail(string $why, int $code = 400): void
{
    http_response_code($code);
    error_log('[postpilot webhook] ' . $why);
    echo 'rejected';
    exit;
}

if ($raw === '' || strlen($raw) > 1048576) {
    webhook_fail('empty or oversized body');
}

/* ==================== Stripe ==================== */

if ($provider === 'stripe') {
    $secret = setting('stripe_webhook_secret');
    if (!$secret) {
        webhook_fail('no stripe webhook secret configured', 503);
    }

    // Stripe-Signature: t=<timestamp>,v1=<hmac of "t.body">
    $header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $parts  = [];
    foreach (explode(',', $header) as $bit) {
        [$k, $v] = array_pad(explode('=', trim($bit), 2), 2, '');
        $parts[$k][] = $v;
    }

    $timestamp = (int)($parts['t'][0] ?? 0);
    if (!$timestamp || abs(time() - $timestamp) > 300) {
        webhook_fail('stripe timestamp outside tolerance');   // replay protection
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $raw, $secret);
    $matched  = false;
    foreach ($parts['v1'] ?? [] as $candidate) {
        if (hash_equals($expected, $candidate)) {
            $matched = true;
            break;
        }
    }
    if (!$matched) {
        webhook_fail('stripe signature mismatch');
    }

    $event  = json_decode($raw, true) ?: [];
    $type   = (string)($event['type'] ?? '');
    $object = $event['data']['object'] ?? [];

    // The user id is carried through as metadata from the moment the checkout
    // session was created, so we never have to guess from an email address.
    $userId = (int)($object['metadata']['user_id']
                 ?? $object['client_reference_id']
                 ?? 0);

    $meta = [
        'type'            => $type,
        'event_id'        => $event['id'] ?? null,
        'customer_id'     => $object['customer'] ?? null,
        'subscription_id' => is_string($object['subscription'] ?? null)
                                ? $object['subscription'] : ($object['id'] ?? null),
        'amount'          => isset($object['amount_total'])
                                ? number_format($object['amount_total'] / 100, 2, '.', '') : null,
    ];

    if ($type === 'checkout.session.completed' && $userId) {
        billing_grant_pro($userId, 'stripe', $meta);
    } elseif ($type === 'customer.subscription.deleted') {
        $found = db_one('SELECT id FROM users WHERE billing_sub_id = ?', [$object['id'] ?? '']);
        if ($found) {
            billing_revoke_pro((int)$found['id'], 'stripe', $meta);
        }
    } else {
        // Recorded but not acted on, so the admin page shows what arrived.
        billing_record_event($userId ?: null, 'stripe', $meta);
    }

    echo 'ok';
    exit;
}

/* ==================== PayPal ==================== */

if ($provider === 'paypal') {
    $webhookId = setting('paypal_webhook_id');
    if (!$webhookId) {
        webhook_fail('no paypal webhook id configured', 503);
    }

    [$token, $err] = paypal_token();
    if (!$token) {
        webhook_fail('paypal token failed: ' . $err, 503);
    }

    // PayPal verifies its own signatures: we hand the headers back to them
    // rather than reimplementing certificate-chain checking here.
    $verify = http_json(paypal_host() . '/v1/notifications/verify-webhook-signature', [
        'transmission_id'   => $_SERVER['HTTP_PAYPAL_TRANSMISSION_ID']   ?? '',
        'transmission_time' => $_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME'] ?? '',
        'cert_url'          => $_SERVER['HTTP_PAYPAL_CERT_URL']          ?? '',
        'auth_algo'         => $_SERVER['HTTP_PAYPAL_AUTH_ALGO']         ?? '',
        'transmission_sig'  => $_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG']  ?? '',
        'webhook_id'        => $webhookId,
        'webhook_event'     => json_decode($raw, true),
    ], ['Authorization: Bearer ' . $token]);

    if (($verify['verification_status'] ?? '') !== 'SUCCESS') {
        webhook_fail('paypal signature not verified');
    }

    $event    = json_decode($raw, true) ?: [];
    $type     = (string)($event['event_type'] ?? '');
    $resource = $event['resource'] ?? [];
    $userId   = (int)($resource['custom_id'] ?? 0);

    $meta = [
        'type'            => $type,
        'event_id'        => $event['id'] ?? null,
        'subscription_id' => $resource['id'] ?? null,
        'customer_id'     => $resource['subscriber']['payer_id'] ?? null,
        'amount'          => $resource['billing_info']['last_payment']['amount']['value'] ?? null,
    ];

    if ($type === 'BILLING.SUBSCRIPTION.ACTIVATED' && $userId) {
        billing_grant_pro($userId, 'paypal', $meta);
    } elseif (in_array($type, ['BILLING.SUBSCRIPTION.CANCELLED',
                               'BILLING.SUBSCRIPTION.EXPIRED',
                               'BILLING.SUBSCRIPTION.SUSPENDED'], true)) {
        $found = $userId
            ? db_one('SELECT id FROM users WHERE id = ?', [$userId])
            : db_one('SELECT id FROM users WHERE billing_sub_id = ?', [$resource['id'] ?? '']);
        if ($found) {
            billing_revoke_pro((int)$found['id'], 'paypal', $meta);
        }
    } else {
        billing_record_event($userId ?: null, 'paypal', $meta);
    }

    echo 'ok';
    exit;
}

webhook_fail('unknown provider', 404);
