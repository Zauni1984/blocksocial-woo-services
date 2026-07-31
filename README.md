# BlockSocial WooCommerce Extra Services

> Zusätzliche, kostenpflichtige Versand- und Serviceoptionen, die Kundinnen und Kunden im Warenkorb oder an der Kasse auswählen können.
> Ein Plugin von **BlockSocial UG (haftungsbeschränkt)**.

Ein vollständig modulares WooCommerce-Plugin. Jede Funktion ist ein eigenes
Modul, das einzeln aktiviert oder deaktiviert werden kann. Alle Gebühren werden
**ausschließlich serverseitig** aus der gespeicherten Konfiguration berechnet –
der Browser kann nur den *Schlüssel* einer Auswahl senden, niemals einen Preis.

---

## Funktionen

### Module (einzeln an-/abschaltbar)

| Modul | Beschreibung |
| --- | --- |
| **Als Geschenk verpacken** | Geschenkverpackung gegen Gebühr, optional mit persönlicher Nachricht. |
| **Express & Priorität** | Schnellere Lieferstufen (z. B. Express) gegen Aufpreis. |
| **Versanddienstleister-Wahl** | Kunde wählt den bevorzugten Carrier (z. B. **DHL statt DPD**), jeweils mit eigenem Aufpreis. |
| **Abholung** | Selbstabholung als wählbare Option – **mit oder ohne Gebühr**. Bei Auswahl werden die **Versandkosten auf 0 gesetzt** (nur eine kostenfreie Abhol-Option bleibt). |
| **Lieferung im Umkreis** | Liefergebühr nach Entfernung zum Ladengeschäft (**km-Radius-Stufen**): bis 5 km Gebühr X, bis 10 km Gebühr Y usw. |
| **Eigene Services** | Beliebige eigene Services / Versandextras **manuell anlegen**. |

### Weitere Kernpunkte

- **Alle Module aktivierbar/deaktivierbar** – ein deaktiviertes Modul blendet
  seine Services aus und entfernt jede Gebühr (serverseitig erzwungen).
- **Benutzergruppen-Sichtbarkeit pro Service**: Jede Option kann auf bestimmte
  WordPress-Rollen beschränkt werden. Damit lassen sich z. B. **Dropshipping-**
  oder **Großhandelsgebühren** nur bestimmten Nutzergruppen anzeigen. Ist keine
  Rolle gewählt, sehen alle die Option. Gäste (nicht eingeloggt) sind über die
  virtuelle Rolle „Gast“ ansprechbar.
- **Gebührentypen**: fester Betrag, Prozent vom Zwischensumme oder pro Artikel.
  Negative Beträge wirken als Rabatt (z. B. Abholrabatt).
- **Steuer**: pro Service steuerpflichtig/-frei und mit wählbarer Steuerklasse.
- **Anzeigeort** wählbar: Warenkorb, Kasse oder beides.
- **Bestellübernahme**: Gewählte Services werden an der Bestellung gespeichert
  und in Bestellübersicht, E-Mails und im Admin-Bereich angezeigt.
- **Rechnungen / Germanized**: Jede Gebühr wird als echte Bestellposition
  (`WC_Order_Item_Fee`) mit Name, Betrag und Steuer angelegt und erscheint
  dadurch automatisch auf Rechnungen – auch auf denen von WooCommerce
  Germanized (inkl. Germanized Pro PDF-Rechnungen). Die Fee-Position wird
  zusätzlich mit einer Service-Referenz getaggt; eine Kundennachricht (z. B.
  Geschenktext) wird als sichtbares Positions-Meta unter der Gebühr ausgegeben.
- **HPOS-kompatibel** (High-Performance Order Storage) und kompatibel erklärt
  für Cart-/Checkout-Blocks.

---

## Sicherheit

Sicherheit war eine ausdrückliche Anforderung und wurde durchgängig umgesetzt:

- **Preise niemals aus dem Request**: Der Client sendet nur Auswahl-*Schlüssel*.
  Die tatsächliche Gebühr wird immer neu aus der Serverkonfiguration berechnet
  (`class-bwes-fees.php`) und beim `woocommerce_cart_calculate_fees`-Hook gesetzt.
- **Nonces überall**: AJAX (`check_ajax_referer`), Admin-Formulare
  (`wp_nonce_field` / `check_admin_referer`), Löschlinks (`wp_nonce_url`).
- **Capability-Checks**: Alle Admin-Aktionen erfordern `manage_woocommerce`.
- **Eingaben werden bereinigt**: `sanitize_text_field`, `sanitize_key`,
  `sanitize_textarea_field`, `wp_kses_post`, `absint`, `floatval`, `esc_url_raw`.
  Rollen werden gegen die real registrierten Rollen validiert.
- **Ausgaben werden escaped**: `esc_html`, `esc_attr`, `esc_url`, `esc_textarea`,
  `wc_price` (kes-safe) beim Rendern.
- **Kein direkter Dateizugriff**: Jede Datei bricht ohne `ABSPATH` ab.
- **Sichere Remote-Requests**: Geocoding über `wp_safe_remote_get` mit Timeout,
  Ergebnis-Caching per Transient und „fail-open-frei“-Verhalten (kann keine
  Distanz ermittelt werden, wird keine Gebühr berechnet – der Checkout wird
  nicht blockiert).
- **Nachrichtenlänge begrenzt** (max. 500 Zeichen) gegen Missbrauch.

---

## Architektur

```
blocksocial-woo-services.php      Bootstrap, Autoloader, HPOS-Deklaration
uninstall.php                     Entfernt alle Daten bei Deinstallation
includes/
  class-bwes-plugin.php           Singleton, verdrahtet alle Komponenten
  class-bwes-install.php          Aktivierung + Default-Seeding
  class-bwes-modules.php          Modul-Registry (an/aus)
  class-bwes-services.php         Service-CRUD, Normalisierung, Rollen-Gating
  class-bwes-fees.php             Serverseitige Gebührenberechnung
  class-bwes-geolocation.php      Geocoding + Haversine-Distanz
  class-bwes-frontend.php         Rendering, AJAX, Fees, Bestellspeicherung
  class-bwes-admin.php            Admin-Oberfläche (Module, Services)
assets/                           JS/CSS für Front- und Backend
```

Ein „**Service**“ ist eine einzelne wählbare Option (Checkbox, Auswahl/Radio
oder Distanzstufen). Module liefern Standard-Services, die im Backend bearbeitet
oder um eigene ergänzt werden können.

---

## Installation

1. Ordner `blocksocial-woo-services` nach `wp-content/plugins/` kopieren
   (oder als ZIP hochladen).
2. Plugin im WordPress-Backend aktivieren (WooCommerce muss aktiv sein).
3. **WooCommerce → Extra Services** öffnen.

Beim Aktivieren werden sinnvolle Standardmodule und -services angelegt.

## Konfiguration

- **Reiter „Module & Einstellungen“**: Module an/aus, Anzeigeort (Warenkorb /
  Kasse / beides) und – für die Umkreislieferung – der Ladenstandort
  (Koordinaten empfohlen, alternativ Adresse zum Geokodieren).
- **Reiter „Services“**: Services bearbeiten oder eigene anlegen – Titel,
  Beschreibung, Modul, Eingabetyp, Gebühr/Gebührentyp, Steuer, Nachrichtenfeld,
  sichtbare Rollen, Sortierung und Status.

### Umkreislieferung

Die Gebühr richtet sich nach der Entfernung vom hinterlegten Ladenstandort zur
Lieferadresse. Standard-Geocoder ist OpenStreetMap **Nominatim**; ein anderer
Nominatim-kompatibler Endpunkt kann hinterlegt werden. Bitte die Nutzungsregeln
des jeweiligen Dienstes beachten. Für Produktivbetrieb werden feste
Koordinaten des Ladens empfohlen.

---

## Entwickler-Hooks

```php
// Verfügbare Module verändern.
add_filter( 'bwes_module_definitions', function ( $defs ) { return $defs; } );

// Für die aktuelle Nutzerin verfügbare Services filtern.
add_filter( 'bwes_available_services', function ( $services, $cart ) { return $services; }, 10, 2 );

// Eigene Validierung pro Service (leerer String = gültig).
add_filter( 'bwes_validate_service', function ( $error, $service, $selection, $message ) {
    return $error;
}, 10, 4 );
```

---

## Kompatibilität

- WordPress 6.0+
- WooCommerce 6.0+ (getestet bis 9.5)
- PHP 7.4+
- WooCommerce HPOS

## Lizenz

MIT © 2026 BlockSocial UG (haftungsbeschränkt)
