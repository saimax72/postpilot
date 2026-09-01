<?php
/**
 * Step-by-step guide to connecting a social channel.
 *
 * PostPilot has no "Log in with Facebook" button: every network is connected by
 * pasting an access token you generate yourself in that network's developer
 * portal. That is genuinely fiddly, and the accounts page cannot hold enough
 * explanation without becoming unusable, so the whole walkthrough lives here.
 *
 * The per-network steps below are written against what the drivers in
 * app/publisher.php actually send. If a driver changes, change the steps too.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';

$user = require_login();

/** A left-to-right chain of labelled boxes. Purely illustrative. */
function guide_flow(array $steps, string $color = '#5b4bd6'): string
{
    $boxW = 150; $gap = 40; $h = 92;
    $w    = count($steps) * $boxW + (count($steps) - 1) * $gap;

    $out = '<svg class="guide-art" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" '
         . 'aria-label="' . e(implode(', then ', array_column($steps, 0))) . '">';

    foreach ($steps as $i => [$title, $sub]) {
        $x    = $i * ($boxW + $gap);
        $last = $i === count($steps) - 1;

        $out .= '<rect x="' . $x . '" y="18" width="' . $boxW . '" height="56" rx="12" '
              . 'fill="var(--card)" stroke="' . ($last ? $color : 'var(--line)') . '" '
              . 'stroke-width="' . ($last ? 2 : 1) . '"/>';
        $out .= '<text x="' . ($x + $boxW / 2) . '" y="41" text-anchor="middle" '
              . 'font-size="13" font-weight="650" fill="var(--ink)">' . e($title) . '</text>';
        $out .= '<text x="' . ($x + $boxW / 2) . '" y="59" text-anchor="middle" '
              . 'font-size="11" fill="var(--muted)">' . e($sub) . '</text>';

        if (!$last) {
            $ax = $x + $boxW + 8; $ae = $x + $boxW + $gap - 8;
            $out .= '<path d="M' . $ax . ' 46 H' . $ae . '" stroke="var(--faint)" stroke-width="1.5"/>';
            $out .= '<path d="M' . ($ae - 6) . ' 42 L' . $ae . ' 46 L' . ($ae - 6) . ' 50" '
                  . 'fill="none" stroke="var(--faint)" stroke-width="1.5" stroke-linejoin="round"/>';
        }
    }
    return $out . '</svg>';
}

/**
 * The two values a network hands you, drawn side by side.
 *
 * Seeing the shape of each one is most of the battle when you are staring at a
 * developer console wondering which of six strings is the one to copy.
 */
function guide_values(string $color, string $tokenHint, ?string $idLabel, ?string $idHint): string
{
    $w = $idLabel ? 560 : 280;

    $card = function (int $x, string $label, string $hint, string $field) use ($color) {
        $s  = '<rect x="' . $x . '" y="8" width="260" height="88" rx="12" fill="var(--card)" stroke="var(--line)"/>';
        $s .= '<rect x="' . $x . '" y="8" width="4" height="88" rx="2" fill="' . $color . '"/>';
        $s .= '<text x="' . ($x + 18) . '" y="32" font-size="11" font-weight="700" '
            . 'letter-spacing="0.06em" fill="var(--faint)">' . e(strtoupper($label)) . '</text>';
        $s .= '<rect x="' . ($x + 18) . '" y="42" width="224" height="24" rx="6" fill="var(--line-soft)"/>';
        $s .= '<text x="' . ($x + 28) . '" y="58" font-size="11.5" '
            . 'font-family="ui-monospace, monospace" fill="var(--ink-2)">' . e($hint) . '</text>';
        $s .= '<text x="' . ($x + 18) . '" y="83" font-size="10.5" fill="var(--muted)">'
            . 'goes in &#8220;' . e($field) . '&#8221;</text>';
        return $s;
    };

    $out  = '<svg class="guide-art" viewBox="0 0 ' . $w . ' 104" role="img" '
          . 'aria-label="The values you paste into PostPilot">';
    $out .= $card(0, 'Access token', $tokenHint, 'Access token');
    if ($idLabel) {
        $out .= $card(300, $idLabel, (string)$idHint, 'Account / page ID');
    }
    return $out . '</svg>';
}

/** The connect form, with the two credential fields called out. */
function guide_form_art(): string
{
    $row = function (int $y, string $label, string $placeholder, bool $mark = false) {
        $s  = '<text x="0" y="' . $y . '" font-size="11" font-weight="600" fill="var(--ink-2)">'
            . $label . '</text>';
        $s .= '<rect x="0" y="' . ($y + 8) . '" width="360" height="30" rx="8" fill="var(--card)" '
            . 'stroke="' . ($mark ? 'var(--brand)' : 'var(--line)') . '" '
            . 'stroke-width="' . ($mark ? 2 : 1) . '"/>';
        $s .= '<text x="12" y="' . ($y + 28) . '" font-size="11.5" fill="var(--faint)">'
            . $placeholder . '</text>';
        return $s;
    };

    $out  = '<svg class="guide-art" viewBox="-4 -14 484 250" role="img" '
          . 'aria-label="The Connect an account form, with the two credential fields highlighted">';
    $out .= $row(10,  'Account name',      'Rice Lake Boat Rentals');
    $out .= $row(64,  'Handle (optional)', '&#64;ricelakeboats');
    $out .= '<text x="0" y="128" font-size="11" font-weight="600" fill="var(--brand)">'
          . '&#9662; Add API credentials now (optional)</text>';
    $out .= $row(146, 'Access token',      'EAAG&#8230; / Bearer token', true);
    $out .= $row(200, 'Account / page ID', 'e.g. 1784&#8230; or urn:li:person:xxx', true);

    // Callout bracket around the two credential fields.
    $out .= '<path d="M372 146 H386 V238 H372" fill="none" stroke="var(--brand)" '
          . 'stroke-width="1.5" stroke-linecap="round"/>';
    $out .= '<text x="394" y="186" font-size="11" font-weight="650" fill="var(--brand)">Leave both</text>';
    $out .= '<text x="394" y="201" font-size="11" font-weight="650" fill="var(--brand)">blank for</text>';
    $out .= '<text x="394" y="216" font-size="11" font-weight="650" fill="var(--brand)">demo mode</text>';
    return $out . '</svg>';
}

/** One numbered step. */
function guide_step(int $n, string $title, string $body): string
{
    return '<li class="guide-step"><span class="guide-n">' . $n . '</span>'
         . '<div><h4>' . $title . '</h4><p>' . $body . '</p></div></li>';
}

layout_head('Connecting accounts', 'Connecting your accounts',
    '<a class="btn btn-ghost" href="/accounts.php">' . icon('link', 16) . ' Back to accounts</a>');
?>

<div class="stack" style="gap:24px">

  <!-- ---------------- The easy path ---------------- -->
  <?php if (oauth_meta_ready()): ?>
    <div class="card" style="border-color:var(--brand)">
      <div class="card-head">
        <h3>Facebook and Instagram: one click</h3>
        <span class="badge badge-published">no token needed</span>
      </div>
      <div class="card-pad">
        <p class="muted" style="margin-top:0">
          Everything below is the manual route. For Facebook and Instagram you do not need it —
          approve PostPilot on Facebook once and your Pages, plus any Instagram accounts linked
          to them, connect themselves.
        </p>
        <a class="btn btn-lg" href="/oauth.php?go=meta" style="background:#1877F2">
          <?= platform_icon('facebook', 16) ?> Connect with Facebook
        </a>
        <p class="small muted" style="margin:16px 0 0">
          Still read the <a href="#net-instagram">Instagram</a> section for its posting limits —
          the 25-a-day cap and the aspect ratio rules apply however you connect.
        </p>
      </div>
    </div>
  <?php elseif (is_admin_user()): ?>
    <div class="alert alert-info" style="align-items:flex-start">
      <?= icon('zap', 16) ?>
      <span><strong>You can make this much easier for your users.</strong>
      Create one Meta app for this whole installation and everyone gets a
      &ldquo;Connect with Facebook&rdquo; button instead of the steps below.
      See <a href="#owner">Setting up one-click connecting</a> at the bottom of this page.</span>
    </div>
  <?php endif; ?>

  <!-- ---------------- How it works ---------------- -->
  <div class="card">
    <div class="card-head"><h3>How connecting works<?= oauth_meta_ready() ? ' by hand' : '' ?></h3></div>
    <div class="card-pad">
      <p class="muted" style="margin-top:0">
        PostPilot does not have a &ldquo;Log in with Facebook&rdquo; button. Each network makes you
        create a small developer app of your own, which hands you an <strong>access token</strong>
        &mdash; a long password proving PostPilot is allowed to post as you. You paste that token
        in once, and scheduling works from then on.
      </p>

      <?= guide_flow([
            ['Your account',     'page or profile'],
            ['Developer portal', 'create an app'],
            ['Access token',     'copy the string'],
            ['PostPilot',        'paste it in'],
          ]) ?>

      <div class="alert alert-info" style="margin-bottom:0;align-items:flex-start">
        <?= icon('zap', 16) ?>
        <span><strong>You do not have to do any of this today.</strong> Connect an account with the
        token fields left empty and it runs in <strong>demo mode</strong> &mdash; you can build your
        whole calendar, and posts move through the entire pipeline without being sent anywhere.
        Add the token later and the same account starts publishing for real.</span>
      </div>
    </div>
  </div>

  <!-- ---------------- What publishes today ---------------- -->
  <div class="card">
    <div class="card-head"><h3>What can publish today</h3></div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Network</th><th>Status</th><th>What you need</th></tr></thead>
        <tbody>
        <?php
        $needs = [
            'facebook'  => 'Page access token + Page ID',
            'instagram' => 'Access token + Instagram user ID',
            'threads'   => 'Threads token + Threads user ID',
            'linkedin'  => 'Access token + your URN',
            'x'         => 'OAuth 2.0 user token (no ID needed)',
            'tiktok'    => 'Publishing driver not written yet',
            'youtube'   => 'Publishing driver not written yet',
            'pinterest' => 'Publishing driver not written yet',
        ];
        foreach (platforms() as $key => $p): ?>
          <tr>
            <td>
              <div class="row" style="gap:10px">
                <span class="pdot pdot-sm" style="background:<?= e($p['color']) ?>"><?= platform_icon($key, 10) ?></span>
                <strong><?= e($p['label']) ?></strong>
              </div>
            </td>
            <td>
              <?php if (platform_live($key)): ?>
                <span class="badge badge-published">publishes live</span>
              <?php else: ?>
                <span class="badge">demo only</span>
              <?php endif; ?>
            </td>
            <td class="small muted"><?= e($needs[$key] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-pad" style="padding-top:14px">
      <p class="small muted" style="margin:0">
        TikTok, YouTube and Pinterest can be connected and scheduled to, but they have no publishing
        driver yet &mdash; a post aimed at them fails at send time with a message saying so. Treat
        them as demo channels for now.
      </p>
    </div>
  </div>

  <!-- ---------------- Where the values go ---------------- -->
  <div class="card">
    <div class="card-head"><h3>Where the values go</h3></div>
    <div class="card-pad">
      <p class="muted" style="margin-top:0">
        Every network below ends the same way: on <a href="/accounts.php">Accounts</a>, press
        <strong>Connect account</strong>, pick the network, give it a name, then open
        <strong>Add API credentials now</strong> and paste.
      </p>
      <?= guide_form_art() ?>
      <p class="small muted" style="margin-bottom:0">
        Tokens are encrypted with your installation&rsquo;s <code>APP_KEY</code> before they reach the
        database. Never paste one into an email or a chat window &mdash; treat it like a password,
        because that is what it is.
      </p>
    </div>
  </div>

  <!-- ---------------- Per-network walkthroughs ---------------- -->
  <h2 class="pp-h2-center" style="margin-top:10px">Step by step, network by network</h2>
  <p class="pp-h2-sub">Pick the one you want. Each takes 10&ndash;20 minutes the first time.</p>

  <?php
  $guides = [

  'facebook' => [
    'intro'  => 'You need a Facebook <strong>Page</strong> &mdash; an app cannot post to a personal
                 profile &mdash; and you must be an admin of it.',
    'values' => ['EAAG1ZCx… (~200 chars)', 'Page ID', '102938475610293'],
    'steps'  => [
      ['Make sure you have a Page',
       'Open your Page and check you are listed as an admin under <em>Page access</em>. If you only
        have a personal profile, create a Page first &mdash; it is free.'],
      ['Create a developer app',
       'Go to <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener noreferrer">developers.facebook.com/apps</a>,
        press <strong>Create app</strong>, and choose the <strong>Business</strong> type. Name it
        anything &mdash; only you will ever see it.'],
      ['Open the Graph API Explorer',
       'Go to <a href="https://developers.facebook.com/tools/explorer" target="_blank" rel="noopener noreferrer">Tools &rarr; Graph API Explorer</a>
        and select your new app in the dropdown on the right.'],
      ['Ask for the right permissions',
       'Under <em>Permissions</em>, add <code>pages_manage_posts</code>,
        <code>pages_read_engagement</code> and <code>pages_show_list</code>. Then press
        <strong>Generate access token</strong> and approve the dialog.'],
      ['Switch to a Page token',
       'In the dropdown where you picked <em>User token</em>, choose your Page instead. The string
        in the box changes &mdash; <strong>that</strong> is the one you want.'],
      ['Make the token long-lived',
       'Paste it into the <a href="https://developers.facebook.com/tools/debug/accesstoken" target="_blank" rel="noopener noreferrer">Access Token Debugger</a>
        and press <strong>Extend Access Token</strong>. A Page token derived from a long-lived user
        token does not expire, which is what you want for scheduling.'],
      ['Find your Page ID',
       'The debugger shows it as <em>Profile ID</em>. It is also on your Page under
        <em>About &rarr; Page transparency</em>.'],
      ['Paste both into PostPilot',
       'Token into <strong>Access token</strong>, Page ID into <strong>Account / page ID</strong>.'],
    ],
    'gotchas' => [
      'Posting to a Page you do not administer needs Meta&rsquo;s App Review. Posting to your own
       Page while the app is in <em>Development</em> mode works without review.',
      'If posts fail with a permissions error, regenerate the token. Permissions added after a token
       was issued do not apply to it retroactively.',
    ],
  ],

  'instagram' => [
    'intro'  => 'The fussiest of the five, because Instagram posts through Facebook. You need a
                 <strong>Business</strong> or <strong>Creator</strong> account linked to a Page.',
    'values' => ['EAAG1ZCx… or IGQVJ…', 'Instagram user ID', '17841400000000000'],
    'steps'  => [
      ['Convert your Instagram account',
       'In the Instagram app: <em>Settings &rarr; Account type and tools &rarr; Switch to
        professional account</em>. Pick Business or Creator.'],
      ['Link it to a Facebook Page',
       'Still in Instagram: <em>Settings &rarr; Sharing to other apps &rarr; Facebook</em>, and
        connect the Page. Publishing will not work without this link, even though your posts never
        appear on the Page.'],
      ['Use the same Meta app',
       'Follow the Facebook steps above if you have not made an app yet, then add the
        <strong>Instagram Graph API</strong> product to it.'],
      ['Generate a token with Instagram permissions',
       'In the Graph API Explorer add <code>instagram_basic</code>,
        <code>instagram_content_publish</code> and <code>pages_show_list</code>, generate the token,
        and extend it in the debugger as before.'],
      ['Find your Instagram user ID',
       'In the Explorer, request <code>me/accounts?fields=instagram_business_account</code>. The long
        number it returns is your Instagram user ID &mdash; not your &#64;handle, and not your Page ID.'],
      ['Paste both into PostPilot',
       'PostPilot accepts either a Facebook token (starts <code>EAA</code>) or an Instagram Login
        token (starts <code>IG</code>) and talks to the right server automatically.'],
    ],
    'gotchas' => [
      'Instagram allows <strong>25 posts per rolling 24 hours</strong>. A bulk upload larger than
       that will start failing partway through.',
      'Feed images must be between <strong>4:5 and 1.91:1</strong>. A 9:16 story-shaped image is
       rejected outright &mdash; crop it in the framing editor before scheduling.',
      'Captions cap at 2,200 characters and 30 hashtags.',
      'Every post needs an image or video. Text-only posts are not possible.',
    ],
  ],

  'threads' => [
    'intro'  => 'Threads has its own API, separate from Instagram&rsquo;s, even though the accounts
                 are linked.',
    'values' => ['THQVJ…', 'Threads user ID', '78901234567890'],
    'steps'  => [
      ['Create a Threads app',
       'At <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener noreferrer">developers.facebook.com/apps</a>,
        create an app and add the <strong>Threads API</strong> use case.'],
      ['Add the scopes',
       'You need <code>threads_basic</code> and <code>threads_content_publish</code>.'],
      ['Add yourself as a tester',
       'Under <em>Roles</em>, add your own Threads account, then accept the invitation from
        <em>Settings &rarr; Website permissions</em> inside the Threads app.'],
      ['Generate the token',
       'Use the Threads playground in the developer console to produce a user access token, then
        exchange it for a long-lived one.'],
      ['Find your Threads user ID',
       'Request <code>me?fields=id,username</code> against <code>graph.threads.net</code>. The
        <code>id</code> is what you want.'],
      ['Paste both into PostPilot',
       'Token and ID go into the same two fields as everything else.'],
    ],
    'gotchas' => [
      'Threads posts cap at 500 characters.',
      'Long-lived Threads tokens expire after 60 days and PostPilot cannot refresh them &mdash; see
       <a href="#expiry">Tokens expire</a> below.',
    ],
  ],

  'linkedin' => [
    'intro'  => 'LinkedIn identifies you by a <strong>URN</strong> rather than a plain ID &mdash; a
                 string that looks like <code>urn:li:person:AbC123</code>.',
    'values' => ['AQVJ… (very long)', 'Your URN', 'urn:li:person:AbC123'],
    'steps'  => [
      ['Create a LinkedIn app',
       'Go to <a href="https://www.linkedin.com/developers/apps" target="_blank" rel="noopener noreferrer">linkedin.com/developers/apps</a>
        and press <strong>Create app</strong>. It must be associated with a LinkedIn Page &mdash;
        create one if you do not have it.'],
      ['Request the Share product',
       'On the <em>Products</em> tab, request <strong>Share on LinkedIn</strong> and
        <strong>Sign In with LinkedIn using OpenID Connect</strong>. Both are usually granted within
        minutes.'],
      ['Set the redirect URL',
       'On the <em>Auth</em> tab, add <code><?= e(APP_URL) ?>/accounts.php</code> as an authorised
        redirect URL.'],
      ['Generate a token',
       'Use LinkedIn&rsquo;s <a href="https://www.linkedin.com/developers/tools/oauth" target="_blank" rel="noopener noreferrer">OAuth token generator</a>
        with the <code>w_member_social</code> and <code>openid profile</code> scopes.'],
      ['Find your URN',
       'Call <code>GET https://api.linkedin.com/v2/userinfo</code> with that token. The
        <code>sub</code> field is your person ID &mdash; prefix it with <code>urn:li:person:</code>.'],
      ['Paste both into PostPilot',
       'To post as a company instead, use <code>urn:li:organization:</code> followed by the Page ID,
        and make sure you are an admin of that Page.'],
    ],
    'gotchas' => [
      'LinkedIn access tokens last <strong>60 days</strong>. You will need to repeat step 4 and
       re-paste roughly every two months.',
    ],
  ],

  'x' => [
    'intro'  => 'The only network here that needs no ID &mdash; just a token. It is also the one most
                 likely to cost you money and the one that breaks soonest.',
    'values' => ['bearer token, ~100 chars', null, null],
    'steps'  => [
      ['Sign up for the developer portal',
       'Go to <a href="https://developer.x.com" target="_blank" rel="noopener noreferrer">developer.x.com</a>
        and create a project and an app. The Free tier allows only a small number of posts per month
        &mdash; check the current limit before relying on it.'],
      ['Turn on user authentication',
       'In the app settings, enable <strong>OAuth 2.0</strong>, set the type to <em>Web App</em>, and
        add <code><?= e(APP_URL) ?>/accounts.php</code> as the callback URL.'],
      ['Set the scopes',
       'You need <code>tweet.write</code>, <code>tweet.read</code>, <code>users.read</code> and
        <code>offline.access</code>.'],
      ['Generate a user access token',
       'Run the OAuth 2.0 authorisation flow from the portal and copy the resulting
        <strong>user</strong> access token &mdash; not the app-only Bearer token, which cannot post.'],
      ['Paste it into PostPilot',
       'Leave <strong>Account / page ID</strong> empty. X does not need it.'],
    ],
    'gotchas' => [
      'X user tokens expire after <strong>two hours</strong>, and PostPilot has no token refresh, so
       an X connection stops working almost immediately. Of the five live networks this is by far
       the least practical today.',
      'The Free tier is metered per month. One large bulk upload can exhaust it.',
    ],
  ],

  ];

  foreach ($guides as $key => $g):
      $p = platform($key); ?>

    <div class="card" id="net-<?= e($key) ?>">
      <div class="card-head">
        <div class="row">
          <span class="pdot" style="background:<?= e($p['color']) ?>;width:30px;height:30px"><?= platform_icon($key, 15) ?></span>
          <h3><?= e($p['label']) ?></h3>
          <span class="badge badge-published">publishes live</span>
        </div>
        <a class="small" href="<?= e($p['docs']) ?>" target="_blank" rel="noopener noreferrer">Official docs &#8599;</a>
      </div>
      <div class="card-pad">
        <p class="muted" style="margin-top:0"><?= $g['intro'] ?></p>

        <?= guide_values($p['color'], $g['values'][0], $g['values'][1], $g['values'][2]) ?>

        <ol class="guide-steps">
          <?php foreach ($g['steps'] as $i => [$t, $b]): ?>
            <?= guide_step($i + 1, $t, $b) ?>
          <?php endforeach; ?>
        </ol>

        <div class="alert alert-warn" style="margin-bottom:0;align-items:flex-start">
          <?= icon('alert', 16) ?>
          <span>
            <strong>Worth knowing</strong>
            <ul class="guide-gotchas">
              <?php foreach ($g['gotchas'] as $gt): ?><li><?= $gt ?></li><?php endforeach; ?>
            </ul>
          </span>
        </div>
      </div>
    </div>

  <?php endforeach; ?>

  <!-- ---------------- Token expiry ---------------- -->
  <div class="card" id="expiry">
    <div class="card-head"><h3>Tokens expire &mdash; and PostPilot will not warn you</h3></div>
    <div class="card-pad">
      <p class="muted" style="margin-top:0">
        Every network expires its tokens on a different clock, and PostPilot stores whatever you
        paste without refreshing it. When a token dies, posts start failing with an authentication
        error and stay in the queue until you paste a new one.
      </p>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>Network</th><th>Token lifetime</th><th>What to do</th></tr></thead>
          <tbody>
            <tr><td>Facebook</td><td>Does not expire, if made long-lived</td><td class="small muted">Nothing, unless you change your password</td></tr>
            <tr><td>Instagram</td><td>60 days</td><td class="small muted">Regenerate and re-paste</td></tr>
            <tr><td>Threads</td><td>60 days</td><td class="small muted">Regenerate and re-paste</td></tr>
            <tr><td>LinkedIn</td><td>60 days</td><td class="small muted">Regenerate and re-paste</td></tr>
            <tr><td>X</td><td><strong>2 hours</strong></td><td class="small muted">Not practical without refresh support</td></tr>
          </tbody>
        </table>
      </div>
      <p class="small muted" style="margin-bottom:0">
        To replace a token, open <a href="/accounts.php">Accounts</a>, find the channel, and use
        <strong>Edit credentials</strong>. Nothing else about the account changes, and scheduled
        posts keep their place in the queue.
      </p>
    </div>
  </div>

  <!-- ---------------- Troubleshooting ---------------- -->
  <div class="card">
    <div class="card-head"><h3>When something goes wrong</h3></div>
    <div class="card-pad">
      <dl class="guide-faq">
        <dt>My post says &ldquo;demo only&rdquo;</dt>
        <dd>That channel has no token, so nothing was sent. Add credentials on
            <a href="/accounts.php">Accounts</a>, then use <strong>Publish now</strong> on the post.</dd>

        <dt>The post failed with a permissions error</dt>
        <dd>Almost always a token generated before the permission was added. Regenerate it with every
            scope selected, and paste the new one in.</dd>

        <dt>Instagram rejected my image</dt>
        <dd>It is outside the 4:5&ndash;1.91:1 range. Open the post, crop it in the framing editor,
            and republish.</dd>

        <dt>Several posts failed at once</dt>
        <dd>Usually a rate limit rather than a broken connection. Use <strong>Requeue</strong> on the
            Queue page &mdash; it spaces the retries an hour apart.</dd>

        <dt>The post is late</dt>
        <dd>The publisher runs every ten minutes, so anything up to ten minutes past its time is
            normal.</dd>
      </dl>
    </div>
  </div>

  <?php if (is_admin_user()): ?>
  <div class="card" id="owner">
    <div class="card-head">
      <h3>Setting up one-click connecting</h3>
      <span class="badge badge-admin">administrators</span>
    </div>
    <div class="card-pad">
      <p class="muted" style="margin-top:0">
        This is done <strong>once, by you</strong>, for the whole installation — not by each user.
        After it, everyone gets a &ldquo;Connect with Facebook&rdquo; button and never sees a token.
        <?= oauth_meta_ready() ? '<strong>It is already configured here.</strong>' : '' ?>
      </p>
      <ol class="guide-steps">
        <?= guide_step(1, 'Create one Meta app',
            'At <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener noreferrer">developers.facebook.com/apps</a>,
             create a <strong>Business</strong> app. This one app serves every user of this
             installation.') ?>
        <?= guide_step(2, 'Add Facebook Login',
            'Add the <strong>Facebook Login for Business</strong> product, then under
             <em>Settings &rarr; Valid OAuth Redirect URIs</em> add exactly:
             <code>' . e(oauth_redirect_uri()) . '</code>') ?>
        <?= guide_step(3, 'Put the credentials in config',
            'Copy the App ID and App Secret from <em>Settings &rarr; Basic</em> into
             <code>app/config.php</code>:<br>
             <code>define(&#39;META_APP_ID&#39;, &#39;…&#39;);</code><br>
             <code>define(&#39;META_APP_SECRET&#39;, &#39;…&#39;);</code><br>
             The button appears as soon as both are set.') ?>
        <?= guide_step(4, 'Test with your own accounts',
            'While the app is in <em>Development</em> mode it works for Pages you administer,
             with no review. That is enough to confirm the whole flow.') ?>
        <?= guide_step(5, 'Submit for App Review — before other people use it',
            'To let <em>anyone else</em> connect their accounts, Meta must approve
             <code>pages_manage_posts</code> and <code>instagram_content_publish</code>. You will
             need a privacy policy URL and a screen recording of the flow. Expect days, sometimes
             weeks.') ?>
      </ol>
      <div class="alert alert-warn" style="margin-bottom:0;align-items:flex-start">
        <?= icon('alert', 16) ?>
        <span>The App Secret is a password for your whole installation. It belongs in
        <code>app/config.php</code>, which is never committed to git — not in any file that is.</span>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="card card-pad center">
    <h3 style="margin-top:0">Ready to connect one?</h3>
    <p class="muted">You can start in demo mode and add the token whenever you get to it.</p>
    <a class="btn" href="/accounts.php"><?= icon('plus', 16) ?> Go to Accounts</a>
  </div>

</div>

<?php layout_foot(); ?>
