# Verkaufs-Kit — BlockSocial WooCommerce Extra Services

Alles, was du brauchst, um das Plugin über **blocksocial.eu** (WooCommerce) zu
verkaufen: Produkttexte, Preisempfehlung, Lizenz-/Update-Einrichtung und
Go-to-Market. Stand: Oktober 2026.

---

## 1. Produkt auf einen Blick

- **Name:** BlockSocial WooCommerce Extra Services
- **Kategorie:** WooCommerce-Erweiterung (Versand & Services / Checkout)
- **Kurz-Claim:** *Bezahlbare Versand- und Serviceoptionen für deinen
  WooCommerce-Checkout – modular, rollenbasiert, mehrsprachig.*
- **USP:** Sechs Plugins in einem (Geschenkverpackung, Express, Carrier-Wahl,
  Abholung, Umkreislieferung, eigene Services) + rollenbasierte Sichtbarkeit für
  Dropshipping-/Großhandelsgebühren + Germanized-Rechnungen + DE/FR/IT.

### Kurzbeschreibung (für die Produktseite, ~50 Wörter)

> Lass deine Kundschaft im Warenkorb oder an der Kasse bezahlte Extras wählen:
> Geschenkverpackung, Express, Wunsch-Carrier (DHL statt DPD), Abholung und
> Umkreislieferung – oder lege eigene Services an. Jede Option rollenbasiert
> einblendbar (ideal für Dropshipping-Gebühren). Preise serverseitig berechnet,
> sicher, HPOS- und Germanized-kompatibel, auf Deutsch, Französisch & Italienisch.

### Feature-Bullets (für die Produktseite)

- 🎁 Geschenkverpackung mit optionaler Nachricht
- ⚡ Express- & Priority-Lieferstufen gegen Aufpreis
- 🚚 Wunsch-Versanddienstleister (z. B. DHL statt DPD) mit eigenem Aufpreis
- 🏬 Abholung – mit oder ohne Gebühr, setzt Versandkosten auf 0
- 📍 Umkreislieferung mit km-Staffelpreisen (Geocoding + Entfernung)
- 🛠 Eigene Services frei anlegen (fest, prozentual oder pro Artikel)
- 👥 Sichtbarkeit pro Benutzergruppe – Dropshipping-/Großhandelsgebühren
- 🧾 Gebühren erscheinen korrekt auf Rechnungen (inkl. Germanized / Germanized Pro)
- 🌍 Deutsch, Französisch, Italienisch – weitere Sprachen via `.pot`
- 🔒 Sicherheit: Preise immer serverseitig, Nonces, Capability-Checks, HPOS
- 🔄 Automatische Updates nach Kauf (lizenzgebunden)

---

## 2. Preisempfehlung

**Empfehlung (Anker): 79 € / Jahr pro Shop** – inklusive Updates & Support für
12 Monate, danach optionale Verlängerung (z. B. −30 %).

### Begründung (Marktvergleich)

Vergleichbare **Einzel**-Funktionsplugins liegen bei 49–79 € pro Jahr:

| Vergleichsprodukt | Funktion | Preis |
| --- | --- | --- |
| [Conditional Fees for WooCommerce](https://woocommerce.com/products/conditional-fees-for-woocommerce/) | Bedingte Gebühren | ~49 $/J. |
| [Conditional Extra Fees](https://woocommerce.com/products/conditional-extra-fees/) | Erweiterte Gebühren | ~69 $/J. |
| [Gift Wrapper for WooCommerce](https://woocommerce.com/products/gift-wrapper-for-woocommerce/) | Nur Geschenkverpackung | ~79 $/J. |

Unser Plugin bündelt **sechs** solcher Funktionsbereiche plus rollenbasierte
Gebühren, Umkreislieferung, Germanized-Rechnungen und drei Sprachen. Ein
Einstieg bei **79 €/Jahr** ist damit fair und zugleich ein klares Preis-Leistungs-
Argument gegenüber dem Einzelkauf (der schnell 200 €+ kostet).

### Empfohlene Staffel

| Lizenz | Shops | Jahrespreis | Lifetime (einmalig) |
| --- | --- | --- | --- |
| **Starter** | 1 | **79 €** | 249 € |
| **Business** | 5 | 149 € | 449 € |
| **Agency** | 25 | 299 € | 899 € |

- **Launch-Aktion:** −30 % in den ersten 6 Monaten (Starter 55 €) schafft frühe
  Bewertungen und Referenzen.
- **Verlängerung:** 70 % des Erstpreises pro Jahr – marktüblich.
- **MwSt.:** Preise netto kalkulieren; WooCommerce + EU-OSS für korrekte
  Umsatzsteuer (B2C) nutzen.

> Wenn du nur **eine** Zahl brauchst: **79 € / Jahr** als Standardlizenz.

---

## 3. Verkauf über WooCommerce einrichten

### 3.1 Produkt anlegen

1. **Produkte → Erstellen** → Produkttyp **Einfaches Produkt**, Haken bei
   **Virtuell** und **Herunterladbar**.
2. Beschreibung aus Abschnitt 1 übernehmen, Screenshots aus `docs/screenshots/`
   als Galerie hochladen.
3. Als Download die aktuelle Plugin-ZIP hinterlegen (oder leer lassen und die
   Auslieferung komplett über Updates/Lizenz steuern).
4. Preisvarianten über **Variables Produkt** (Attribut „Lizenz": Starter/
   Business/Agency) oder drei Einzelprodukte abbilden.

### 3.2 Lizenzschlüssel ausliefern

Empfohlen: Plugin **License Manager for WooCommerce** (kostenlos) oder ein
vergleichbares Lizenz-Plugin. Es erzeugt pro Bestellung einen Schlüssel und
liefert ihn in Bestellbestätigung und Konto aus.

### 3.3 Automatische Updates (lizenzgebunden, über GitHub)

Die Updates laufen **nicht** direkt aus dem privaten GitHub-Repo in den
Kundenshop (dann müsste ein GitHub-Token mitgeliefert werden – unsicher), sondern
über einen schlanken **Update-Proxy auf blocksocial.eu**:

```
Kundenshop  ──(Lizenzschlüssel)──▶  blocksocial.eu /wp-json/bwes/v1/update
                                         │  prüft Lizenz (License Manager)
                                         │  holt Release von GitHub (Server-Token)
                                         ◀── Version + lizenzgeschützte Download-URL
Kundenshop  ──(Download)──────────▶  blocksocial.eu /download?license=…  ──▶ ZIP
```

- Das GitHub-Token bleibt **serverseitig** auf blocksocial.eu; das private Repo
  bleibt privat.
- Nur aktive Lizenzen erhalten Updates.
- Die Referenz-Implementierung des Proxys liegt bei:
  [`docs/server/blocksocial-update-server.php`](server/blocksocial-update-server.php)
  → als eigenes kleines Plugin auf blocksocial.eu installieren, GitHub-Token und
  License-Manager-Anbindung eintragen, fertig.

**Release-Workflow (bei jeder neuen Version):**

1. Versionsnummer im Plugin erhöhen (Header + `BWES_VERSION` + `readme.txt`).
2. ZIP bauen: `git archive --format=zip --prefix=blocksocial-woo-services/ -o blocksocial-woo-services-x.y.z.zip HEAD`
3. Auf GitHub ein **Release** mit Tag `vX.Y.Z` anlegen und die ZIP als Asset
   anhängen.
4. Fertig – aktive Shops bekommen das Update automatisch angeboten.

> **Entwickler-/eigene Shops** können alternativ den **Direkt-GitHub-Modus**
> nutzen: in `wp-config.php` `define('BWES_GITHUB_TOKEN','…');` setzen – dann zieht
> das Plugin Releases direkt aus GitHub (Token nur für deine eigenen Server!).

---

## 4. Go-to-Market – Empfehlungen

- **Freemium-Funnel:** eine kostenlose „Lite"-Version (nur Geschenkverpackung +
  Abholung) auf wordpress.org als Reichweiten- und Lead-Kanal, Pro-Upsell im
  Plugin. Erfahrungsgemäß der stärkste Hebel für WP-Plugin-Verkäufe.
- **Bundle:** gemeinsam mit weiteren BlockSocial-Plugins als Paket (höherer
  Warenkorb, weniger Support pro Euro).
- **Alternative zur Eigenbau-Lizenz:** [Freemius](https://freemius.com) oder
  [Easy Digital Downloads + Software Licensing] übernehmen Checkout, Lizenzen,
  Updates, EU-MwSt. und Affiliate schlüsselfertig – spart Betrieb, kostet Marge
  (~7 %). Für Start mit wenig Aufwand überlegenswert; der mitgelieferte Updater
  funktioniert mit beiden (Proxy-Endpunkt anpassen).
- **Vertrauen:** Demo-Video (die Screenshots hier als Storyboard), 14-Tage-Geld-
  zurück, sichtbare Changelog- und Support-Policy.
- **SEO/Content:** je ein Ratgeber pro Use-Case („DHL statt DPD anbieten",
  „Dropshipping-Gebühren in WooCommerce", „Lieferung im Umkreis").

---

## 5. Support & Recht

- **Support-Kanal:** support@blocksocial.eu, Reaktionszeit im Angebot nennen.
- **Lizenzbedingungen:** siehe [`docs/EULA.md`](EULA.md). Wichtig: WordPress-
  Plugins sind GPL-pflichtig – verkauft werden **Updates, Support und die
  Bequemlichkeit**, nicht ein proprietäres Nutzungsverbot. Die EULA ist darauf
  ausgelegt.
- **DSGVO:** Das Plugin sendet bei aktiver Lizenz Shop-URL + Lizenzschlüssel an
  blocksocial.eu (Update-/Lizenzprüfung) und – nur im Umkreismodul – die
  Lieferadresse an den konfigurierten Geocoder. In der Datenschutzerklärung des
  Kundenshops erwähnen (Hinweis liegt der Doku bei).

---

© 2026 BlockSocial UG (haftungsbeschränkt) · blocksocial.eu
