# Lite Wallet · Lightning

Víceuživatelská peněženka v PHP nad oddělenými peněženkami jednoho účtu LNbits. Každý e-mail má vlastní zůstatek a historii. Přístup se potvrzuje osmimístným **jednorázovým kódem** doručeným přes SMTP; aplikace neposílá trvalá hesla e-mailem. Platbu lze poslat na jiný e-mail nebo BOLT11 fakturu. **Převody na e-mail probíhají přes Lightning**, nejsou to bitcoinové on-chain transakce ani BTC adresy. LNbits a jeho funding source prostředky spravují; uživatelé nemají vlastní seed.

## Požadavky

- PHP **8.1+**, `curl`, `pdo_sqlite`, `sodium`, `openssl`, sessions; HTTPS pro veřejnou doménu.
- Dostupný SMTP server s **STARTTLS** na portu 587 a platným certifikátem.
- LNbits instance umožňující pod vaším účtem vytvořit další peněženku pomocí `POST /api/v1/wallet` s účetním Bearer/ACL tokenem. Verzi a skutečné oprávnění ověřte ve **vlastní instanci** přes `/docs`; obecná dokumentace LNbits se podle verze liší. Token jedné peněženky (`admin_key`) nezakládá další peněženky.
- Soukromý adresář mimo webový kořen pro databázi a `config.php`. SQLite databáze a šifrovací klíč vyžadují pravidelné zálohy; bez šifrovacího klíče se záznamy nedají dešifrovat.

## Instalace a zachování stávající peněženky

1. Udělejte zálohu aplikace a stávajícího `config.php`. Nová verze mění způsob přihlašování a vyžaduje databázi; nevydávejte ji za funkční, dokud nenastavíte SMTP a token účtu. Nasazení proveďte s DocumentRoot **přesně** na `public/`.
2. Zkopírujte `config.example.php` do `config.php` mimo `public/`. Vygenerujte vlastní `app_key` příkazem `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`. Nastavte `app_url`, SMTP a absolutní `database_path` v soukromém zapisovatelném adresáři (např. `data/`). **Token sdílený v chatu nepoužívejte**; vystavte nový token pro správu peněženek, pokud to vaše LNbits podporuje. Ukládejte jej jen na serveru. Omezte přístupová práva `config.php` i adresáře `data/`.
3. Ještě před přihlášením dalších uživatelů přiřaďte **dosavadní** LNbits peněženku svému e-mailu příkazem `php bin/import_wallet.php`. Zadáte e-mail, dosavadní `invoice_key` a `admin_key` **interaktivně na serveru**; skript ověří ID peněženky u LNbits a uloží klíče šifrovaně. Nemigruje ani neodesílá prostředky. Pak se na webu přihlaste tímto e-mailem a kódem.
4. Před otevřením registrace otestujte doručení kódu a vytvoření další **prázdné** peněženky na malých částkách. Upravte `max_send_sats` a `max_invoice_sats`. `history_amount_unit` nastavte dle odpovědi vaší verze LNbits (`msat` nebo `sat`); zůstatek API je v `msat`.

Při reverzní proxy musí webový PHP proces dostávat `$_SERVER['HTTPS']='on'`. Neukládejte klíče ani SMTP heslo do GitHubu nebo JavaScriptu. PHP proces potřebuje zapisovat do složky databáze včetně souborů SQLite `-wal`/`-shm`.

### E-mail a účty

- Formulář vždy požádá o e-mail. Po ověření jednorázovým kódem se vytvoří peněženka, pokud ještě neexistuje; uživatel nezadává trvalé heslo. Kód má platnost 10 minut, pět pokusů a serverové omezení rychlosti odesílání. Opakované požadavky dostávají stejnou obecnou odpověď.
- Relace vyprší po 30 minutách bez uživatelské akce. Automatické obnovení přehledu tuto dobu neprodlužuje.
- Při platbě na dosud neznámý e-mail se vytvoří peněženka příjemce. Po potvrzení částky přijme platbu na vlastní LN fakturu a může se přihlásit teprve po ověření přístupu do schránky. **Před potvrzením zkontrolujte adresu:** překlep nebo nedoručitelná schránka může prostředky uzamknout v peněžence, kterou musí vyřešit provozovatel.
- Před voláním odeslání se uloží ID převodu a příjemcova faktura. Nejistý výsledek spojení se automaticky neopakuje. Stav lze ověřit přes formulář „Ověřit převod podle ID“; kdyby se protokol LNbits a databáze rozešly, zkontrolujte historii obou peněženek přímo na instanci.
- Vytvoření LNbits peněženky není atomické s místní SQLite databází. Při výpadku mezi těmito kroky může zůstat prázdná peněženka bez vazby a lokální účet ve stavu vytváření. Najděte peněženku v LNbits podle názvu `Lite Wallet <prvních 12 znaků ID>` a spojte ji pomocí `php bin/import_wallet.php` se správným e-mailem; **nezkoušejte automaticky vytvářet další peněženku**.

## Struktura

| Složka | Účel |
| --- | --- |
| `public/` | Jediné veřejné PHP vstupy, statické soubory a PWA |
| `src/Http/` + `src/views/` | Tenké controllery, bezpečnostní hlavičky a HTML šablona |
| `src/Domain/` | Přihlašování, platební operace a pravidla převodu |
| `src/Infrastructure/` | SQLite, SMTP, šifrování klíčů a repozitáře |
| `src/Support/` | Konfigurace a izolovaná relace |
| `bin/import_wallet.php` | Jednorázové převzetí dosavadní peněženky |
| `tests/` | Mock LNbits a test e-mailových kódů a plateb |

Lokální test: `python3 tests/smoke.py` (vyžaduje PHP s `pdo_sqlite`, `openssl` v PATH). Test používá dočasné SQLite a falešné servery LNbits a STARTTLS SMTP. Server žádnou živou platbu při testu neprovádí.

QR kódy Lightning faktur se generují lokálně; knihovna [Project Nayuki](https://github.com/nayuki/QR-Code-generator) je použita s MIT licencí. Service worker ukládá jen statické soubory. PHP nasazení nepotřebuje Node.js.
