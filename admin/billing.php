<?php
/**
 * Payments and pricing, for administrators.
 *
 * Everything on this page is stored in app_settings rather than in a PHP file,
 * so changing a price or rotating a key never needs a deploy.
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/layout.php';

$me = require_admin();

if (!settings_table_ready()) {
    layout_head('Payments', 'Payments and pricing');
    ?>
    <div class="page-mid">
      <div class="alert alert-warn" style="align-items:flex-start">
        <?= icon('alert', 16) ?>
        <span><strong>One database change is needed first.</strong>
        Run <code>migrations/006_billing.sql</code> in phpMyAdmin, then reload this page.
        Nothing else on the site is affected until you do.</span>
      </div>
    </div>
    <?php
    layout_foot();
    return;
}

$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'pricing') {
        $price = str_replace(',', '.', trim((string)($_POST['pro_price'] ?? '')));

        if (!is_numeric($price) || (float)$price < 0) {
            flash('error', 'The price must be a number.');
        } else {
            setting_set('pro_price', number_format((float)$price, 2, '.', ''));
            setting_set('pro_currency',
                isset(billing_currencies()[$_POST['pro_currency'] ?? '']) ? $_POST['pro_currency'] : 'USD');
            setting_set('pro_interval', ($_POST['pro_interval'] ?? 'month') === 'year' ? 'year' : 'month');
            flash('success', 'Pricing updated. It now shows everywhere on the site.');
        }
        redirect('/admin/billing.php');
    }

    if ($do === 'provider') {
        $provider = in_array($_POST['billing_provider'] ?? '', ['stripe', 'paypal'], true)
            ? $_POST['billing_provider'] : '';
        setting_set('billing_provider', $provider);

        // A blank secret field means "leave what is stored alone", so an admin
        // can edit the plan ID without re-entering keys they cannot read back.
        $keep = function (string $field) {
            $value = trim((string)($_POST[$field] ?? ''));
            if ($value !== '') {
                setting_set($field, $value);
            }
        };

        if ($provider === 'stripe') {
            $keep('stripe_secret_key');
            $keep('stripe_webhook_secret');
        } elseif ($provider === 'paypal') {
            $keep('paypal_client_id');
            $keep('paypal_secret');
            $keep('paypal_webhook_id');
            setting_set('paypal_mode', ($_POST['paypal_mode'] ?? 'sandbox') === 'live' ? 'live' : 'sandbox');
            setting_set('paypal_plan_id', trim((string)($_POST['paypal_plan_id'] ?? '')));
        }

        flash('success', 'Provider settings saved.');
        redirect('/admin/billing.php');
    }

    if ($do === 'toggle') {
        $on = ($_POST['billing_enabled'] ?? '') === '1';
        if ($on && !billing_provider()) {
            flash('error', 'Choose and configure a provider before turning payments on.');
        } else {
            setting_set('billing_enabled', $on ? '1' : '0');
            flash('success', $on
                ? 'Payments are on. Users now see an Upgrade button that charges them.'
                : 'Payments are off. The Upgrade button falls back to emailing you.');
        }
        redirect('/admin/billing.php');
    }

    if ($do === 'test') {
        [$ok, $message] = billing_test_connection();
        flash($ok ? 'success' : 'error', $message);
        redirect('/admin/billing.php');
    }

    if ($do === 'clear') {
        foreach (setting_secret_names() as $name) {
            setting_forget($name);
        }
        setting_set('billing_enabled', '0');
        flash('success', 'Stored keys deleted and payments turned off.');
        redirect('/admin/billing.php');
    }
}

$provider = billing_provider();
$hookUrl  = rtrim(APP_URL, '/') . '/webhook.php?p=' . ($provider ?: 'stripe');

layout_head('Payments', 'Payments and pricing');
?>

<div class="page-mid stack" style="gap:24px">

  <!-- ---------------- Status ---------------- -->
  <div class="card">
    <div class="card-head">
      <h3>Status</h3>
      <?php if (billing_enabled()): ?>
        <span class="badge badge-published">taking payments<?= billing_test_mode() ? ' (test mode)' : '' ?></span>
      <?php else: ?>
        <span class="badge">not taking payments</span>
      <?php endif; ?>
    </div>
    <div class="card-pad">
      <div class="stats" style="margin:0 0 18px">
        <div class="stat"><div class="k">Pro price</div><div class="v"><?= e(billing_price_label()) ?></div></div>
        <div class="stat s-green"><div class="k">Paying users</div><div class="v"><?= billing_pro_count() ?></div></div>
        <div class="stat s-pink"><div class="k">Provider</div><div class="v" style="font-size:1.125rem"><?= $provider ? e(ucfirst($provider)) : '—' ?></div></div>
      </div>

      <?php if (billing_test_mode() && billing_enabled()): ?>
        <div class="alert alert-warn" style="align-items:flex-start">
          <?= icon('alert', 16) ?>
          <span><strong>Test mode.</strong> Real cards will not be charged and nobody is really
          paying you. Switch to live keys when you are ready.</span>
        </div>
      <?php endif; ?>

      <form method="post" class="row" style="gap:10px">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="toggle">
        <input type="hidden" name="billing_enabled" value="<?= billing_enabled() ? '0' : '1' ?>">
        <button class="btn <?= billing_enabled() ? 'btn-ghost' : '' ?>" type="submit">
          <?= billing_enabled() ? 'Turn payments off' : 'Turn payments on' ?>
        </button>
        <?php if ($provider): ?>
          <button class="btn btn-ghost" type="submit" form="test-form">Test connection</button>
        <?php endif; ?>
      </form>
      <form method="post" id="test-form" style="display:none">
        <?= csrf_field() ?><input type="hidden" name="do" value="test">
      </form>

      <p class="small muted" style="margin:14px 0 0">
        With payments off, the Upgrade button on the pricing page emails you instead, and you
        change someone's plan by hand. Nothing breaks either way.
      </p>
    </div>
  </div>

  <!-- ---------------- Pricing ---------------- -->
  <div class="card">
    <div class="card-head"><h3>Pro plan pricing</h3></div>
    <form method="post" class="card-pad">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="pricing">

      <div class="row" style="gap:16px;align-items:flex-start;flex-wrap:wrap">
        <label class="field" style="min-width:150px">
          <span>Price</span>
          <input type="text" name="pro_price" inputmode="decimal"
                 value="<?= e(billing_price()) ?>" required placeholder="12.00">
        </label>
        <label class="field" style="min-width:150px">
          <span>Currency</span>
          <select name="pro_currency">
            <?php foreach (billing_currencies() as $code => $symbol): ?>
              <option value="<?= e($code) ?>" <?= billing_currency() === $code ? 'selected' : '' ?>>
                <?= e($code) ?> (<?= e($symbol) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field" style="min-width:150px">
          <span>Billed</span>
          <select name="pro_interval">
            <option value="month" <?= billing_interval() === 'month' ? 'selected' : '' ?>>Monthly</option>
            <option value="year"  <?= billing_interval() === 'year'  ? 'selected' : '' ?>>Yearly</option>
          </select>
        </label>
      </div>

      <p class="small muted" style="margin:0 0 16px">
        Shown as <strong><?= e(billing_price_label()) ?> <?= e(billing_period_label()) ?></strong>
        on the home page, the pricing page and the signup panel.
        <?php if ($provider === 'paypal'): ?>
          <br><strong>PayPal ignores this number when charging</strong> — it uses the Plan you
          created in their dashboard. Keep the two in step yourself.
        <?php endif; ?>
      </p>

      <button class="btn" type="submit">Save pricing</button>
    </form>
  </div>

  <!-- ---------------- Provider ---------------- -->
  <div class="card">
    <div class="card-head"><h3>Payment provider</h3></div>
    <form method="post" class="card-pad">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="provider">

      <div class="field">
        <span class="label">Take payments with</span>
        <div class="acct-picker">
          <?php foreach (['' => 'None', 'stripe' => 'Stripe', 'paypal' => 'PayPal'] as $key => $label): ?>
            <label class="acct">
              <input type="radio" name="billing_provider" value="<?= e($key) ?>"
                     <?= $provider === $key ? 'checked' : '' ?>
                     onchange="document.body.dataset.prov=this.value">
              <?= e($label) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Stripe -->
      <div class="prov-panel" data-for="stripe">
        <div class="alert alert-info" style="font-size:.8125rem;align-items:flex-start">
          <?= icon('zap', 16) ?>
          <span>From your Stripe dashboard: <em>Developers &rarr; API keys</em> for the secret key,
          then <em>Developers &rarr; Webhooks</em> to add the endpoint below and copy its signing
          secret. Start with <code>sk_test_</code> keys.</span>
        </div>
        <label class="field">
          <span>Secret key</span>
          <input type="password" name="stripe_secret_key" autocomplete="off"
                 placeholder="<?= setting_has('stripe_secret_key') ? 'saved — leave blank to keep' : 'sk_live_… or sk_test_…' ?>">
          <span class="hint">Encrypted with your APP_KEY before it is stored. Never shown again.</span>
        </label>
        <label class="field">
          <span>Webhook signing secret</span>
          <input type="password" name="stripe_webhook_secret" autocomplete="off"
                 placeholder="<?= setting_has('stripe_webhook_secret') ? 'saved — leave blank to keep' : 'whsec_…' ?>">
          <span class="hint">Without this, payments are never applied — see below.</span>
        </label>
      </div>

      <!-- PayPal -->
      <div class="prov-panel" data-for="paypal">
        <div class="alert alert-info" style="font-size:.8125rem;align-items:flex-start">
          <?= icon('zap', 16) ?>
          <span>From <em>developer.paypal.com &rarr; Apps &amp; Credentials</em>. PayPal cannot price
          a subscription on the fly, so create a <strong>Product</strong> and a <strong>Plan</strong>
          in their dashboard first and paste the Plan ID here.</span>
        </div>
        <div class="row" style="gap:16px;align-items:flex-start;flex-wrap:wrap">
          <label class="field grow" style="min-width:220px">
            <span>Client ID</span>
            <input type="password" name="paypal_client_id" autocomplete="off"
                   placeholder="<?= setting_has('paypal_client_id') ? 'saved — leave blank to keep' : 'A…' ?>">
          </label>
          <label class="field grow" style="min-width:220px">
            <span>Secret</span>
            <input type="password" name="paypal_secret" autocomplete="off"
                   placeholder="<?= setting_has('paypal_secret') ? 'saved — leave blank to keep' : 'E…' ?>">
          </label>
        </div>
        <div class="row" style="gap:16px;align-items:flex-start;flex-wrap:wrap">
          <label class="field grow" style="min-width:220px">
            <span>Plan ID</span>
            <input type="text" name="paypal_plan_id" value="<?= e((string)setting('paypal_plan_id', '')) ?>"
                   placeholder="P-XXXXXXXXXXXXX">
          </label>
          <label class="field" style="min-width:160px">
            <span>Mode</span>
            <select name="paypal_mode">
              <option value="sandbox" <?= setting('paypal_mode', 'sandbox') !== 'live' ? 'selected' : '' ?>>Sandbox</option>
              <option value="live"    <?= setting('paypal_mode', 'sandbox') === 'live' ? 'selected' : '' ?>>Live</option>
            </select>
          </label>
        </div>
        <label class="field">
          <span>Webhook ID</span>
          <input type="password" name="paypal_webhook_id" autocomplete="off"
                 placeholder="<?= setting_has('paypal_webhook_id') ? 'saved — leave blank to keep' : 'WH-…' ?>">
          <span class="hint">Created when you add the webhook endpoint below.</span>
        </label>
      </div>

      <button class="btn" type="submit">Save provider settings</button>
    </form>
  </div>

  <!-- ---------------- Webhook ---------------- -->
  <div class="card">
    <div class="card-head"><h3>Webhook endpoint</h3></div>
    <div class="card-pad">
      <p class="muted" style="margin-top:0">
        Add this URL in your provider's dashboard. <strong>A payment only takes effect when the
        provider calls it</strong> — the page the customer lands on afterwards proves nothing, so
        without this nobody ever actually becomes Pro.
      </p>
      <div class="hook-url"><code><?= e($hookUrl) ?></code></div>

      <p class="small muted" style="margin:14px 0 0">
        <?php if ($provider === 'paypal'): ?>
          Subscribe it to <code>BILLING.SUBSCRIPTION.ACTIVATED</code>,
          <code>BILLING.SUBSCRIPTION.CANCELLED</code> and <code>BILLING.SUBSCRIPTION.EXPIRED</code>.
        <?php else: ?>
          Subscribe it to <code>checkout.session.completed</code> and
          <code>customer.subscription.deleted</code>.
        <?php endif; ?>
      </p>
    </div>
  </div>

  <!-- ---------------- Recent events ---------------- -->
  <div class="card">
    <div class="card-head">
      <h3>Recent billing events</h3>
      <span class="small muted">newest first</span>
    </div>
    <?php $events = billing_recent_events(); ?>
    <?php if (!$events): ?>
      <div class="card-pad">
        <p class="muted" style="margin:0">
          Nothing yet. Anything your provider sends to the webhook shows up here, including events
          PostPilot does not act on — useful for confirming the endpoint is wired up at all.
        </p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>When</th><th>User</th><th>Event</th><th>Amount</th></tr></thead>
          <tbody>
          <?php foreach ($events as $ev): ?>
            <tr>
              <td class="small muted"><?= e(date('j M, H:i', strtotime($ev['created_at'] . ' UTC'))) ?></td>
              <td><?= $ev['email'] ? e($ev['email']) : '<span class="muted">—</span>' ?></td>
              <td><code class="small"><?= e($ev['event_type']) ?></code></td>
              <td><?= $ev['amount'] ? e(billing_symbol() . $ev['amount']) : '<span class="muted">—</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- ---------------- Danger ---------------- -->
  <div class="card">
    <div class="card-head"><h3>Delete stored keys</h3></div>
    <form method="post" class="card-pad"
          onsubmit="return confirm('Delete every stored payment key and turn payments off?')">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="clear">
      <p class="muted" style="margin-top:0">
        Removes every saved key for both providers and turns payments off. Existing subscriptions
        keep running at the provider — cancel those there.
      </p>
      <button class="btn btn-danger" type="submit">Delete stored keys</button>
    </form>
  </div>

</div>

<?php layout_foot(<<<'JS'
/* Show only the panel for the chosen provider. */
(function () {
  var picked = document.querySelector('input[name=billing_provider]:checked');
  function sync() {
    var value = (document.querySelector('input[name=billing_provider]:checked') || {}).value || '';
    document.querySelectorAll('.prov-panel').forEach(function (el) {
      el.classList.toggle('show', el.dataset.for === value);
    });
  }
  document.querySelectorAll('input[name=billing_provider]').forEach(function (el) {
    el.addEventListener('change', sync);
  });
  sync();
})();
JS); ?>
