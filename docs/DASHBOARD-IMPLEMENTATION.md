# Dashboard and company access checkpoint

Historical checkpoint: use [the current requirements-to-code review](COMMISSION-AND-NETWORK-SYSTEM.md#requirements-to-code-review---17-october-2026) for the latest status. Later code supersedes the dated missing-module table and salary-tier references below.

13 October 2026, Asia/Dhaka. The user requested dashboard options from the PDFs, a separate company entry, and supplied a final header logo (`h1.png`). The PNG is copied unchanged into `public/madina-header-logo.png`; it supersedes the two earlier logo images.

## Access and appearance

Company entry: `/company/login`; company pages: `/company` and `/company/{section}`. Standalone staff login/recovery and an administration sidebar replace the public website header/categories. Company links do not appear in public navigation. Authenticated dealers cannot access company routes, even by entering a URL directly. Correct dealer credentials do not authenticate at the company login; staff credentials use the company login. Existing staff `/workspace/...` links redirect to their company equivalents. Staff menu visibility and server action permissions remain role-based. Staff invitation/recovery emails use company reset URLs; logout returns to the proper login with full browser navigation.

Dealer/sub-dealer entry remains `/login`, with `/workspace` pages. The reference-style dealer website header, blue slide-out account menu, white profile summary and action cards use permitted client modules. The company dashboard follows the same palette/card system with its separate administration shell. No reference company dashboard was available; that interface is designed from the supplied requirements.

Profile figures show total orders, approved purchases, financial dues, pending supply units and requests awaiting review. These are server-derived operational amounts, rather than invented points/ranks/wallet earnings. The avatar is a synthetic initial. The profile is staff-controlled; dealer forms do not enable profile changes. Mobile navigation traps drawer focus, makes the background inert, and closes with Escape. The company mobile drawer does not introduce the public storefront navigation.

## PDF module coverage

Rechecked the English PDF module lists, physical pages 4–7, against the preserved extraction and planning interpretation. The Bangla clarification/confirmed salary inputs remain documented in planning/01 and planning/08. Later user instructions override the documents for manual payments, private company-uploaded receipts and deferred commission discussion.

| PDF group | Dashboard options currently available | Remaining acceptance work |
|---|---|---|
| D01–D05 | Profile/security, product requests, orders, deliveries, stock labels, notifications and dues | Complete back-order/stock-notification rules and dispatch/contact/slip acceptance |
| D06–D09 | Earnings/withdrawal history, direct network list and rank-policy status | Financial activation deferred; full tree/timeline and rank calculations require approved rules |
| S01–S04 | Separate sub-dealer pricing/access, requests/history and confirmed salary-tier page | Salary qualification/month close/approval and full export configuration |
| A01–A03 | Role-scoped staff menus/actions, account creation/status/corrections/invitations, enquiry follow-up | Full application verification/rejection workflow and configurable permission administration |
| A04–A06 | Account codes/division/district fields, product registry and stock movements | Complete geographic hierarchy/coordinates, categories/images/cost management and threshold editing |
| A07–A09 | Order/payment review, partial dispatch, deliveries, credit authorization, dues, company content/enquiries | Due adjustments/aging, complete content/banner/settings administration |
| F01–F05 | Inactive commission drafts, salary tiers and policy status | Recipient/territory/rank/referral configuration and financial recognition rules remain deferred |
| F06–F08 | Withdrawal/receipt/history pages, ledger/audit and scoped order CSV export | Activate only after approved policies; published collection accounts, richer reports/date filters and Excel/PDF remain incomplete |

This is a verified dashboard appearance/access increment, not completion of every PDF module or production release.

## Verification

Frontend build and TypeScript passed. Full backend suite passed29 tests/197 assertions before adding the final Inertia logout check; the subsequent company-access suite passed5 tests/49 assertions. The test bootstrap explicitly targets isolated `madina_testing` and refuses the demo database. Company/dealer role separation, guest redirection, permissions, private cache headers, recovery destinations and logout are covered.

`docs/qa/browser-results.json`:37 passing page/workflow checks, including a synthetic dealer purchase request. `docs/qa/dashboard-results.json`:36 passing desktop/mobile checks across company, dealer and sub-dealer accounts: summary cards, proper portal links, public/company separation, no overflow, drawer behavior, sub-dealer salary scope and correct logout. Screenshots use synthetic local accounts only. Viewports1440×1000 and390×844. No JavaScript errors in these runs.

Production operations, approved client inventory/content, missing modules and deployment/restore acceptance remain in the production plan. No automatic collection or payout integration has been enabled.

## Email or phone sign-in — 14 October 2026

User requested E-Mail / Phone Number instead of email-only login. Both `/login` and `/company/login` now use that label and a text identity field. The existing request key remains `email` for compatibility; the login handler accepts either a valid email or a registered numeric phone. Email lookup ignores case. Phone matching normalizes spaces, brackets, hyphens and Bangladesh local/880/+880/00880 formats. The database has an expression index for phone lookups; stored contact text is preserved.

Only one active matching account can sign in by phone. Duplicate active phone contacts require email sign-in; invalid, unavailable or wrong-portal identities receive the same generic error. Phone-format variants share an attempt-limit key. Portal separation, password checks, session renewal and auditing remain enforced. Recovery still sends reset links to the registered email; no SMS provider was introduced.

Verification: the focused phone/company/public suite passed15 tests/164 assertions. Registered company/dealer/sub-dealer phone login, normalized formats, email fallback, ambiguous numbers, suspended accounts, wrong passwords and format-resistant throttling were checked in isolated `madina_testing`. Browser checks passed9 cases for mobile inputs, numeric submissions reaching authentication, existing email sign-in and zero JavaScript errors. Evidence: docs/qa/login-identity-results.json. No credentials are stored in that evidence.

- 14 October 2026: product registry/media checkpoint supersedes the table's missing basic image/cost/threshold management. Company create/details/editor, source selection, safe media delivery and publication now exist. Hierarchical categories, scheduled campaigns and deployment media capacity remain later acceptance work. See PRODUCT-MANAGEMENT.md.
