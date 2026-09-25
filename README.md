# Lite Wallet · Lightning

Samostatná webová peněženka v PHP nad **jednou** peněženkou LNbits. Vychází ze schváleného mobilního vzhledu Lite Wallet. Ukazuje skutečný zůstatek a historii, vystavuje faktury s QR kódem, ověřuje zaplacení a po potvrzení uživatelem platí faktury BOLT11. Na serveru ani v telefonu negeneruje seed. BTC adresy a on-chain platby nejsou součástí této verze.

## Instalace

1. Na serveru mějte PHP **8.1+** s rozšířením `curl`, zapnutými sessions a HTTPS pro veřejný přístup. LNbits musí být dostupné z PHP serveru.
2. Zkopírujte `config.example.php` do `config.php` v kořeni projektu. Z LNbits peněženky vložte **invoice/read key** a **admin key**; klíče neposílejte do prohlížeče. Nastavte svůj server `lnbits_url` (například `https://lnbits.cz`).
3. Vytvořte hash vlastního hesla příkazem `php -r 'echo password_hash(readline("New password: "), PASSWORD_DEFAULT), PHP_EOL;'` a vložte výsledek do `password_hash`. Dlouhé náhodné heslo uchovejte odděleně.
4. Nastavte webový kořen (DocumentRoot) **přesně** na složku `public/`. Soubory `config.php`, `src/` a ostatní soubory v kořeni projektu nesmějí být veřejně přístupné. Pro lokální vyzkoušení spusťte z kořene projektu `php -S localhost:8000 -t public` a otevřete `http://localhost:8000`.
5. V `max_send_sats` zvolte nejvyšší částku jedné platby. Začněte s malým limitem a testovací peněženkou. Při prvním použití porovnejte částky v historii s LNbits; `history_amount_unit` nastavte na `msat` pro starší API nebo `sat`, pokud vaše instance vrací částku historie v sat. Zůstatek `/api/v1/wallet` je v msat.

Při provozu za HTTPS reverzní proxy musí PHP správně vidět `$_SERVER['HTTPS'] = 'on'`. Nezakládejte bezpečnostní rozhodnutí na libovolném `X-Forwarded-Proto` od návštěvníka.

## Chování plateb

- **Přijmout:** `POST /api/v1/payments` s `out:false`, pevnou částkou v sat a hodinovou platností. QR kód se generuje lokálně v prohlížeči, jeho text se neposílá externí QR službě. Stav faktury se pravidelně ověřuje přes PHP.
- **Odeslat:** vložte BOLT11 fakturu s pevnou částkou. PHP načte a zkontroluje částku v prefixu BOLT11, vyžádá dekódování od LNbits a v druhém kroku vyžaduje potvrzení. Pro samotné zaplacení použije admin key. Faktury bez pevné částky, LNURL a on-chain adresy nejsou podporovány.
- **Po výpadku spojení při odesílání:** výsledek může být nejistý. Aplikace stejný požadavek automaticky neopakuje; před dalším pokusem zkontrolujte LNbits a historii. LNbits může připočíst poplatek za směrování nad částku na faktuře.
- **Historie:** nejvýše posledních 100 záznamů. U starších LNbits se mohou pole stavu nebo časové údaje mírně lišit; při první instalaci porovnejte s nativním rozhraním instance.

Přístupové klíče patří pouze do `config.php`, mimo webový kořen. Jedno heslo zde chrání jedinou LNbits peněženku. Pro účty více zákazníků je třeba samostatné ověřování uživatelů, oddělené peněženky a pravidla pro správu klíčů. Lightning prostředky spravuje provozovatel připojené LNbits instance a její zdroj financování; nejde o peněženku se seedem u uživatele.

Web lze připnout na plochu. Service worker ukládá **pouze statické CSS, JS a ikonu**; HTML, API odpovědi, faktury a klíče neukládá. Platby a aktuální zůstatek vyžadují připojení.

### Soubory

| Soubor | Úloha |
| --- | --- |
| `public/index.php` | Přihlášení a původní design Lite Wallet upravený pro LN |
| `public/api.php` | Autorizované operace, limity, potvrzení platby |
| `src/LnbitsClient.php` | cURL adaptér k LNbits bez přesměrování a s ověřením TLS |
| `src/InvoiceAmount.php` | Čtení pevné částky z BOLT11 |
| `public/assets/app.js` | Navigace, zobrazení, QR, obnova a potvrzení |
| `public/assets/qrcodegen.js` | QR Code generator od Project Nayuki (MIT licence ponechána v souboru) |

Projekt nevyžaduje Node.js při provozu. Přibalený QR JavaScript vznikl překladem MIT zdroje [Project Nayuki](https://github.com/nayuki/QR-Code-generator) do JavaScriptu.
