# Víceuživatelská peněženka: návrh

## Hranice systému

- Jedna instance LNbits a jeden účet provozovatele obsluhují oddělené LNbits peněženky. Každý ověřený e-mail v aplikaci patří právě jedné peněžence.
- Uživatel se přihlašuje jednorázovým kódem zaslaným e-mailem. Heslo k účtu LNbits ani klíče jeho peněženky nikdy nepřicházejí do prohlížeče.
- Účetní Bearer token s oprávněním vytvořit peněženku je pouze na PHP serveru. Jednotlivé klíče peněženek se ukládají šifrovaně mimo veřejný adresář.
- Platby na e-mail používají Lightning fakturu vytvořenou peněženkou příjemce. Příjemce musí prokázat vlastnictví schránky, než se do ní přihlásí. E-mail není bitcoinová on-chain adresa.

## Procesy

1. Přihlášení: žádost o kód → omezení frekvence → doručení přes SMTP → ověření kódu → vytvoření peněženky, pokud chybí → relace.
2. Převod: náhled částky a e-mailu → potvrzení → vytvoření příjemce a jeho peněženky podle potřeby → faktura příjemce → trvalý záznam pokusu → jediné volání platby → dotaz na stav faktury. Nejasný výsledek se nikdy automaticky neposílá znovu.
3. První uvedení do provozu: bezpečné přiřazení dosavadní peněženky e-mailu provozovatele; žádný automatický přesun stávajících prostředků.

## Omezení

- Vydání podporuje Lightning (BOLT11), ne bitcoinové on-chain adresy. Převod může být zpoplatněn dle zdroje LNbits.
- Potřebuje dostupné SMTP, PDO SQLite a možnost zakládat další peněženky pro účet na konkrétní instanci LNbits.
- Kódy přihlášení a účetní token nejsou součástí repozitáře ani uživatelského HTML.
