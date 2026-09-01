<?php
/**
 * Where the provider sends the browser after payment.
 *
 * Deliberately does nothing but say thank you. The plan is changed by
 * webhook.php on the provider's signed word, so a user who bookmarks this URL
 * and revisits it gains nothing.
 */
require_once __DIR__ . '/app/bootstrap.php';

$user = require_login();

flash(
    plan_key($user) === 'pro' ? 'success' : 'info',
    plan_key($user) === 'pro'
        ? 'Payment received — you are on Pro. Every limit is lifted.'
        : 'Thanks. Your payment is being confirmed; the plan updates as soon as your provider '
        . 'tells us it went through, usually within a minute. Reload this page shortly.'
);

redirect('/pricing.php');
