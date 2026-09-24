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

## Technikai megjegyzések

- A kliens a WordPress REST API-n (`/wp-json/iwc/v1/…`) keresztül 4 másodpercenként kérdez le
  (inaktív böngészőfülön ritkábban). Az intervallum az `iwc_poll_interval` szűrővel módosítható (ms).
- Követelmény: WordPress 5.8+, PHP 7.4+.
