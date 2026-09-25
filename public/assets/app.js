(() => {
  'use strict';
  const $ = (query) => document.querySelector(query);
  const $$ = (query) => [...document.querySelectorAll(query)];
  const screen = $('#screen');
  const modal = $('#detailModal');
  const csrf = $('meta[name="csrf-token"]').content;
  const formatSat = (msat) => new Intl.NumberFormat('cs-CZ', { maximumFractionDigits: 3 }).format(Number(msat) / 1000);
  let masked = false;
  let activeInvoice = null;
  let invoicePoll = null;
  let toastTimer = null;
  let paymentRows = [];

  async function api(action, data, params = {}) {
    const options = { credentials: 'same-origin', cache: 'no-store' };
    if (data !== undefined) {
      options.method = 'POST';
      options.headers = { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf };
      options.body = JSON.stringify(data);
    }
    const query = new URLSearchParams({ action, ...params });
    const response = await fetch(`api.php?${query}`, options);
    if (response.status === 401) { location.reload(); throw new Error('Přihlášení vypršelo.'); }
    const json = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(json.error || `Chyba ${response.status}`);
    return json;
  }

  function toast(message) {
    $('#toast').textContent = message;
    $('#toast').hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { $('#toast').hidden = true; }, 5000);
  }
  function setPrivate(node, value) {
    node.dataset.actual = value;
    node.textContent = masked ? '••••••' : value;
  }
  function toggleBalance() {
    masked = !masked;
    $$('[data-private]').forEach((node) => { node.textContent = masked ? '••••••' : (node.dataset.actual || node.textContent); });
    $('#balanceToggle').setAttribute('aria-pressed', String(masked));
    $('#balanceToggle').setAttribute('aria-label', masked ? 'Zobrazit částky' : 'Skrýt částky');
    $('#balanceToggle use').setAttribute('href', masked ? '#i-eye-off' : '#i-eye');
    $('#visibilityText').textContent = masked ? 'Částky jsou skryté' : 'Částky se zobrazují';
  }

  function closeMenu(focus = true) {
    if ($('#drawer').hidden) return;
    $('#drawer').hidden = true; $('#drawerBackdrop').hidden = true;
    $('#topbar').inert = false; screen.inert = false; $('#bottomNav').inert = false;
    $('#menuOpen').setAttribute('aria-expanded', 'false');
    if (focus) $('#menuOpen').focus();
  }
  function openMenu() {
    $('#drawer').hidden = false; $('#drawerBackdrop').hidden = false;
    $('#topbar').inert = true; screen.inert = true; $('#bottomNav').inert = true;
    $('#menuOpen').setAttribute('aria-expanded', 'true'); $('#menuClose').focus();
  }
  function goTo(view) {
    const target = $(`.view[data-view="${view}"]`);
    if (!target) return;
    closeMenu(false);
    $$('.view').forEach((item) => { item.hidden = item !== target; });
    $$('[data-go]').forEach((item) => {
      if (item.closest('.bottom-nav, .drawer')) {
        if (item.dataset.go === view) item.setAttribute('aria-current', 'page');
        else item.removeAttribute('aria-current');
      }
    });
    screen.scrollTop = 0;
    screen.focus({ preventScroll: true });
    if (view === 'activity') refresh();
    if (view === 'send') checkPendingTransfer();
  }

  function showModal(title, description, details, action) {
    $('#modalTitle').textContent = title;
    $('#modalDescription').textContent = description;
    const dl = $('#modalDetails'); dl.replaceChildren();
    for (const [label, value] of details) {
      const dt = document.createElement('dt'); dt.textContent = label;
      const dd = document.createElement('dd'); dd.textContent = value;
      dl.append(dt, dd);
    }
    const actions = $('#modalActions'); actions.replaceChildren();
    if (action) {
      const cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'action action-secondary full-button'; cancel.textContent = 'Zrušit';
      cancel.addEventListener('click', () => modal.close());
      const confirm = document.createElement('button'); confirm.type = 'button'; confirm.className = 'action action-primary full-button'; confirm.textContent = 'Potvrdit a zaplatit';
      confirm.addEventListener('click', async () => {
        confirm.disabled = true; cancel.disabled = true;
        try {
          const outcome = await action();
          modal.close();
          if (outcome) showModal(outcome.title, outcome.description, outcome.details);
        }
        catch (error) { modal.close(); showModal('Stav platby', error.message, []); }
      });
      actions.append(cancel, confirm);
    } else {
      const close = document.createElement('button'); close.type = 'button'; close.className = 'action action-primary full-button'; close.textContent = 'Rozumím'; close.addEventListener('click', () => modal.close());
      actions.append(close);
    }
    modal.showModal();
  }
  function icon(direction) {
    const span = document.createElement('span'); span.className = `transaction-icon ${direction}`;
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); svg.classList.add('icon');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use'); use.setAttribute('href', direction === 'out' ? '#i-up' : '#i-down');
    svg.append(use); span.append(svg); return span;
  }
  function paymentNode(row) {
    const outgoing = row.amount_msat < 0;
    const amount = `${outgoing ? '−' : '+'}${formatSat(Math.abs(row.amount_msat))}`;
    const status = ({ success: 'Zaplaceno', pending: 'Čeká', failed: 'Neúspěšná platba' })[row.status] || 'Stav neznámý';
    const date = row.time ? new Intl.DateTimeFormat('cs-CZ', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(row.time * 1000)) : 'Neznámé datum';
    const button = document.createElement('button'); button.type = 'button'; button.className = 'transaction';
    const info = document.createElement('span'); info.className = 'transaction-info';
    const title = document.createElement('strong'); title.textContent = row.memo || (outgoing ? 'Odeslaná platba' : 'Přijatá platba');
    const subtitle = document.createElement('small'); subtitle.textContent = `${date} · ${status}`;
    info.append(title, subtitle);
    const value = document.createElement('span'); value.className = 'transaction-value';
    const figure = document.createElement('strong'); figure.className = outgoing ? 'out' : 'in'; figure.dataset.private = ''; setPrivate(figure, amount);
    const unit = document.createElement('small'); unit.textContent = 'sat'; value.append(figure, unit);
    button.append(icon(outgoing ? 'out' : 'in'), info, value);
    button.addEventListener('click', () => showModal(outgoing ? 'Odeslaná platba' : 'Přijatá platba', row.memo || status, [
      ['Částka', masked ? 'Skryto' : `${amount} sat`], ['Datum', date], ['Stav', status], ['ID', row.id || '—']
    ]));
    return button;
  }
  function renderList(target, rows) {
    target.replaceChildren();
    if (!rows.length) { const p = document.createElement('p'); p.className = 'empty-state'; p.textContent = 'Zatím žádné platby.'; target.append(p); return; }
    rows.forEach((row) => target.append(paymentNode(row)));
  }
  async function refresh() {
    try {
      const data = await api('summary');
      setPrivate($('#balance'), formatSat(data.balance_msat));
      $('#walletName').textContent = data.name;
      $('#connection').textContent = 'Připojeno';
      paymentRows = data.payments;
      renderList($('#recentList'), paymentRows.slice(0, 4));
      renderList($('#historyList'), paymentRows);
    } catch (error) {
      $('#connection').textContent = 'Nedostupné';
      toast(error.message);
    }
  }
  async function copy(text) {
    try { await navigator.clipboard.writeText(text); toast('Faktura zkopírována.'); }
    catch (_) { toast('Kopírování není dostupné. Text lze označit ručně.'); }
  }
  function displayQr(invoice) {
    const target = $('#invoiceQr'); target.replaceChildren();
    try {
      const qr = qrcodegen.QrCode.encodeText(invoice.toUpperCase(), qrcodegen.QrCode.Ecc.MEDIUM);
      // Locally bundled MIT QR generator. The encoded text comes only from LNbits.
      const border = 4;
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('viewBox', `0 0 ${qr.size + border * 2} ${qr.size + border * 2}`);
      svg.setAttribute('role', 'img');
      svg.setAttribute('aria-label', 'QR kód Lightning faktury');
      const background = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
      background.setAttribute('width', '100%'); background.setAttribute('height', '100%'); background.setAttribute('fill', '#fff');
      const pixels = [];
      for (let y = 0; y < qr.size; y++) {
        for (let x = 0; x < qr.size; x++) {
          if (qr.getModule(x, y)) pixels.push(`M${x + border},${y + border}h1v1h-1z`);
        }
      }
      const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      path.setAttribute('d', pixels.join('')); path.setAttribute('fill', '#111');
      svg.append(background, path); target.append(svg);
    } catch (_) { target.textContent = 'Faktura je pro QR kód příliš dlouhá. Zkopírujte její text.'; }
  }
  async function checkInvoice() {
    if (!activeInvoice) return;
    if (Date.now() / 1000 > activeInvoice.expires_at) { $('#invoiceStatus').textContent = 'Platnost faktury vypršela. Vytvořte novou.'; stopInvoicePoll(); return; }
    try {
      const state = await api('status', undefined, { id: activeInvoice.id });
      if (state.paid || state.status === 'success') {
        $('#invoiceStatus').textContent = 'Zaplaceno ✓'; stopInvoicePoll(); refresh();
      } else if (state.status === 'failed') { $('#invoiceStatus').textContent = 'Platba se nezdařila.'; stopInvoicePoll(); }
    } catch (_) { $('#invoiceStatus').textContent = 'Stav teď nelze ověřit. Zkouším znovu…'; }
  }
  function stopInvoicePoll() { clearInterval(invoicePoll); invoicePoll = null; }

  async function checkPendingTransfer() {
    const notice = $('#transferNotice');
    try {
      const { state } = await api('email_latest_status');
      const messages = {
        pending: 'Stav posledního převodu na e-mail se stále ověřuje. Neodesílejte ho znovu.',
        paid: 'Poslední převod na e-mail dorazil. Podrobnosti najdete v historii.',
        failed: 'Poslední převod na e-mail se nezdařil. Podrobnosti najdete v historii.',
      };
      notice.hidden = !messages[state];
      if (!notice.hidden) notice.textContent = messages[state];
    } catch (_) {
      notice.hidden = false;
      notice.textContent = 'Stav převodu teď nelze ověřit. Před dalším odesláním zkontrolujte historii.';
    }
  }

  $('#receiveForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const amount = Number($('#receiveAmount').value);
    if (!Number.isSafeInteger(amount) || amount <= 0) { toast('Zadejte kladné celé číslo v sat.'); return; }
    const button = event.target.querySelector('[type="submit"]'); button.disabled = true;
    try {
      const data = await api('receive', { amount, memo: $('#receiveMemo').value.trim() });
      stopInvoicePoll(); activeInvoice = data; $('#invoiceText').textContent = data.invoice;
      displayQr(data.invoice); $('#invoiceCard').hidden = false;
      $('#invoiceStatus').textContent = `Čeká na zaplacení · ${formatSat(data.amount_sats * 1000)} sat`;
      invoicePoll = setInterval(() => { if (!document.hidden) checkInvoice(); }, 5000);
      $('#invoiceCard').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      refresh();
    } catch (error) { toast(error.message); }
    finally { button.disabled = false; }
  });
  $('#copyInvoice').addEventListener('click', () => { if (activeInvoice) copy(activeInvoice.invoice); });
  let sendMode = 'email';
  const isInvoice = (value) => /^ln(?:bc|tb|bcrt)/i.test(value.trim());
  function syncSendFields() {
    const email = sendMode === 'email';
    const invoice = !email && isInvoice($('#externalRecipient').value);
    $('#emailRecipientField').hidden = !email;
    $('#externalRecipientField').hidden = email;
    $('#emailRecipient').disabled = !email;
    $('#externalRecipient').disabled = email;
    $('#sendAmountField').hidden = invoice;
    $('#sendAmount').disabled = invoice;
    $('#sendDescription').textContent = email
      ? 'Příjemci založíme peněženku, pokud ji ještě nemá. V oznámení uvidí váš e-mail jako odesílatele a přihlásí se kódem.'
      : 'Zadejte Lightning adresu jiné peněženky nebo vložte fakturu. Příjemci se nezakládá účet Lite Wallet ani neposílá e-mail.';
    $('#externalHint').textContent = invoice
      ? 'Částka je už uvedena ve faktuře a zobrazí se před potvrzením.'
      : 'Lightning adresa vypadá jako e-mail, ale platba nejde do e-mailové schránky. Vložte také fakturu začínající lnbc…';
    $$('[data-send-mode]').forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.sendMode === sendMode)));
  }
  $$('[data-send-mode]').forEach((button) => button.addEventListener('click', () => {
    sendMode = button.dataset.sendMode;
    syncSendFields();
  }));
  $('#externalRecipient').addEventListener('input', syncSendFields);
  $('#sendForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = event.target.querySelector('[type="submit"]'); button.disabled = true;
    try {
      const external = sendMode === 'external';
      const recipient = external ? $('#externalRecipient').value.trim() : $('#emailRecipient').value.trim();
      const invoice = external && isInvoice(recipient);
      const amount = Number($('#sendAmount').value);
      if (!invoice && (!Number.isSafeInteger(amount) || amount <= 0)) throw new Error('Zadejte platnou částku v sat.');
      if (!external) {
        const preview = await api('email_preview', { email: recipient, amount });
        // The server records this transfer before asking LNbits to pay it.
        showModal('Potvrdit převod', 'Posíláte člověku e-mailem přes Lite Wallet. Pokud účet ještě nemá, založíme mu jej a v oznámení uvidí váš e-mail jako odesílatele. Převod může mít poplatek.', [
          ['E-mail příjemce', preview.email], ['Částka', `${formatSat(preview.amount_msat)} sat`]
        ], async () => {
          try {
            const sent = await api('email_send', { token: preview.token });
            $('#emailRecipient').value = ''; $('#sendAmount').value = '';
            refresh(); checkPendingTransfer();
            return { title: 'Převod zadán', description: sent.message, details: [] };
          } catch (error) {
            checkPendingTransfer();
            throw error;
          }
        });
      } else if (invoice) {
        const preview = await api('preview', { invoice: recipient });
        showModal('Potvrdit platbu', 'Platíte fakturu do jiné Lightning peněženky. Platba může zahrnovat poplatek za směrování.', [
          ['Částka', `${formatSat(preview.amount_msat)} sat`], ['Popis', preview.description || 'Bez popisu']
        ], async () => {
          const sent = await api('send', { token: preview.token });
          $('#externalRecipient').value = ''; syncSendFields();
          refresh();
          return { title: 'Platba odeslána', description: 'Požadavek byl přijat. Ověřte výsledek v historii.', details: [] };
        });
      } else {
        const preview = await api('address_preview', { address: recipient, amount });
        showModal('Potvrdit platbu', 'Platíte do jiné Lightning peněženky. Na tento e-mail neposíláme zprávu ani nezakládáme účet Lite Wallet. Platba může mít poplatek.', [
          ['Lightning adresa', preview.address], ['Částka', `${formatSat(preview.amount_msat)} sat`],
          ['Popis', preview.description || 'Bez popisu']
        ], async () => {
          const sent = await api('address_send', { token: preview.token });
          $('#externalRecipient').value = ''; $('#sendAmount').value = '';
          refresh();
          return { title: 'Platba zadána', description: sent.message, details: [] };
        });
      }
    } catch (error) { toast(error.message); }
    finally { button.disabled = false; }
  });
  $('#passwordForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = event.target.querySelector('[type="submit"]'); button.disabled = true;
    const message = $('#passwordMessage');
    try {
      await api('password_set', { current: $('#currentPassword').value,
        password: $('#newPassword').value, confirmation: $('#confirmPassword').value });
      event.target.reset();
      message.hidden = false;
      message.textContent = 'Heslo je uložené. Odteď se jím můžete přihlásit.';
      $('#passwordDescription').textContent = 'Heslo můžete změnit zde. Pokud ho zapomenete, přihlaste se e-mailovým kódem.';
    } catch (error) {
      message.hidden = false; message.textContent = error.message;
    } finally { button.disabled = false; }
  });

  $$('[data-go]').forEach((button) => button.addEventListener('click', () => goTo(button.dataset.go)));
  $('#menuOpen').addEventListener('click', openMenu);
  $('#menuClose').addEventListener('click', () => closeMenu());
  $('#drawerBackdrop').addEventListener('click', () => closeMenu());
  $('#drawer').addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeMenu();
    if (event.key !== 'Tab') return;
    const buttons = $$('#drawer button');
    if (event.shiftKey && document.activeElement === buttons[0]) { event.preventDefault(); buttons[buttons.length - 1].focus(); }
    else if (!event.shiftKey && document.activeElement === buttons[buttons.length - 1]) { event.preventDefault(); buttons[0].focus(); }
  });
  $$('[data-close-modal]').forEach((button) => button.addEventListener('click', () => modal.close()));
  modal.addEventListener('click', (event) => { if (event.target === modal) modal.close(); });
  $('#balanceToggle').addEventListener('click', toggleBalance);
  $('#settingsVisibility').addEventListener('click', toggleBalance);
  $('#refreshHistory').addEventListener('click', refresh);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) { refresh(); checkInvoice(); } });
  setInterval(() => { if (!document.hidden) { refresh(); checkPendingTransfer(); } }, 30000);
  if ('serviceWorker' in navigator && location.protocol === 'https:') navigator.serviceWorker.register('sw.js').catch(() => {});
  refresh();
  checkPendingTransfer();
})();
