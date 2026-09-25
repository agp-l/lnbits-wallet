# Lite Wallet · Lightning

Víceuživatelská peněženka v PHP nad oddělenými peněženkami jednoho účtu LNbits. Každý e-mail má vlastní zůstatek a historii. Přístup se potvrzuje osmimístným **jednorázovým kódem** doručeným přes SMTP; aplikace neposílá trvalá hesla e-mailem. Platbu lze poslat na jiný e-mail nebo BOLT11 fakturu. **Převody na e-mail probíhají přes Lightning**, nejsou to bitcoinové on-chain transakce ani BTC adresy. LNbits a jeho funding source prostředky spravují; uživatelé nemají vlastní seed.

## Požadavky

- PHP **8.0+**, `curl`, `pdo_mysql`, `sodium`, `openssl`, sessions; HTTPS pro veřejnou doménu. Lokální XAMPP s PHP 8.0 lze použít na vyzkoušení; pro veřejnou peněženku použijte podporovanou verzi PHP. Původní konfigurace SQLite nadále funguje s `pdo_sqlite`.
- MySQL/MariaDB s tabulkami InnoDB. Tabulky uvidíte v phpMyAdmin; SQLite je alternativní databáze bez serveru a v phpMyAdmin se neotevře. Klíče a záznamy plateb ručně neupravujte.
- Dostupný SMTP server s **STARTTLS** na portu 587 a platným certifikátem.
- LNbits instance umožňující pod vaším účtem vytvořit další peněženku pomocí `POST /api/v1/wallet` s účetním Bearer/ACL tokenem. Verzi a skutečné oprávnění ověřte ve **vlastní instanci** přes `/docs`; obecná dokumentace LNbits se podle verze liší. Token jedné peněženky (`admin_key`) nezakládá další peněženky.
- Soukromý `config.php` mimo webový kořen. Zálohujte databázi **i** `app_key` z konfigurace; bez šifrovacího klíče se uložené LNbits klíče nedají dešifrovat.

## Instalace a zachování stávající peněženky

1. Udělejte zálohu aplikace a dosavadního `config.php`. Nasazení proveďte s DocumentRoot **přesně** na `public/`.
2. V phpMyAdmin na `http://localhost/phpmyadmin/` vytvořte **novou prázdnou databázi** například `lite_wallet` s kódováním `utf8mb4`. Vyberte ji, klikněte na **Importovat**, zvolte soubor [`sql/schema.mysql.sql`](sql/schema.mysql.sql) a spusťte import. Vzniknou tabulky `users`, `login_codes`, `rate_limits` a `transfers`. Nepoužívejte tabulky jiného projektu.
3. V phpMyAdmin otevřete **Uživatelské účty → Přidat uživatelský účet** a vytvořte pro peněženku databázového uživatele s oprávněními `SELECT`, `INSERT`, `UPDATE`, `DELETE` pouze pro tuto databázi; SQL schéma importujte přes účet správce. Zkopírujte `config.example.php` do `config.php` mimo `public/` a vyplňte `database.host`, `database.port`, `database.name`, `database.user` a `database.password`. Pokud databáze běží ve stejném XAMPP/LAMPP, obvykle je `host` `127.0.0.1`; port použijte podle skutečného nastavení MySQL. Ověřte, že **webové PHP** má `pdo_mysql` a je ve verzi PHP 8.0 nebo vyšší. Připojení a všechny tabulky můžete nyní ověřit příkazem `php bin/check_database.php`; vyplněný `app_key` k této kontrole není potřeba.
4. Vygenerujte vlastní `app_key` příkazem `php bin/generate_app_key.php` a zkopírujte celý vypsaný řetězec do hodnoty `'app_key'` v `config.php` mezi apostrofy. Klíč nikomu neposílejte; zálohujte jej spolu s databází. Nastavte `app_url` a SMTP. **Token sdílený v chatu nepoužívejte**; vystavte nový token pro správu peněženek, pokud to vaše LNbits podporuje. Ukládejte jej jen na serveru a omezte práva `config.php`.
5. Ještě před přihlášením dalších uživatelů přiřaďte **dosavadní** LNbits peněženku svému e-mailu příkazem `php bin/import_wallet.php`. Zadáte e-mail, dosavadní `invoice_key` a `admin_key` **interaktivně na serveru**; skript ověří ID peněženky u LNbits a uloží klíče šifrovaně. Nemigruje ani neodesílá prostředky. Pak se na webu přihlaste tímto e-mailem a kódem.
6. Před otevřením registrace otestujte doručení kódu a vytvoření další **prázdné** peněženky na malých částkách. Upravte `max_send_sats` a `max_invoice_sats`. `history_amount_unit` nastavte dle odpovědi vaší verze LNbits (`msat` nebo `sat`); zůstatek API je v `msat`.

Při reverzní proxy musí webový PHP proces dostávat `$_SERVER['HTTPS']='on'`. Neukládejte klíče ani SMTP heslo do GitHubu nebo JavaScriptu. Přes phpMyAdmin uvidíte uživatele a převody; klíče peněženek jsou v databázi zašifrované. Nezadávejte skutečná hesla do ukázkového souboru ani do GitHubu.

### Místní ladění

Při otevření z `localhost` přímo ze stejného počítače se zobrazí i fatální chyby PHP. Pokud prohlížeč stále hlásí HTTP 500 bez textu, aktualizujte soubory (`git pull origin main`), ověřte syntaxi ve webovém PHP příkazem `/opt/lampp/bin/php -l src/App.php` a přečtěte poslední řádky `/opt/lampp/logs/error_log`. CLI příkaz `php -v` může používat jinou verzi než XAMPP. Nepoužívejte veřejnou doménu k ladění výpisem chyb.

Pokud stránka hlásí chybějící rozšíření `sodium`, spusťte `/opt/lampp/bin/php -r 'var_export(extension_loaded("sodium"));'` a zkontrolujte `find /opt/lampp -name sodium.so -print`. Soubor `sodium.so` lze načíst nastavením `extension=sodium` v `/opt/lampp/etc/php.ini` a restartem XAMPP; pokud v instalaci není, je nutné použít PHP sestavené s podporou sodium. Balíček pro systémové PHP sám o sobě neopraví PHP uvnitř XAMPP.

**Už používáte SQLite?** Původní `config.php` s `database_path` dál funguje. Přepnutí na `database` typu MySQL nepřenese účty ani vazby peněženek; stávající data nechte v SQLite, dokud neproběhne řízená migrace. SQLite soubor a jeho `-wal`/`-shm` vyžadují zapisovatelnou složku mimo `public/` a společnou zálohu s `app_key`.

### E-mail a účty

- Formulář vždy požádá o e-mail. Po ověření jednorázovým kódem se vytvoří peněženka, pokud ještě neexistuje; uživatel nezadává trvalé heslo. Kód má platnost 10 minut, pět pokusů a serverové omezení rychlosti odesílání. Opakované požadavky dostávají stejnou obecnou odpověď.
- Relace vyprší po 30 minutách bez uživatelské akce. Automatické obnovení přehledu tuto dobu neprodlužuje.
- Při platbě na dosud neznámý e-mail se vytvoří peněženka příjemce. Po potvrzení částky přijme platbu na vlastní LN fakturu a může se přihlásit teprve po ověření přístupu do schránky. **Před potvrzením zkontrolujte adresu:** překlep nebo nedoručitelná schránka může prostředky uzamknout v peněžence, kterou musí vyřešit provozovatel.
- Před voláním odeslání se uloží ID převodu a příjemcova faktura. Nejistý výsledek spojení se automaticky neopakuje. Stav lze ověřit přes formulář „Ověřit převod podle ID“; kdyby se protokol LNbits a databáze rozešly, zkontrolujte historii obou peněženek přímo na instanci.
- Vytvoření LNbits peněženky není atomické s místní databází. Při výpadku mezi těmito kroky může zůstat prázdná peněženka bez vazby a lokální účet ve stavu vytváření. Najděte peněženku v LNbits podle názvu `Lite Wallet <prvních 12 znaků ID>` a spojte ji pomocí `php bin/import_wallet.php` se správným e-mailem; **nezkoušejte automaticky vytvářet další peněženku**.

## Struktura

| Složka | Účel |
| --- | --- |
| `public/` | Jediné veřejné PHP vstupy, statické soubory a PWA |
| `src/Http/` + `src/views/` | Tenké controllery, bezpečnostní hlavičky a HTML šablona |
| `src/Domain/` | Přihlašování, platební operace a pravidla převodu |
| `src/Infrastructure/` | MySQL/SQLite, SMTP, šifrování klíčů a repozitáře |
| `sql/schema.mysql.sql` | Tabulky pro import přes phpMyAdmin |
| `src/Support/` | Konfigurace a izolovaná relace |
| `bin/import_wallet.php` | Jednorázové převzetí dosavadní peněženky |
| `bin/check_database.php` | Kontrola připojení a schématu bez zápisu |
| `bin/generate_app_key.php` | Vygenerování klíče pro `config.php` |
| `tests/` | Mock LNbits a test e-mailových kódů a plateb |

Lokální test: `python3 tests/smoke.py` (vyžaduje PHP s `pdo_sqlite`, `openssl` v PATH). Test používá dočasné SQLite a falešné servery LNbits a STARTTLS SMTP; MySQL ověřte samostatně před nasazením na živém serveru. Server žádnou živou platbu při testu neprovádí.

QR kódy Lightning faktur se generují lokálně; knihovna [Project Nayuki](https://github.com/nayuki/QR-Code-generator) je použita s MIT licencí. Service worker ukládá jen statické soubory. PHP nasazení nepotřebuje Node.js.
