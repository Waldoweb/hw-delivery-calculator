=== HW Delivery Calculator ===
Contributors: Hostetler Web
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPL-2.0-or-later

Delivery cost estimate for WooCommerce product pages. The customer picks their
street address and sees the driving distance from the kennel and an estimated
price, e.g.

    About 142 driving miles from our kennel in Hersey, MI.
    Estimated delivery: $284 ($2/mile one-way)
    This is an estimate. We will confirm the delivery price with you...

Information only. It does not touch the cart, checkout or orders, and nothing
is sent to or saved on the site: the address goes from the customer's browser
straight to Google.

== How it works ==

1. The page loads one small script (5 KB compressed) and no Google code.
2. The first time someone taps or clicks into the address field, the script
   loads the Google Maps JavaScript API. This keeps Core Web Vitals clean on
   every page view where nobody uses the calculator.
3. Suggestions come from Places (New) autocomplete: US only, street addresses
   only (Google primary types street_address, premise, subpremise), ranked
   near the kennel first.
4. The chosen address must have a house number and a street, otherwise the
   customer sees "Please enter your full street address" and no price.
5. Routes API returns the one-way driving distance from the kennel
   coordinates. Miles are rounded to a whole number, and the price is
   rounded miles x rate, rounded to whole dollars.
6. Farther than the maximum distance: the "contact us" message instead of a
   price.

Loading, "no match", "no route", Google failing to load and a rejected key all
show a plain message under the field.

== Google Cloud setup (once per site) ==

Use a Google account the business controls. The key is a browser key: anyone
can read it from the page source, so the restrictions below are what keep it
from being used elsewhere.

1. Project and billing
   - https://console.cloud.google.com > project picker > New project, e.g.
     "Michigan Puppy website".
   - Billing > link a billing account. Google requires one even when usage
     stays inside the free monthly allowance.

2. Enable exactly these three APIs
   APIs & Services > Library, search each one and click Enable:
   - Maps JavaScript API
   - Places API (New)       <- the "(New)" one. The legacy "Places API"
                               does not work with this plugin.
   - Routes API

3. Create and restrict the key
   APIs & Services > Credentials > Create credentials > API key, then edit it:
   - Name: e.g. "michiganpuppy.com delivery calculator".
   - Application restrictions: Websites. Add each address the site is served
     on, with /* at the end:
         https://michiganpuppy.com/*
         https://www.michiganpuppy.com/*
     Add a staging domain too if you test there. The settings page (wp-admin)
     is on the same domain, so the lookup button and preview work with this.
   - API restrictions: Restrict key, and tick only
         Maps JavaScript API, Places API (New), Routes API.
   - Save. Changes can take up to 5 minutes to apply.

4. Cap daily usage (protects against a stolen key or a bot)
   For each of the three APIs: APIs & Services > Enabled APIs & services >
   click the API > Quotas & System Limits. Find the per-day request quota
   (names vary, e.g. "... requests per day"), tick it, Edit quota, and set a
   cap. Sensible starting caps for a breeder site:
         Places API (New)        2,000 requests per day
         Routes API                500 requests per day
         Maps JavaScript API       500 per day (if a per-day quota is listed)
   One estimate is a handful of autocomplete requests while typing (sent as
   one session), one place details request and one route request. Raise the
   caps if real customers ever hit them (the calculator then shows "Address
   search is not available right now").

5. Budget alert (recommended)
   Billing > Budgets & alerts > Create budget, e.g. $10/month, with email
   alerts at 50%, 90% and 100%. A budget only alerts; the quota caps are what
   actually stop usage.

Costs: Google bills per request with a free monthly allowance per API, which
normal breeder-site traffic stays well inside. Check
https://mapsplatform.google.com/pricing/ for current numbers.

== Plugin setup ==

WooCommerce > Delivery Calculator (Settings > Delivery Calculator when
WooCommerce is not active).

- API key: the restricted key from above.
- Kennel address: type it, click "Look up coordinates" (uses the key in the
  field), check the pin link, Save. Or paste coordinates by hand: right-click
  the spot in Google Maps and click the numbers at the top to copy them.
  Distances are measured from the saved coordinates only; the WooCommerce
  store address is not used.
  The kennel's street address is never printed on the page; customers only
  see the town below. The coordinates do travel to the browser (the distance
  is worked out there, with Google), so they are in the page source for
  anyone who opens developer tools. Keeping them fully hidden would need the
  site's server to do the route lookup instead.
- Town shown to customers: fills {kennel}, e.g. "Hersey, MI".
- Rate per mile: default 2.00, applied to one-way miles.
- Maximum distance: default 600 miles. 0 = no limit.
- Texts: heading, intro, field label and placeholder, result, note and
  over-limit message. Placeholders anywhere in them:
      {miles}  driving miles, rounded, e.g. 142
      {price}  estimated price, whole dollars, e.g. $284
      {rate}   rate per mile, e.g. $2 or $2.50
      {max}    maximum distance, e.g. 600
      {kennel} town shown to customers
  The note and over-limit message accept basic HTML (e.g. a link to the
  contact page). Clear the heading or intro to hide it.

The preview at the bottom of the settings page runs the real calculator with
the saved settings. Try a full address, a street with no house number, and
somewhere past the maximum distance.

== Placing it ==

Shortcode (either name works):

    [hw_delivery_calculator]
    [hwd_delivery_calculator]

- Oxygen: Shortcode element in the product template.
- Bricks: Shortcode element (or the Shortcode option in a Code/Text element)
  in the single product template.
- Anywhere else that runs shortcodes (post content, widgets).

Per-placement overrides:

    [hw_delivery_calculator heading="Delivery to your door" intro="" heading_tag="h2"]

heading/intro replace the saved texts for that placement (empty hides them);
heading_tag is h2, h3 (default), h4, h5 or p.

Administrators see a red-bordered notice where the shortcode is placed until
the API key and coordinates are saved; visitors see nothing.

== Performance notes ==

- Script: assets/js/hw-delivery-calculator.js, printed in the footer only on
  pages that render the shortcode. CSS (about 4 KB) is inlined with the first
  calculator so there is no layout shift.
- Perfmatters "Delay JavaScript" and WP Rocket "Delay JavaScript execution"
  exclude the script automatically (perfmatters_delay_js_exclusions and
  rocket_delay_js_exclusions filters). For other optimizers, exclude
  "hw-delivery-calculator.js" from delay/lazy-load JS by hand. If it does
  get delayed it still works (on start it checks whether the field already
  has focus), just a moment later.
- Settings are in a data attribute on the box, not an inline script, so page
  caching and JS optimizers do not affect them.

== Accessibility ==

The field is an ARIA 1.2 combobox with a listbox of suggestions: arrow keys
move, Enter picks, Escape closes (and a second Escape clears). Enter or the
phone keyboard's Search/Go key with no suggestion highlighted picks the top
one. Results, errors and the number of suggestions are announced through live
regions. Targets are at least 48px tall and the field uses a 16px font so iOS
does not zoom. On phones, if the on-screen keyboard would hide the list, the
field scrolls up (set --hwdc-scroll-offset on .hwdc for a taller sticky
header, default 96px).

== Styling ==

The box inherits the theme's font and text color. The Styles section of the
settings page sets the fonts (family, text size, heading font, size and
weight) and colors. Each field takes any CSS value, including the theme's or
builder's variables: var(--primary), or just --primary. Empty fields keep the
default. The settings page does not load the theme, so give theme variables a
fallback, var(--primary, #1d6f42), to see them in the preview there.

Each Styles field sets one of these variables on .hwdc, which theme or
builder CSS can also override (settings that are filled in win, since they
print after the theme's stylesheet):

    --hwdc-accent (focus ring and result border, default #1d6f42)
    --hwdc-text, --hwdc-muted, --hwdc-field-text, --hwdc-bg, --hwdc-border,
    --hwdc-hover (result box and highlighted suggestion), --hwdc-warn,
    --hwdc-error, --hwdc-font, --hwdc-font-size, --hwdc-heading-font,
    --hwdc-heading-size, --hwdc-heading-weight, --hwdc-heading-color
    Not in the settings: --hwdc-radius, --hwdc-scroll-offset

The font and heading variables only apply when the matching setting is filled
in; setting them from theme CSS alone does nothing.

To drop the built-in CSS entirely (Styles settings still print):
    add_filter( 'hw_delivery_calculator_inline_css', '__return_false' );

The "Google Maps" line under the suggestions is Google's required attribution
for showing Places results without a map. Keep it visible.

== Developer filters ==

hw_delivery_calculator_config ( array $config, array $settings )
    Everything passed to the browser: key, origin {lat,lng}, rate, max,
    currency, locale, types (Places primary types allowed in suggestions)
    and i18n (all messages).

hw_delivery_calculator_inline_css ( bool )
    False skips the inline CSS.

window.hwDeliveryCalculator.init( element )
    Starts calculators inserted after page load (AJAX, popups).

== Troubleshooting ==

"Address search is not available right now" on the first tap:
  - Browser console shows RefererNotAllowedMapError: add the domain (with
    /*) to the key's website restrictions.
  - ApiNotActivatedMapError or ApiTargetBlockedMapError: one of the three
    APIs is not enabled, or not ticked in the key's API restrictions.
  - Quota exceeded: raise the daily cap.
No suggestions at all for valid addresses: Places API (New) is missing (the
  legacy Places API does not count).
"We could not calculate the distance": Routes API is not enabled or not
  allowed on the key.

== Changelog ==

= 1.1.1 =
* Styles settings: each field's name now sits above it instead of beside it.

= 1.1.0 =
* New Styles section in the settings: font, text size, heading font, size
  and weight, and ten colors. Fields take any CSS value, including theme
  variables (var(--primary), or just --primary). Color fields have a picker,
  and the preview restyles as you type.
* The address field text color can now be changed (--hwdc-field-text).

= 1.0.1 =
* The kennel address field now says it is never shown to customers; the
  readme explains what reaches the browser.
* Example coordinates in the settings error message are now generic.

= 1.0.0 =
* First release: [hw_delivery_calculator] shortcode (alias
  [hwd_delivery_calculator]) with Places (New) autocomplete, Routes driving
  distance, per-mile pricing, maximum distance, editable texts, coordinate
  lookup and live preview on the settings page, Perfmatters/WP Rocket delay
  exclusions, and GitHub auto-updates.
