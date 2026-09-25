# Další přihlašovací metody: návrh

## Současný stav

`users.id` je interní účet; `wallet_id` je stabilní vazba na peněženku LNbits. Dnes `users.email` musí být vyplněný a je jedinečný. Přihlášení používá osmimístný jednorázový kód, nikoli heslo: tabulka `login_codes` drží jen hash rozpracovaného kódu. Po úspěšném ověření se řádek smaže, proto může být tabulka prázdná. Prošlý nepoužitý kód se odmítne, ale řádek může zůstat až do dalšího požadavku na tento e-mail.

## Doporučené pořadí

1. Přidat **passkey** jako volitelnou metodu po ověření e-mailu. E-mailový kód nechat pro první přístup a obnovu. Alternativou je heslo, které si uživatel nastaví sám po ověření e-mailu; neodesílat heslo poštou. Ukládat pouze `password_hash()` a pro obnovu ověřovat vlastnictví e-mailu. Změnu přihlašování provést až s konkrétním návrhem obnovy přístupu k prostředkům.
2. Připravit tabulku `account_identities` se sloupci `user_id`, `type`, `identifier`, `verified_at` a unikátní dvojicí `(type, identifier)`. Stávající e-maily přenést jako identity typu `email`; `users.email` lze změnit na volitelný až po úpravě registrace, převodů na e-mail, notifikací a databázových migrací. Po celou dobu nechat `users.id` a `wallet_id` beze změny.
3. Pro Nostr přijímat veřejný klíč a serverem vydanou jednorázovou výzvu. Klient ji podepíše Nostr signerem; server ověří podpis, čerstvost, doménu a jednorázové použití výzvy. Nikdy nežádat `nsec` do formuláře ani jej neukládat na serveru. Připojení druhé identity ke stávajícímu účtu povolit pouze po ověření v již přihlášené relaci, aby nevznikly dvě peněženky pro jednoho člověka. Ztráta Nostr klíče vyžaduje předem zvolený způsob obnovy.

Název peněženky v LNbits je **pouze štítek**. Nyní odpovídá e-mailu; pro účty bez e-mailu lze později použít rozpoznatelný alias nebo zkrácený veřejný klíč. Nikdy jej nepoužívat jako klíč pro autorizaci nebo párování plateb. E-mail v názvu zároveň zpřístupňuje totožnost správci a každému, kdo má k peněžence oprávněný přístup v LNbits; samotná jedinečnost `wallet_id` anonymitu nezajišťuje.

Linky používá pro vlastní obnovu/přístup `nsec` nebo 20 slov SLIP-39. LNURL-auth v Linky slouží k podepisování přihlášení **na jiné weby**; přihlášení do Linky tímto způsobem nefunguje. Pro zdejší serverovou peněženku je vhodná výzva ověřená proti veřejnému Nostr klíči, nikoli převzetí jejich lokálního modelu uložení seedu.
