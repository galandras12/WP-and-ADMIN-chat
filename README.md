# Belső Chat – WordPress plugin

Kizárólag belsős csevegés a WordPress felhasználóinak, asztali Messenger stílusú lebegő chat ablakkal.

## Telepítés

1. Tömörítsd be az `internal-chat` mappát (`internal-chat.zip`), vagy másold a `wp-content/plugins/` alá.
2. WordPress admin → Bővítmények → Új hozzáadása → Bővítmény feltöltése → aktiválás.
3. Admin menü → **Belső Chat** → **Új szoba**.

## Funkciók

- **Több chat szoba**, mindegyiknél checkboxokkal választható, hogy mely szerepkörök a tagjai
  (Feliratkozó, Közreműködő, Szerző, Szerkesztő, valamint az esetleges egyéb szerepkörök).
  Az adminisztrátorok mindig tagjai minden szobának. A tagok olvashatnak és írhatnak.
- **Visszaolvasható előzmények**: alapból 1 év, legördülő menüben bővíthető 2, 3, 4, 5 vagy 10 évre.
  A régebbi üzenetek nem törlődnek, csak nem jelennek meg, így a korlát bármikor növelhető.
- **Utólagos szerkesztés**: név, logó, tagok, előzmények és láthatóság bármikor módosítható.
- **Szoba logó**: a médiatárból feltölthető, a chat ablak tetején a szoba neve előtt jelenik meg.
- **Láthatóság szobánként**: *Minden oldalon* (weboldal + admin felület) vagy *Csak az admin felületen*.
  A chat csak bejelentkezett tagoknak jelenik meg.
- **Új üzenet**: a chat ablak felugrik (kicsinyített vagy bezárt állapotból is), az új üzenet vastagon kiemelve
  jelenik meg. Amikor a felhasználó a lenti szövegbeviteli mezőre kattint, az üzenetek visszaváltanak normál betűre.
  Ha másik szobában jön új üzenet, piros számláló jelzi.
- **Üzenet megjelenés**: felül a felhasználónév, alatta kicsi, halvány szürke időbélyeg, majd az üzenet szövege.
- **Szobaváltás**: a fejlécben a szoba nevére kattintva lenyílik az elérhető szobák listája.
  Ha nincs másik szoba, a kattintás nem csinál semmit.
- **Valós idejű**: az új üzenetek kb. 1 másodpercen belül megérkeznek (lásd lent).
- Enter = küldés, Shift+Enter = új sor. Felfelé görgetve a régebbi üzenetek betöltődnek.

## Adatmegőrzés (frissítés / törlés esetén)

Minden adat saját adatbázis-táblákban van (a WordPress táblaelőtaggal, pl. `wp_`):

| Tábla | Tartalom |
|---|---|
| `wp_iwc_rooms` | szobák, logó, jogosultságok, előzmény-beállítás, láthatóság |
| `wp_iwc_messages` | üzenetek (a küldő neve is el van mentve, így törölt felhasználónál is megmarad) |
| `wp_iwc_reads` | ki meddig olvasta az egyes szobákat |

- A plugin **semmilyen esetben nem töröl táblát vagy adatot**: nincs `uninstall.php`, nincs uninstall hook,
  a deaktiválás nem csinál semmit.
- Frissítéskor a séma csak bővül (`dbDelta`), meglévő adat nem módosul.
- Törlés és újratelepítés után a szobák, jogosultságok és beszélgetések automatikusan újra elérhetők.
- Szobát az admin felületen nem lehet véglegesen törölni, csak **archiválni** (és visszaállítani),
  így a beszélgetések véletlenül sem vesznek el.
- A logók a WordPress médiatárban vannak, azokat sem érinti a plugin törlése.

Ha valaha véglegesen el akarod távolítani az adatokat, a fenti három táblát és a `iwc_db_version`
opciót kézzel kell törölni (pl. phpMyAdminban).

## Valós idejű működés (Server-Sent Events)

Az új üzenetek kb. 1 másodpercen belül megjelennek (a teszteken 0,1–0,3 mp), külön szerver nélkül.

- A böngésző egy nyitva tartott kapcsolaton (`/wp-json/iwc/v1/stream`, Server-Sent Events) kapja a változásokat.
  A szerver másodpercenként egy olcsó ellenőrzést végez, és csak változáskor küld adatot.
- Egy kapcsolat legfeljebb 25 másodpercig él, utána a szerver lezárja és a böngésző azonnal újranyitja,
  így a PHP folyamatok nem ragadnak be.
- Háttérben lévő böngészőfül nem tart nyitva kapcsolatot, csak kb. 16 másodpercenként kérdez le.
- Ha a tárhely nem támogatja a streamet, a chat magától visszavált 4 másodperces lekérdezésre.
- **Belső Chat → Beállítások**: a valós idejű mód kikapcsolható.

**Terhelés:** minden látható, nyitott chat ablak egy PHP folyamatot foglal le. Osztott tárhelyen, ahol kevés
a PHP worker, sok egyidejű felhasználónál ez lassíthatja az oldalt. Ilyenkor kapcsold ki a valós idejű módot,
vagy csökkentsd a kapcsolat élettartamát.

**Szerverbeállítás:** nginx esetén a plugin küld `X-Accel-Buffering: no` fejlécet. Ha az üzenetek csak
csomagokban érkeznek, a szerver (pl. Apache `mod_deflate`, Cloudflare) puffereli a választ: a
`text/event-stream` típusnál ki kell kapcsolni a tömörítést/pufferelést.

## Technikai megjegyzések

- Szűrők: `iwc_stream_lifetime` (mp, alapból 25), `iwc_stream_interval` (mp, alapból 1),
  `iwc_poll_interval` (ms, tartalék lekérdezés, alapból 4000).
- Követelmény: WordPress 5.8+, PHP 7.4+.
