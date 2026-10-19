=== BlockSocial WooCommerce Extra Services ===
Contributors: blocksocial
Tags: woocommerce, shipping, checkout, fees, gift wrapping, express, pickup, local delivery
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.3.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Paid extra shipping options and services (gift wrapping, express, carrier choice, pickup, local radius delivery, custom services) selectable in cart or checkout.

== Description ==

BlockSocial WooCommerce Extra Services lets customers pick and pay for extra
shipping options and services in the cart or at checkout. Every feature is a
module you can switch on or off, and every fee is calculated server-side from
your configuration — the browser can only send a selection key, never a price.

Modules:

* Gift wrapping (fee + optional gift message)
* Express / priority delivery tiers
* Carrier choice (e.g. DHL instead of DPD), each with its own surcharge
* Local pickup, with or without a fee
* Local radius delivery — fee by distance (km tiers) from your store
* Custom services you create manually

Highlights:

* Every module can be enabled/disabled.
* Per-service role visibility, so shipping/handling options (e.g. dropshipping
  or wholesale fees) show only to selected user groups.
* Fee types: flat, percent of subtotal, or per item. Negative values act as a
  discount.
* Per-service tax handling and tax class.
* Choices are saved on the order and shown in order details, emails and admin.
* HPOS compatible.

Built by BlockSocial UG (haftungsbeschränkt).

== Installation ==

1. Upload the `blocksocial-woo-services` folder to `/wp-content/plugins/`.
2. Activate the plugin (WooCommerce must be active).
3. Go to WooCommerce → Extra Services to configure modules and services.

== Security ==

* Nonce protection on all AJAX and admin actions.
* `manage_woocommerce` capability required for all admin changes.
* All input sanitized, all output escaped.
* Fees always recomputed server-side; prices are never trusted from the client.
* Geocoding via wp_safe_remote_get with caching; never blocks checkout on
  failure.

== Frequently Asked Questions ==

= Which languages are included? =

The plugin ships with a translation template (languages/bwes.pot) and complete
German, French and Italian translations. It follows your WordPress site
language automatically. Built-in service labels are localized as well.

= How is the local delivery distance calculated? =

From the store origin (coordinates or address) to the customer's shipping
address, using geocoding (OpenStreetMap Nominatim by default) and the Haversine
formula. Fixed coordinates are recommended for production.

= Can I show an option only to certain customers? =

Yes. Each service has a "Visible to roles" setting. Leave it empty for everyone,
or pick specific roles (including the virtual "Guest" role).

== Changelog ==

= 1.3.0 =
* Automatic updates: licence-gated auto-updates delivered from GitHub releases
  through a vendor proxy (blocksocial.eu), with an optional direct-GitHub mode
  for the vendor's own sites. New "License & Updates" admin tab.
* Added full documentation set under /docs (user guide with screenshots, sales
  kit with pricing, EULA, and the server-side update proxy reference).

= 1.2.0 =
* Local delivery by distance is now "exclusive": selecting it deactivates all
  other services and removes the normal shipping costs. A hint tells customers
  to enter their address to see the distance-based fee.
* New per-service options in the editor: "Exclusive" (deactivates other
  services when selected) and "Hint" (note shown below the option).
* Full internationalization: text domain loading plus translation catalog
  (.pot) and ready-to-use German (de_DE), French (fr_FR) and Italian (it_IT)
  translations. Built-in service labels are localized automatically.

= 1.1.0 =
* Local pickup (and any service flagged "removes shipping costs") now sets the
  shipping cost to zero when selected, replacing the shipping rates with a
  single free pickup option. Any configured pickup fee still applies.
* Fees are tagged onto the order fee line items so they appear correctly on
  invoices (including Germanized), with the customer message shown beneath.

= 1.0.0 =
* Initial release.
