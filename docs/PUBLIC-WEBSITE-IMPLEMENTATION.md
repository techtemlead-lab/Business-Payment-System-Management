# Public website checkpoint

Historical checkpoint: use [the current requirements-to-code review](COMMISSION-AND-NETWORK-SYSTEM.md#requirements-to-code-review---17-october-2026) for the latest status. Later code supersedes this page's dated remaining-work list.

13 October 2026, Asia/Dhaka. User authorized the local build and requested the reference design, public website first. The supplied Madina logo is the shared header brand.

The catalog, product detail, account login, forgot/reset password, about, dealer directory and contact routes now use the shared reference header/footer and navy, orange, blue and pale gray styling. The homepage remains functional. Layout evidence is recorded in `../../planning/evidence/reference-public-pages-layout.md`: login/product screenshots support their appearance; catalog/about/contact/directory measurements are incomplete, so those layouts are source-guided reconstructions rather than a certified pixel-exact copy.

Implemented catalog search, category/price/stock filters, sorting and pagination; product quantity controls, browser-local cart/saved items/comparison, link sharing, accessible information tabs; authentication and recovery forms with password visibility and error messages; published CMS text; approved directory division/district filters; validated enquiries stored for staff. Dealer accounts remain staff-created. Directory responses exclude private profile, NID and email fields. Public product pages exclude dealer pricing. CMS content is escaped text.

Twenty public reference products are design fixtures. Catalog/preview-detail fixtures are enabled only in the local environment and the catalog yields to active non-demo client products. Client-approved inventory, descriptions, photos, contact details and promotions are still required. The homepage retains reference promotional fixtures pending that content. Browser cart selections are not financial orders. No payment automation is introduced.

Verification: frontend build and TypeScript succeeded. Public browser verification records 58 passing checks at 1536×900 and 390×844 in `docs/qa/public-pages-results.json`, including synthetic enquiry submission, invalid/demo login, desktop/mobile overflow, pagination, exact price sorting/filtering, quantity/cart, comparison and keyboard tabs. Homepage regression records 15 passing checks. Screenshots are in `docs/screenshots/`. The full backend suite passed 24 tests/145 assertions before the additional password reset test; the subsequent focused public suite passed 5 tests/46 assertions, including one-use reset tokens. Backend tests run exclusively against `madina_testing`, with process-level isolation enforced by `tests/TestCase.php`.

Remaining: dashboard visual replacement, approved client content/assets, complete catalog image/category management, scheduled campaigns, coordinates/nearby directory and map integration, plus the broader acceptance and deployment work in the production plan. Commission discussion is deferred. Earning/payout policy activation stays gated; manual company payment, private receipt upload and history requirements remain intact.

