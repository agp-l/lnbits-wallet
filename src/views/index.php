<?php
$user = $state['user'];
$loggedIn = $user !== null;
$name = $loggedIn ? (string) ($user['wallet_name'] ?: 'Lite Wallet') : 'Lite Wallet';
$error = $state['error'];
$notice = $state['notice'];
$csrf = $state['csrf'];
$pendingEmail = $state['pendingEmail'];
$maxSendSats = (int) ($state['maxSendSats'] ?? 10000);
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#ffffff">
  <meta name="referrer" content="no-referrer">
  <meta name="csrf-token" content="<?= html($csrf) ?>">
  <title><?= html($name) ?> · Lightning</title>
  <link rel="manifest" href="manifest.webmanifest">
  <link rel="icon" href="icons/icon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="assets/app.css">
  <?php if ($loggedIn): ?>
  <script src="assets/qrcodegen.js" defer></script>
  <script src="assets/app.js" defer></script>
  <?php endif; ?>
</head>
<body>
<?php if (!$loggedIn): ?>
  <div class="login-shell"><div class="login-card card">
    <span class="brand-mark" aria-hidden="true">₿</span>
    <p class="eyebrow">Lightning peněženka</p><h1>Lite Wallet</h1>
    <?php if ($setupError !== ''): ?>
      <p role="alert" class="error-box"><?= html($setupError) ?></p>
    <?php else: ?>
      <p class="lead">Zadejte svůj e-mail a pošleme vám jednorázový přihlašovací kód. Pokud peněženku ještě nemáte, založíme ji po ověření e-mailu.</p>
      <?php if ($error !== ''): ?><p role="alert" class="error-box"><?= html($error) ?></p><?php endif; ?>
      <?php if ($notice !== ''): ?><p role="status" class="notice"><?= html($notice) ?></p><?php endif; ?>
      <form method="post" autocomplete="on">
        <input type="hidden" name="action" value="request_code">
        <input type="hidden" name="csrf" value="<?= html($csrf) ?>">
        <div class="field"><label for="email">E-mail</label><input id="email" name="email" type="email" autocomplete="email" value="<?= html($pendingEmail) ?>" required></div>
        <button type="submit" class="action action-primary full-button">Poslat přihlašovací kód</button>
      </form>
      <?php if ($pendingEmail !== ''): ?>
      <form method="post" autocomplete="on">
        <input type="hidden" name="action" value="verify_code">
        <input type="hidden" name="csrf" value="<?= html($csrf) ?>">
        <div class="field"><label for="code">Kód z e-mailu pro <?= html($pendingEmail) ?></label><input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]{8}" minlength="8" maxlength="8" autocomplete="one-time-code" required></div>
        <button type="submit" class="action action-secondary full-button">Přihlásit se</button>
      </form>
      <?php endif; ?>
      <details class="password-login" <?= !empty($state['passwordLoginOpen']) ? 'open' : '' ?>>
        <summary>Máte nastavené heslo? Přihlaste se jím</summary>
        <form method="post" autocomplete="on">
          <input type="hidden" name="action" value="login_password">
          <input type="hidden" name="csrf" value="<?= html($csrf) ?>">
          <div class="field"><label for="passwordEmail">E-mail</label><input id="passwordEmail" name="email" type="email" autocomplete="username" value="<?= html($pendingEmail) ?>" required></div>
          <div class="field"><label for="loginPassword">Heslo</label><input id="loginPassword" name="password" type="password" autocomplete="current-password" required></div>
          <button type="submit" class="action action-primary full-button">Přihlásit se heslem</button>
          <p class="small-note">Zapomenuté heslo? Přihlaste se nahoře e-mailovým kódem a v nastavení si vytvořte nové.</p>
        </form>
      </details>
    <?php endif; ?>
  </div></div>
<?php else: ?>
  <?php readfile(dirname(__DIR__) . '/icons.svg'); ?>
  <div class="app" id="app">
    <header class="topbar" id="topbar">
      <button class="tap-icon" id="menuOpen" type="button" aria-label="Otevřít nabídku" aria-controls="drawer" aria-expanded="false"><svg class="icon" aria-hidden="true"><use href="#i-menu"/></svg></button>
      <div class="brand-mark" aria-hidden="true">₿</div>
      <div class="brand-text"><strong><?= html($name) ?></strong><span>LIGHTNING PENĚŽENKA</span></div>
      <button class="tap-icon" id="balanceToggle" type="button" aria-label="Skrýt částky" aria-pressed="false"><svg class="icon" aria-hidden="true"><use href="#i-eye"/></svg></button>
    </header>
    <main class="screen" id="screen" tabindex="-1">
      <section class="view" data-view="home" aria-label="Přehled">
        <p class="eyebrow">Moje peněženka</p><h1>Bitcoin na jednom místě.</h1><p class="lead">Zůstatek a platby přes Lightning Network.</p>
        <section class="balance-card" aria-label="Zůstatek">
          <div class="balance-top"><span class="balance-label">Aktuální zůstatek</span><span class="network-pill">LIGHTNING</span></div>
          <div class="balance-number"><span id="balance" data-private>—</span> <small>sat</small></div>
          <div class="balance-fiat">Účet spravovaný službou LNbits</div>
          <div class="balance-divider"></div><div class="balance-foot"><span id="walletName">Peněženka</span><strong id="connection">Načítání…</strong></div>
        </section>
        <div class="action-row"><button type="button" class="action action-primary" data-go="receive"><svg class="icon" aria-hidden="true"><use href="#i-down"/></svg>Přijmout</button><button type="button" class="action action-secondary" data-go="send"><svg class="icon" aria-hidden="true"><use href="#i-up"/></svg>Odeslat</button></div>
        <div class="section-heading"><h2>Poslední pohyby</h2><button class="text-button" type="button" data-go="activity">Zobrazit vše</button></div>
        <div class="activity-list" id="recentList"><p class="empty-state">Načítám platby…</p></div>
        <div class="notice"><svg class="icon" aria-hidden="true"><use href="#i-info"/></svg><span>Pro příjem vytvořte Lightning fakturu. BTC adresy pro on-chain platby tato peněženka nevytváří.</span></div>
      </section>
      <section class="view" data-view="activity" aria-label="Historie plateb" hidden>
        <div class="page-head"><p class="eyebrow">Vaše aktivita</p><h1>Historie plateb</h1><p class="lead">Příchozí a odchozí Lightning platby.</p></div>
        <div class="activity-list" id="historyList"><p class="empty-state">Načítám platby…</p></div>
        <button class="action action-secondary full-button" id="refreshHistory" type="button">Obnovit historii</button>
      </section>
      <section class="view" data-view="receive" aria-label="Přijmout Lightning" hidden>
        <div class="page-head"><p class="eyebrow">Příchozí platba</p><h1>Přijmout bitcoin</h1><p class="lead">Vytvořte fakturu pro přesnou částku v satoshi.</p></div>
        <form id="receiveForm" class="card" autocomplete="off">
          <h2 class="card-title"><svg class="icon" aria-hidden="true"><use href="#i-down"/></svg>Nová Lightning faktura</h2>
          <div class="field"><label for="receiveAmount">Částka v sat</label><div class="form-suffix"><input id="receiveAmount" type="number" min="1" step="1" inputmode="numeric" required><span>sat</span></div></div>
          <div class="field"><label for="receiveMemo">Poznámka (nepovinná)</label><input id="receiveMemo" type="text" maxlength="140" placeholder="Za co je platba"></div>
          <button type="submit" class="action action-primary full-button">Vytvořit fakturu</button>
        </form>
        <div class="card receive-card generated-invoice" id="invoiceCard" hidden>
          <h2 class="card-title">Faktura připravená k zaplacení</h2>
          <div class="qr-display" id="invoiceQr" aria-label="QR kód Lightning faktury"></div>
          <div class="address-box" id="invoiceText"></div>
          <button type="button" class="action action-secondary full-button" id="copyInvoice"><svg class="icon icon-sm" aria-hidden="true"><use href="#i-copy"/></svg>Kopírovat fakturu</button>
          <p class="small-note" id="invoiceStatus" role="status">Čeká na zaplacení.</p>
        </div>
      </section>
      <section class="view" data-view="send" aria-label="Odeslat Lightning" hidden>
        <div class="page-head"><p class="eyebrow">Odchozí platba</p><h1>Odeslat bitcoin</h1><p class="lead">Stačí znát e-mail příjemce. Zaplatit můžete i do jiné Lightning peněženky.</p></div>
        <form id="sendForm" class="card" autocomplete="off">
          <h2 class="card-title"><svg class="icon" aria-hidden="true"><use href="#i-up"/></svg>Komu posíláte?</h2>
          <div class="send-modes" role="group" aria-label="Způsob odeslání">
            <button type="button" class="send-mode" data-send-mode="email" aria-controls="emailRecipientField" aria-pressed="true">Poslat člověku e-mailem</button>
            <button type="button" class="send-mode" data-send-mode="external" aria-controls="externalRecipientField" aria-pressed="false">Zaplatit do jiné peněženky</button>
          </div>
          <p class="card-desc send-description" id="sendDescription">Příjemci založíme peněženku, pokud ji ještě nemá. V oznámení uvidí váš e-mail jako odesílatele a přihlásí se kódem.</p>
          <div class="field" id="emailRecipientField"><label for="emailRecipient">E-mail příjemce</label><input id="emailRecipient" type="email" autocomplete="off" placeholder="jmeno@domena.cz" required></div>
          <div class="field" id="externalRecipientField" hidden><label for="externalRecipient">Lightning adresa nebo faktura</label><textarea id="externalRecipient" rows="3" placeholder="jmeno@domena.cz nebo lnbc…" spellcheck="false" autocapitalize="off" maxlength="5000" required disabled></textarea><small id="externalHint">Lightning adresa vypadá jako e-mail, ale platba nejde do e-mailové schránky. Vložte také fakturu začínající lnbc…</small></div>
          <div class="field" id="sendAmountField"><label for="sendAmount">Částka v sat</label><div class="form-suffix"><input id="sendAmount" type="number" min="1" max="<?= $maxSendSats ?>" step="1" inputmode="numeric" required><span>sat</span></div><small>1 až <?= number_format($maxSendSats, 0, ',', ' ') ?> sat.</small></div>
          <button type="submit" class="action action-primary full-button">Zkontrolovat platbu</button>
          <p class="small-note" id="sendNote">Převod probíhá přes Lightning a může mít poplatek. Odeslání potvrdíte v dalším kroku.</p>
        </form>
        <p class="notice" id="transferNotice" role="status" aria-live="polite" hidden></p>
      </section>
      <section class="view" data-view="settings" aria-label="Nastavení" hidden>
        <div class="page-head"><p class="eyebrow">Aplikace</p><h1>Nastavení</h1><p class="lead">Účet: <?= html($user['email']) ?></p></div>
        <div class="card settings-card"><button type="button" class="setting-row" id="settingsVisibility"><span class="transaction-icon"><svg class="icon" aria-hidden="true"><use href="#i-eye"/></svg></span><span class="copy"><strong>Viditelnost zůstatku</strong><small id="visibilityText">Částky se zobrazují</small></span><svg class="icon icon-sm" aria-hidden="true"><use href="#i-chevron"/></svg></button></div>
        <form id="passwordForm" class="card password-settings" autocomplete="on">
          <h2 class="card-title"><?= empty($user['password_hash']) ? 'Nastavit heslo' : 'Změnit heslo' ?></h2>
          <p class="card-desc" id="passwordDescription"><?= empty($user['password_hash']) ? 'Heslem se pak můžete přihlásit bez čekání na e-mail. Přihlášení kódem zůstane dostupné.' : 'Heslo lze změnit. Pokud jste ho zapomněli, přihlaste se e-mailovým kódem a nastavte nové.' ?></p>
          <div class="field" id="currentPasswordField" <?= empty($user['password_hash']) || !empty($state['freshEmailLogin']) ? 'hidden' : '' ?>><label for="currentPassword">Současné heslo</label><input id="currentPassword" type="password" autocomplete="current-password" <?= empty($user['password_hash']) || !empty($state['freshEmailLogin']) ? '' : 'required' ?>></div>
          <div class="field"><label for="newPassword">Nové heslo</label><input id="newPassword" type="password" autocomplete="new-password" minlength="12" maxlength="72" required><small>Alespoň 12 znaků, maximálně 72 bajtů. Nikdy ho neposíláme e-mailem.</small></div>
          <div class="field"><label for="confirmPassword">Nové heslo znovu</label><input id="confirmPassword" type="password" autocomplete="new-password" required></div>
          <button type="submit" class="action action-primary full-button">Uložit heslo</button>
          <p class="small-note" id="passwordMessage" role="status" aria-live="polite" hidden></p>
        </form>
        <form method="post"><input type="hidden" name="csrf" value="<?= html($csrf) ?>"><input type="hidden" name="action" value="logout"><button class="action action-secondary full-button" type="submit">Odhlásit se</button></form>
        <div class="notice"><svg class="icon" aria-hidden="true"><use href="#i-shield"/></svg><span>Platby spravuje připojený LNbits server. Tato aplikace neobsahuje vlastní seed.</span></div>
      </section>
    </main>
    <nav class="bottom-nav" id="bottomNav" aria-label="Hlavní navigace">
      <button type="button" class="nav-item" data-go="home" aria-current="page"><svg class="icon" aria-hidden="true"><use href="#i-home"/></svg>Přehled</button>
      <button type="button" class="nav-item" data-go="activity"><svg class="icon" aria-hidden="true"><use href="#i-history"/></svg>Aktivita</button>
      <button type="button" class="nav-item" data-go="receive"><svg class="icon" aria-hidden="true"><use href="#i-down"/></svg>Přijmout</button>
      <button type="button" class="nav-item" data-go="send"><svg class="icon" aria-hidden="true"><use href="#i-up"/></svg>Odeslat</button>
    </nav>
    <div class="drawer-backdrop" id="drawerBackdrop" hidden></div>
    <aside class="drawer" id="drawer" role="dialog" aria-modal="true" aria-label="Nabídka peněženky" hidden>
      <div class="drawer-head"><div class="drawer-identity"><span class="brand-mark" aria-hidden="true">₿</span><strong><?= html($name) ?></strong></div><button class="tap-icon" type="button" id="menuClose" aria-label="Zavřít nabídku"><svg class="icon" aria-hidden="true"><use href="#i-close"/></svg></button></div>
      <div class="drawer-content"><div class="drawer-label">Peněženka</div>
        <button class="drawer-link" type="button" data-go="home"><svg class="icon" aria-hidden="true"><use href="#i-home"/></svg>Přehled</button>
        <button class="drawer-link" type="button" data-go="activity"><svg class="icon" aria-hidden="true"><use href="#i-history"/></svg>Historie plateb</button>
        <button class="drawer-link" type="button" data-go="receive"><svg class="icon" aria-hidden="true"><use href="#i-down"/></svg>Přijmout Lightning</button>
        <button class="drawer-link" type="button" data-go="send"><svg class="icon" aria-hidden="true"><use href="#i-up"/></svg>Odeslat Lightning</button>
        <div class="drawer-label drawer-label-spaced">Další</div><button class="drawer-link" type="button" data-go="settings"><svg class="icon" aria-hidden="true"><use href="#i-settings"/></svg>Nastavení</button>
      </div><p class="drawer-foot">Lite Wallet · Lightning Network</p>
    </aside>
    <dialog class="modal" id="detailModal" aria-labelledby="modalTitle">
      <div class="modal-head"><h2 id="modalTitle">Detail</h2><button class="tap-icon" type="button" data-close-modal aria-label="Zavřít"><svg class="icon" aria-hidden="true"><use href="#i-close"/></svg></button></div>
      <p id="modalDescription"></p><dl id="modalDetails"></dl>
      <div id="modalActions"><button type="button" class="action action-primary full-button" data-close-modal>Rozumím</button></div>
    </dialog>
    <p class="toast" id="toast" role="status" aria-live="polite" hidden></p>
  </div>
<?php endif; ?>
</body></html>
