<?php
/**
 * Send a signed-in user to the payment provider.
 *
 * Nothing about their plan changes here. This only builds a checkout session
 * and redirects; the upgrade happens when the provider calls webhook.php.
 */
require_once __DIR__ . '/app/bootstrap.php';

$user = require_login();

if (!billing_enabled()) {
    flash('error', 'Payments are not set up on this installation yet.');
    redirect('/pricing.php');
}

if (plan_key($user) === 'pro') {
    flash('info', 'You are already on Pro.');
    redirect('/pricing.php');
}

[$url, $error] = billing_checkout_url($user);

if (!$url) {
    // The provider's own words are more useful than anything generic, and only
    // the user who pressed the button sees this.
    flash('error', 'Could not start checkout: ' . $error);
    redirect('/pricing.php');
}

redirect($url);
