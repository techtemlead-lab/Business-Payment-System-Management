# Reference homepage implementation

Historical checkpoint: use [the current requirements-to-code review](COMMISSION-AND-NETWORK-SYSTEM.md#requirements-to-code-review---17-october-2026) for the latest status. Dashboard and financial implementation has advanced since this dated note.

Completed local homepage pass,13 October2026, Asia/Dhaka. User direction: master visual copy of the reference website, public homepage first. This replaces the earlier independent green/cream visual direction.

Implemented navy company/header strip, reference logo, search, Offers/Projects/Account/Shop groups, category navigation/dropdowns, five-slide banner carousel and two side banners, announcement strip, five quick-link cards, category tiles, twenty reference product cards, footer/social/app/payment imagery, floating compare/cart/chat controls, mobile bottom navigation and scroll-to-top. Client-specific company text and local application links replace external account actions. No private reference member data is used.

Source assets and public reference catalog presentations are local in `public/reference/`; the catalog data is a design fixture, not approved Madina inventory, pricing or medical claims. Homepage cart and saved items are browser-local interface state. Requests still use the authenticated application; no online payment or transaction on the reference was performed. Comparison allows selecting up to three reference presentation products and displays their name, image, price and stock. A promotional popup appears after three seconds and respects a24-hour dismissal preference. Company contact routes replace unconfirmed reference contact destinations.

Templates: `resources/views/reference/home.blade.php`; styles: `resources/css/reference-home.css`; interactions: `resources/js/reference-home.ts`. The homepage now shares reference/layout, header and footer templates with the public catalog, product, authentication and information pages. See docs/PUBLIC-WEBSITE-IMPLEMENTATION.md for that checkpoint. Dashboard visual replacement is pending.

Verification: frontend build and TypeScript passed. `docs/qa/homepage-results.json` records15 passing browser checks: HTTP200, five slides, twenty product cards, no desktop/mobile overflow, carousel navigation, cart, saved-item toggle, actual catalog search route, mobile navigation, image decoding, promotional-popup behavior, product comparison and no page JavaScript errors. Tested viewports1536x900 and390x844. Screenshots in `docs/screenshots/reference-home-*`. This is behavioral and visual review, not a pixel-perfect certification of every reference page.

Financial discussion remains deferred at the user's request. Manual company payments, private receipts and payout history remain requirements; unresolved earning/payout policy activation stays gated. Do not change these rules while refining the website design.

Branding update, 13 October 2026: the user supplied `d.jpg` is copied unchanged to `public/madina-header-logo.jpg` and used by the shared public header. CSS trims the outer blank margins while retaining the emblem, company name and tagline. The reference logo is no longer the public header brand.


The later WhatsApp logo replaces the first image in the shared header. The first supplied image remains archived under public/branding/madina-logo-first.jpg. The active file is an unchanged copy of the latest supplied JPEG; a version query refreshes browser caches.

The final supplied h1.png supersedes the WhatsApp JPEG as the header logo. It is copied unchanged to public/madina-header-logo.png and served with version query v=3. Earlier supplied images remain available as source assets; this choice applies to public and portal branding.
