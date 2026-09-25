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
        <div class="page-head"><p class="eyebrow">Odchozí platba</p><h1>Odeslat bitcoin</h1><p class="lead">Pošlete satoshi na e-mail v Lite Wallet, Lightning adresu nebo zaplaťte fakturu.</p></div>
        <form id="emailSendForm" class="card" autocomplete="off">
          <h2 class="card-title"><svg class="icon" aria-hidden="true"><use href="#i-up"/></svg>Poslat uživateli Lite Wallet</h2>
          <p class="card-desc">Příjemci se založí vlastní Lightning peněženka, pokud ji ještě nemá. Přístup získá kódem doručeným na tuto adresu.</p>
          <div class="field"><label for="emailRecipient">E-mail příjemce</label><input id="emailRecipient" type="email" autocomplete="off" required></div>
          <div class="field"><label for="emailAmount">Částka v sat</label><div class="form-suffix"><input id="emailAmount" type="number" min="1" max="<?= $maxSendSats ?>" step="1" inputmode="numeric" required><span>sat</span></div><small>1 až <?= number_format($maxSendSats, 0, ',', ' ') ?> sat. Příjemce musí mít jiný e-mail než vy.</small></div>
          <button type="submit" class="action action-primary full-button">Zkontrolovat převod</button>
          <p class="small-note">Převod probíhá přes Lightning a může mít poplatek. E-mail není BTC adresa na blockchainu.</p>
        </form>
        <form id="emailStatusForm" class="card transfer-status" autocomplete="off">
          <h2 class="card-title">Ověřit převod podle ID</h2>
          <div class="field"><label for="transferId">ID převodu při nejasném výsledku</label><input id="transferId" type="text" pattern="[0-9a-f]{32}" minlength="32" maxlength="32" spellcheck="false" autocomplete="off" required></div>
          <button type="submit" class="action action-secondary full-button">Ověřit stav</button>
        </form>
        <div class="send-divider">nebo Lightning adresa</div>
        <form id="addressSendForm" class="card" autocomplete="off">
          <h2 class="card-title">Poslat na Lightning adresu</h2>
          <p class="card-desc">Adresa jiné Lightning služby vypadá jako e-mail, například jmeno@domena.cz. Neposílá se na e-mailovou schránku.</p>
          <div class="field"><label for="lightningAddress">Lightning adresa</label><input id="lightningAddress" type="email" autocomplete="off" placeholder="jmeno@domena.cz" required></div>
          <div class="field"><label for="addressAmount">Částka v sat</label><div class="form-suffix"><input id="addressAmount" type="number" min="1" max="<?= $maxSendSats ?>" step="1" inputmode="numeric" required><span>sat</span></div></div>
          <button type="submit" class="action action-primary full-button">Zkontrolovat adresu a částku</button>
        </form>
        <div class="send-divider">nebo faktura BOLT11</div>
        <form id="sendForm" class="card" autocomplete="off">
          <h2 class="card-title"><svg class="icon" aria-hidden="true"><use href="#i-up"/></svg>Zaplatit fakturu</h2>
          <div class="field"><label for="recipient">Faktura BOLT11</label><textarea id="recipient" rows="5" placeholder="lnbc…" spellcheck="false" autocapitalize="off" maxlength="5000" required></textarea><small>Podporovány jsou faktury s pevnou částkou. BTC adresu sem nevkládejte.</small></div>
          <button type="submit" class="action action-primary full-button">Zkontrolovat platbu</button>
        </form>
        <p class="small-note">Odeslání proběhne až po potvrzení částky v dalším kroku.</p>
      </section>
      <section class="view" data-view="settings" aria-label="Nastavení" hidden>
        <div class="page-head"><p class="eyebrow">Aplikace</p><h1>Nastavení</h1><p class="lead">Účet: <?= html($user['email']) ?></p></div>
        <div class="card settings-card"><button type="button" class="setting-row" id="settingsVisibility"><span class="transaction-icon"><svg class="icon" aria-hidden="true"><use href="#i-eye"/></svg></span><span class="copy"><strong>Viditelnost zůstatku</strong><small id="visibilityText">Částky se zobrazují</small></span><svg class="icon icon-sm" aria-hidden="true"><use href="#i-chevron"/></svg></button></div>
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
