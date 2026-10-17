# Madina Natural Health Care — Backend and Commission Integration

Updated: 17 October 2026. This document describes the implemented services and release controls. The quotation and client business plan remain separate documents.

## Requirements-to-code review - 17 October 2026

### বর্তমান সিদ্ধান্ত

পুরোনো ফিচার তালিকা, ব্যবসার লিখিত ব্যাখ্যা, চুক্তির খসড়া, সংশোধিত কোটেশন, বাংলা ব্যবসা পরিকল্পনা ও অভ্যন্তরীণ প্রশ্নতালিকার সঙ্গে বর্তমান কোড মিলিয়ে দেখা হয়েছে। মূল অর্ডার, ইনভেন্টরি, কমিশন, SR, পে-রোল, পেমেন্ট অ্যাকাউন্ট ও এক্সপোর্ট ব্যবস্থা আছে। এই পর্যালোচনায় পাওয়া ৮টি আংশিক মডিউলের কাজ এখন সম্পন্ন ও পরীক্ষিত। নথির সঙ্গে নিয়মের ৪টি অমিল ব্যবহারকারীর নির্দেশ অনুযায়ী পরবর্তী আলোচনার জন্য রাখা হয়েছে; তাই পুরো প্রকল্পকে ১০০% ক্লায়েন্ট-অনুমোদিত বলা হচ্ছে না। ১২ অক্টোবরের 56.25% মূল্যায়ন বর্তমান প্রকল্পের অগ্রগতি নয়।

ব্যবহারকারীর সর্বশেষ ব্যাখ্যা: ক্লায়েন্ট ডেলিভারির পরে নিজের প্রাথমিক ব্যবসার তথ্য ও শুরুর হিসাব যোগ করবেন। তাই এই ডেটা সংগ্রহ বা এন্ট্রিকে বর্তমান ডেভেলপমেন্টের অসম্পূর্ণতা হিসেবে ধরা হয়নি। কোনো opening-balance statement এখন জমা বা independently verified হয়েছে বলেও ধরা হয়নি। ট্রেড লাইসেন্স ব্যবসার পরিচয়সংক্রান্ত আলাদা নথি; এটি শুরুর আর্থিক হিসাবের প্রমাণ হিসেবে ব্যবহার করা হয়নি। লাইসেন্সের ছবি, NID ও অপ্রয়োজনীয় ব্যক্তিগত তথ্য সোর্স রিপোজিটরিতে যোগ করা হয়নি।

This is an internal review. It does not revise the signed commercial scope, fill the quotation's blank fees/dates/support terms, approve a business policy or activate finance.

### Source precedence and scope

Reviewed sources:

- The original raw feature list supplied in the conversation and the polished `Pasted text.txt` attachment dated 12 October: public website, partner dashboard, administration, reporting and payment-account controls.
- The other pasted business explanation: purchase-value distribution, geographic recipients, area quotas, three-generation referrals and rank/milestone concepts. Its earlier rank figures are not substituted for the later quotation's starting figures.
- The supplied handwritten dealer, rank, area and sub-dealer images, read alongside the user's later corrections. The confirmed flat 3% salary and separate 3% tour replace the former monthly salary tiers.
- `সফটওয়্যার ডেভেলপমেন্ট চুক্তিপত্র তৈরি.pdf` in Downloads, 26 pages; the matching DOCX was also extracted to resolve damaged Bengali text extraction. Relevant module/video/rank pages were rendered and visually checked. Generated citations inside that draft are not treated as verified legal guidance or executable instructions.
- `Madina-Natural-Health-Care-Quotation.pdf`, revision 06 dated 15 October, 17 pages, and its editable HTML; `Madina-Natural-Health-Care-Business-Plan-Bangla.pdf`, 9 pages, and its HTML.
- The 12-page historical internal review and the later 8-page requirement-clarification document, including their questions and explicit unresolved-policy notes.
- Earlier Downloads quotation variants (`Final-`, `etit ` and `ok `) and the earlier `Fainal-` clarification. The old tiered-salary quotation is superseded; the `ok ` quotation has the same extracted text as the current 17-page version. Filename wording such as “Final” does not override the user's subsequent instructions.
- Existing website, dashboard, homepage, product-management and commission implementation notes. Their dated “remaining” lists are checkpoints, not the current module status.

The two hospital proposal reference parts are presentation references, not an instruction to add hospital modules. The AMR recording still has no reliable transcript in this workspace; no additional spoken requirement is claimed to have been verified.

### Which code was reviewed

| Location | Revision at review | Meaning |
|---|---|---|
| This worktree, `codex/project-completion-audit` | `7217086` | Includes the latest operations, payroll, reserves, schedules and native exports |
| Fetched GitHub `origin/main` | `c7f9c13` | No newer remote-main commit was found; the final operations commit is still on the project branch |
| Primary checkout `D:/works/Business-Payment-System-Management`, local `main` | `be4067d` | Eight commits behind fetched main; it must not be mistaken for the reviewed application revision |

The revision table records the audit baseline; the eight-module completion changes are included in the implementation commit containing this review. The primary checkout's existing changes were preserved. This review does not merge the branch, reset that checkout or prove that a live server is running the reviewed revision. Database migration status was inspected on the configured local application database; tests ran on a separate PostgreSQL instance.

### All 43 quoted modules

“Implemented” means the principal quoted workflow has source implementation and relevant regression coverage; it does not mean signed client acceptance or production deployment. “Partial” identifies a concrete missing part. “Align” means implemented behavior and the document wording need an explicit reconciliation; changing financial semantics silently would be unsafe.

| Ref. | Status | Code evidence and result |
|---|---|---|
| P01 | Align | `routes/web.php`, public header and company authentication: public navigation and partner login exist; SR uses company staff login. Separate customer/dealer/SR entries described in the quotation are not three distinct login products. The completed frontend deliberately separates company access. |
| P02 | Implemented | `PublicController`, `SiteCustomization` and information templates provide company introduction, mission, vision and editable contact content. |
| P03 | Implemented | Public catalog/detail templates, product registry and database products provide search, categories, photos, prices and stock status. Categories are flat names, not a hierarchical category editor. |
| P04 | Implemented | `SiteCustomization`, its editor and homepage support promotional slides, banners, featured products and offers. No automatic dated campaign engine is implied. |
| P05 | Implemented | `PublicController` and directory template filter consented active dealers by division/district and suppress private profile fields. |
| P06 | Implemented | Browser geolocation and server distance calculation show nearest saved dealer coordinates; division/district fallback remains available. `PublicWebsiteTest` covers distance and visibility. |
| P07 | Implemented | Contact form validation, stored enquiries, staff follow-up, configurable office map and directions exist. |
| P08 | Implemented | Content commands and site customization maintain supported text, images, banners and notices with staff permissions. |
| P09 | Implemented | `ProductRegistryController`, `ProductVideo` and `Product::media()` support validated YouTube/Vimeo embeds, HTTPS MP4/WebM links and private uploads. Video marketing selects a published product video for a configurable homepage section. Hidden/invalid media, stale settings and unsafe URLs are rejected; arbitrary iframe HTML is never stored. |
| D01 | Implemented | Partner profile is read-only; authorized account corrections, email/phone login, password changes and recovery exist. |
| D02 | Implemented | `OrderService`, order requests and captured payment claims implement product quantities, account prices, references and pending review. |
| D03 | Implemented | Order decisions, back-order estimates, partial dispatch, courier/tracking and delivered status retain shared event history. |
| D04 | Implemented | `StockNotifications` and stock-status display cover new/restocked products and low/out-of-stock notices; delivery is in-app. |
| D05 | Implemented | `DueAdjustments` provides source-linked credit notes and bounded debit corrections with unique references/request keys and a separate Finance reviewer. Approved credits reduce outstanding dues without altering original prices, receipts or the policy snapshot. Paid-order returns retain the separate reversal workflow. |
| D06 | Implemented | `FinanceSettings` stores a versioned, audited minimum, editable by authorized Finance staff. Backend validation and partner screens use the same value; each new withdrawal retains its minimum snapshot and idempotent retries preserve the original request. |
| D07 | Implemented | Partner, staff and permitted SR order details show the same dated credit/correction history, preparer, reviewer, reasons and before/after balance. Requests/rejections remain visible separately from approved credits and verified payments. |
| D08 | Implemented | Referral paths, direct lists, network tree and downline purchase timeline are scoped to the permitted network; `BackendIntegrationTest` covers access boundaries. |
| D09 | Implemented | Qualification, rank history, maintenance, funded one-time awards and milestone progress exist in commission services and partner views. |
| S01 | Implemented | Sub-dealer account type, sponsor, staff approval/control and locked profile exist. |
| S02 | Implemented | `ProductPricing` and shared order/fulfilment services use per-product sub-dealer prices and shared payment/dues history. |
| S03 | Implemented | Purchase-source salary accrual and closed-month settlement use flat 3%; the 3% tour reserve remains separate and is not credited as duplicate salary. |
| S04 | Implemented | Policy configuration, dated schedules and company financial exports cover sub-dealer purchases, dues, allocations and salary settlements. This does not assert a partner-facing export of every company report. |
| SR01 | Implemented | Staff-created SR accounts, activation/suspension and territory assignment exist; there is no public SR registration. |
| SR02 | Implemented | Restricted SR capabilities and `TerritoryAccess` enforce permitted actions and full geographic boundaries. |
| SR03 | Implemented | `StaffOperations` and `FieldSalesController` provide applications, independently reviewed onboarding, scoped dealers, orders on behalf and dues/collection visibility. |
| SR04 | Implemented | Frozen monthly targets, net territory achievement and personal payroll/history exist. Contracts support fixed pay/allowance/deduction and target bonuses; no unspecified SR commission formula is inferred. |
| A01 | Implemented | `StaffPermissions`, `EnforceStaffActions` and Roles & permissions implement per-module View/Create/Edit/Approve rights, custom role presets and versioned individual overrides. Both JSON and Inertia mutations enforce the matrix; SR restrictions and SuperAdmin-only staff access delegation remain. Legacy editors preserve detailed overrides. |
| A02 | Implemented | Account creation, suspension/restoration, invitations/reset, controlled profile corrections and audit events exist. |
| A03 | Implemented | Staff/SR applications support review, approval/rejection and controlled account creation; `DealerOnboardingTest` and operations tests cover restrictions. |
| A04 | Implemented | Dealer/area codes, full geographic profile hierarchy, territory assignments and coordinates exist. |
| A05 | Implemented | Product registry provides cost privacy, MRP, partner prices, 20%-50% entered discount bounds, images, videos, category and publication status; pricing validation also protects funded distributions. |
| A06 | Implemented | Audited inventory receipts/issues/adjustments, quantities, reasons, idempotent references and low/out-of-stock thresholds exist. |
| A07 | Implemented | Order/payment verification, approval/hold/rejection, credit authorization, back orders and partial fulfilment exist. |
| A08 | Implemented | Due adjustments have prepare/review stages, independent authorization, activation gates, balance revalidation, balanced journal entries, partner notices and shared history. Debit corrections only restore an earlier credit; additional sales require a new order. Credits are not cash received or commissionable receipts. |
| A09 | Implemented | Supported content, banners, notices, dashboard switches and business settings are editable. Immutable posted financial history is not an editable screen structure. |
| F01 | Align | Defaults, approved dated global/buyer policies and recipient caps exist. Orders freeze terms at submission; the quotation says subsequent approved orders. Recipient exceptions are capped within funding rather than unrestricted extra earnings. Align the timing and exception semantics. |
| F02 | Align | Referral management and captured chain exist. Code pays direct sponsor 10%, second ancestor 2%, third ancestor 1%, matching the detailed business explanation. The quotation calls D2D “first/second generation”; relabel the two D2D levels to remove ambiguity without duplicating the direct 10%. |
| F03 | Implemented | All twelve dealer/sub-dealer allocation categories have source-linked accounting, pending reserves or earned credits as applicable. |
| F04 | Implemented | Geographic pools, recorded area quotas, rank qualifications, four royalty shares and one-time funded incentives exist. Bike reward amount is entered and reviewed explicitly; the BDT 300,000 quotation example is not an automatic cash award. |
| F05 | Align | Staff/SR payroll has prepare/review/pay stages. Partner salary/royalty settlement is a Finance-authorized wallet-credit action, followed by independently reviewed withdrawals; it has no separate draft/reviewer approval stage for the settlement itself. Reconcile the intended partner salary-sheet approval process. |
| F06 | Implemented | Manual withdrawal review/payment recording and encrypted Bank/bKash/Nagad/Rocket account management exist. Automated provider transfers are expressly separate in the quotation. |
| F07 | Implemented | Authenticated operator IDs now flow through API/Inertia posting, salary/royalty settlement, rank awards, hold release and commission reversals. Dedicated rank-award audits retain beneficiary/source details. Existing historical null/buyer actor rows are not rewritten; system operations can still have a null actor. |
| F08 | Implemented | Fifteen native XLSX/PDF types include actual rank history, funded rank awards with source reversals/net amount, grouped product/category sales and due adjustments. Captured categories survive later edits; legacy categories are captured at upgrade. Dates/permissions/totals and Bengali PDF support retain existing controls; unit prices are not summed as sales totals. |

There are **39 implemented module rows, 0 partial rows and 4 deferred alignment rows** after completing the eight previously partial rows. These counts are a requirements checklist, not a weighted completion percentage. A partial row may contain substantial completed work; the same missing adjustment feature affects multiple quoted rows. The former 56.25% cannot be reused, and these counts must not be advertised as a new project-completion percentage.

### Completed partial modules and deferred decisions

The eight partial rows P09, D05, D06, D07, A01, A08, F07 and F08 are implemented above. They share six implementation areas because the single due-adjustment workflow completes three quoted rows. Regression coverage is in `ModuleCompletionTest` alongside the existing integration, financial report and platform suites.

- Credit notes apply only against an approved order's unpaid balance. A debit correction can restore previously credited dues, never increase the original sale. Review rechecks current receipts to prevent an old draft from over-crediting. Source totals/prices/policies and cash receipts remain unchanged; no extra commission is minted. Requests, approvals, rejections and repeated/changed requests have explicit behavior.
- The Finance setting uses optimistic versions, and new withdrawal requests retain their minimum. Custom roles and individual matrices use known modules/actions, reject stale updates and preserve SR boundaries. A legacy permissions editor cannot overwrite the new matrix; ordinary staff detail corrections remain possible.
- Rank reports read actual achievement/award records and linked payout reversals. Grouped sales are delivered gross sales by order date, with financial adjustments reported separately. Video providers are validated sources rather than supplied iframe HTML.
- Operator attribution applies to new actions only. No historical authorizations were invented.

**Deferred at the user's explicit request:** P01/F01/F02/F05 still require discussion of login presentation, policy-capture timing and exception semantics, D2D generation labels, and the partner settlement approval model. Their existing behavior and financial rules were not changed by this completion work.

### Client data entry, opening review and release

The user clarified during this review that the client will enter initial data after delivery. Client product/stock records, dealer/territory records, payment accounts and completed commercial/handover terms remain operational inputs. No legacy migration is claimed to have been requested or completed.

The existing Opening balance review screen **verifies totals already present in the system**; it does not import old wallets, create arbitrary initial commissions, adjust dues or load a bank opening balance. Ordinary accounts, inventory and orders can be entered through their normal controls. If the client needs historical positive wallet/held balances or old financial dues entered as opening entries, a controlled opening-entry/import workflow is still needed; the verification form alone cannot do that. Historical migration and bulk entry are separately scheduled in the quotation.

The supplied license identifies a Pirganj, Rangpur business location. `SiteCustomization::defaults()` still has Noyarhat/Ashulia/Savar office and footer addresses, and the map description refers to a registered center. Determine whether these are separate registered/operating offices before publication; no address, map or legal-name spelling was silently replaced. The contact phone in the supplied license agrees with the client's previously supplied number and the normalized website default. Business identity review is based on the supplied image, not an independent authenticity check. The image and its personal identification fields remain outside the repository.

The configured local application database now has all 14 migrations applied. Before deployment, a private backup was restored to a separate rehearsal database and the additive migration passed there. All ten internal reconciliation checks pass after deployment, including due-credit reconciliation; recorded money and stock were preserved:

| Recorded total | BDT |
|---|---:|
| Available wallet liability | 0.00 |
| Held wallet liability | 0.00 |
| Verified order receipts | 1,000.00 |
| Paid withdrawals | 0.00 |

There are two orders, one receipt, and no commission batches, journals, refunds, withdrawals, payroll entries or reserve disbursements. The origin of the BDT 1,000 has not been established as real opening business money; it was not deleted, reclassified or used to manufacture evidence. Client entry and review must establish the intended starting dataset before finance is activated.

`posting_enabled=false` and `opening_verified=false` remain the recorded application state. The supplied trade license is kept separate from the opening review. No reviewer account was impersonated, no approval row was inserted and no financial activation configuration was changed.

After the client finishes entry: reconcile the intended starting records, upload the applicable supporting statement/reconciliation, obtain the separate Finance review through the application and enable the deployment setting deliberately. This is an operational handover sequence, not evidence that the software has already passed client acceptance. Live deployment, client scenario acceptance/training, real content/account setup, mail delivery and scheduled backups/restore ownership still require the agreed environment and service schedule.

### Verification of this completion

The full isolated PostgreSQL regression suite passed **208 tests / 1,562 assertions**, including the initial-payment/order regression found during integration. Subsequent focused runs passed 25 tests / 233 assertions and the final module suite passed 13 tests / 156 assertions, covering staff detail correction without overwriting its matrix and allowing authorized financial previews without posting. TypeScript validation, the production Vite build and Pint passed. Browser QA on a separate synthetic database checked roles, withdrawal settings, credit notes, video marketing and the expanded report selector. An actual due PDF was downloaded, rendered and visually inspected.

The additive migration was rehearsed on a restored private backup before being applied to the configured local application database. All 14 migrations are applied and all ten reconciliation checks pass, with the BDT 1,000 receipt and zero wallet/payout totals unchanged. Posting remains disabled and opening balances remain unverified. The primary checkout, dependency lockfile changes, client quotation, business-plan files and private evidence are preserved outside this implementation commit. These results establish implemented behavior; the four deferred decisions and client acceptance remain separate.

## Integrated operations

| Area | Implemented behavior | Operational requirement |
|---|---|---|
| SR territories | Hierarchical, case-normalized territory boundaries; scoped partner lists, orders on behalf of partners, order timelines, dues and dealer applications | A manager assigns territories; account staff independently review new partner applications |
| Staff targets and payroll | Frozen monthly territories, net posted sales, earned target bonuses, effective salary contract revisions, month-close preparation, independent review, manual payment evidence and personal salary history | Finance supplies agreed salary terms and uses separate preparer/reviewer/payer controls; earlier contract and rejected payroll records remain immutable |
| Payment accounts | Finance-managed Bank/bKash/Nagad/Rocket accounts; encrypted registry details, active collection/payout flags, optimistic versions, order claim account snapshots, encrypted account-version history and captured payout-account versions | These are company account controls for recorded manual payments; automated provider transfers require a separately commissioned integration |
| Commission schedules | Approved global and individual purchase policies with start/expiry dates; recipient earning caps; order-captured terms and immutable revisions | A different Finance reviewer approves schedules; caps retain unused money as reserve rather than increasing another recipient's allocation |
| Reserve payouts | Funded sub-dealer tour and additional-rank rewards, one-time qualified vehicle rewards, marketing invoice approvals/payments, private evidence and recoveries | Reward amounts are explicit reviewed requests; milestone achievement alone does not create money; the car cash amount is not inferred |
| Hold releases | Active-recipient and original-period area-quota checks, cooling-off preservation, single credit and an audit trail | Legacy area allocations without a captured quota require reconciliation before release |
| Financial reports | Fifteen native XLSX and Bengali-capable PDF reports for orders, delivered lines, grouped products/categories, dues, credit/correction activity, commissions, salary/royalty settlements, payroll, withdrawals, journals, reserve rewards, rank achievements, rank incentive awards and commission refund adjustments; scoped records, period/date controls and totals | Dates use Asia/Dhaka; XLSX streams the full selected dataset; PDF limits output to 5,000 rows and directs larger reports to Excel |
| Opening review | Private PostgreSQL backup command; journal, wallet, receipt, reservation, allocation and stock checks; hashed opening snapshot, independent evidence and separate Finance verification | Internal agreement with the ledger does not establish agreement with an external bank statement or constitute business-owner approval |

New company screens are under `/company/operations/{section}`. All new integrations have session-authenticated, CSRF-protected endpoints under `/api/v1/operations`, with matching Inertia command routes under `/commands/operations`. The shared ProductPricing contract and completed frontend design are preserved. Pages load in separate JavaScript bundles.

Production financial posting requires the explicit deployment configuration AND an independently verified opening-balance record. Preparing drafts, reviewing configuration and exporting reports never enables posting. Business-owner approval of the policy and source opening records remains a release condition.

The public website, catalog, partner network, inventory, order fulfilment, notices and existing payout workflows continue to use the established application routes and permissions. Live branding/content, published dealer contact consent, gateway account details and business acceptance are supplied by the client. Application notices do not imply external SMS/push delivery. Commission reversal records financial adjustments; physical stock returns and bank refund execution remain separate operations.

## Financial basis and defaults

All commission percentages use the purchase value paid to the company. Product MRP is used to calculate the company share, which defaults to 30% of MRP. Dealer and sub-dealer prices are configured separately per product. An empty sub-dealer price retains the legacy 40% discount. Newly entered partner discounts must be within 20–50%; the resulting price must also fund the commission policy.

| Allocation | Dealer | Sub-dealer |
|---|---:|---:|
| Direct referral | 10% | 10% |
| Area pool | 10% | 10% |
| Own purchase cashback | 5% | 5% |
| Dealer monthly salary pool | 2% | 2% |
| Leadership royalty pool | 4% | 4% |
| Rank incentive reserve | 2% | 2% |
| D2D referral | 3% | 3% |
| Marketing reserve | 2% | 2% |
| Car/motorcycle reserve | 2% | 2% |
| Additional rank reserve | — | 4% |
| Sub-dealer monthly salary | — | 3% |
| Sub-dealer tour reserve | — | 3% |
| **Total purchase-value allocation** | **40%** | **50%** |

Example: MRP BDT 200, dealer purchase BDT 100 → company BDT 60 and allocations BDT 40. Sub-dealer purchase BDT 120 → company BDT 60 and allocations BDT 60. A larger discount can create a shortfall; it must not be treated as a funded sale under the same policy.

The area pool defaults to management, division, district, upazila and union shares of 2% each. Assignment matching uses the full geographic hierarchy. Duplicate or ambiguous legacy assignments do not receive automatic wallet credits. Area dealers must meet their recorded monthly purchase quota.

The preserved three-generation referral model pays the direct sponsor 10%, generation 2 another 2%, and generation 3 another 1%. Dynamic compression is an explicit policy parameter and defaults to disabled. Missing recipients remain reserves; suspended recipients remain held without an available wallet credit.

Sub-dealer monthly salary is a flat 3% across all qualifying purchases. The former 1%, 2%, 3%, 4% purchase tiers are replaced. Tour allocations are separately reserved and are not an additional spendable salary credit. Additional-rank, marketing, tour and vehicle funds are disbursed through funded reserve requests and independent Finance approval; milestone achievement does not mint an award. Marketing expenses require a separate payer, active company account, actual reference and private receipt.

## Recognition, history and rounding

An order must be fully paid and delivered before earnings are posted. Payment verification and delivery both call the same posting service, so a credit sale paid after delivery is handled. A unique order batch and transaction locks prevent duplicate posting.

New orders capture their account type, prices, referral chain, geography and complete financial rule snapshot. Later rule changes apply to new orders. Every policy update validates the merged schedule, including unchanged rows, and appends an immutable revision with an effective timestamp. Generation, area and royalty sub-shares must reconcile to their parent pools. Monthly settlement uses each source order's retained policy.

Money mutations use integer paisa. Allocation rounding reconciles largest remainders first between parent pools and then between recipients, without exceeding the funded batch. Original allocation amounts remain recorded when net allocations are reduced by refunds.

Calendar settlement periods follow Asia/Dhaka; database timestamps remain UTC. Current or future months cannot be settled. Salary qualification uses actual posted, net-of-refund volume for the selected month, not an accumulating monthly counter. Each settlement payout retains its source allocation and the cumulative refunded basis at the time of credit, so later refunds cannot claw back an earlier refund twice. Retrying a settlement cannot pay the same source twice, including when further eligible source allocations are added later.

Royalty defaults to 1% for each of MO, AM, SM and ME, shared among that rank's qualified dealers. Configurable rank shares must total the royalty pool. Recorded rank history prevents later promotions from collecting an earlier month's higher royalty.

Default leadership criteria and funded awards:

| Rank | Qualification | One-time award |
|---|---|---:|
| MO | BDT 25,000 qualifying own purchases | BDT 5,000 |
| AM | 10 MO-or-higher leaders within 3 generations | BDT 50,000 |
| SM | 10 AM-or-higher leaders within 3 generations | BDT 250,000 |
| ME | 10 SM-or-higher leaders within 3 generations | BDT 300,000 |

Awards are unique per user and rank and require sufficient rank-incentive reserve funding. Historical incentive flags without an award history require reconciliation before another award. The default motorcycle target is BDT 5,000,000, with BDT 800,000 counted per branch. The existing car target is BDT 250,000,000, with BDT 20,000,000 per branch. Only paid, delivered, net-of-refund purchases count toward milestones.

## Refunds, holds and withdrawals

Commission refund adjustments require a reference and reason. Repeating the same reference replays the result; changed data is rejected. Cumulative refunds cannot exceed the order value. Append-only refund and reversal records preserve the original financial trail. Adjustments recover direct earnings and payouts already settled from that order's pools. Unrecoverable amounts become clawback receivables, offset by later earnings.

Commission cooling-off holds are tracked separately from withdrawal reservations. Maturation releases only the corresponding commission hold and cannot consume funds reserved for a withdrawal. Suspended recipients do not receive an available credit. Finance can release a held allocation after its recipient becomes active and, for an area earning, after the original recorded period and quota qualify. A release retains any unexpired cooling-off period and cannot be credited twice.

The default withdrawal minimum is BDT 200. Requests reserve available funds atomically, encrypt destination details and enforce idempotent payloads. Outstanding clawbacks block further requests, approval and payment recording. Rejecting an unpaid request releases its reserved funds through clawback reconciliation. An approved but unpaid request can also be rejected. A paid request cannot be cancelled through review.

Manual payment recording requires a private receipt, payment reference and actual payment time. The payer must differ from the reviewer and beneficiary. Finance can view a destination on the authorized detail page; that access is audited. Bulk listing responses exclude encrypted destinations and private storage paths. The application records manual payments and commission adjustments; these endpoints do not execute bank transfers.

Withholding defaults to zero. A configured withholding rate is snapshotted at withdrawal request time and journals the recorded net payment and withholding liability. This is an application setting, not a representation of a legally required tax rate.

## Inventory and permissions

The stock-status button pauses or resumes ordering. It never deletes counted inventory or invents 50 units. Inventory receipts and adjustments retain explicit quantities, reasons, actor and unique references. Products with inventory or order history are archived rather than deleted; unused products may be deleted after the database transaction succeeds.

Only a super administrator may assign employee permissions. Employee custom permissions must come from the capability whitelist. The SR role has its own restricted field-sales permission list; SR permissions cannot grant Finance or account-administration access. Partner profile JSON cannot grant staff access. Product pricing, cost access, initial stock and identity changes enforce their separate permissions. An employee with pricing permission cannot erase hidden product costs.

Partner rank, earnings and referral purchase views are scoped to the authenticated account and its permitted downline. Account and finance staff views retain their respective capability checks. The local administrator login bypass from the frontend branch has been removed.

## Backend boundaries

The completed Inertia frontend continues using its command routes. Session-authenticated JSON endpoints are exposed through `routes/backend.php`, with CSRF protection, active-account checks and throttling. Posting, wallet and pricing APIs use decimal strings for money; simulation summaries retain their numeric UI contract.

Key endpoints include:

- `GET /api/v1/me`, `/catalog`, `/orders`, `/orders/{order}`
- Order creation, review, payment claim/verification, dispatch and delivery endpoints
- `POST /api/v1/inventory/adjustments`
- `GET /api/v1/earnings`, `/network`, `/network/{user}/orders`
- `POST /api/v1/withdrawals`
- `GET` and `PATCH /api/v1/finance/policy`
- `POST /api/v1/finance/orders/{order}/commissions`
- `POST /api/v1/finance/orders/{order}/commission-refunds`
- `POST /api/v1/finance/settlements`
- Distribution and salary preview endpoints, which never post or activate earnings

`App\Domain\Pricing\ProductPricing` remains the product-pricing contract. Commission orchestration delegates to policy, posting, qualification, wallet, settlement and reversal services.

## Database and deployment

PostgreSQL is required for the financial transaction lock and database constraints. The additive integration migration introduces per-product sub-dealer pricing, order policy snapshots, order commission batches, policy revisions, settlement source payouts, rank awards, refunds, reversals and a separate commission-hold balance. It also preserves existing stock and order histories and backfills referral paths.

Existing customized rules are preserved. Only untouched version-1 defaults are normalized to the reconciled rank and withholding defaults; legacy settings are retained in a schema-upgrade policy revision. The rule seeder uses `firstOrCreate`, so repeated seeding does not overwrite administrator changes. Legacy allocations are not automatically posted again; reconcile existing financial records before enabling live posting.

After updating the application checkout, configure its PostgreSQL connection and run:

```sh
composer install --no-interaction
php artisan finance:readiness --backup-only --no-interaction
php artisan migrate --no-interaction
php artisan db:seed --class=CommissionRuleSeeder --no-interaction
php artisan finance:readiness --no-interaction
npm ci
npm run build
php artisan config:cache
```

`MADINA_FINANCIAL_ACTIVATION=false` is the default. Enable it only after the business owner verifies the approved policy, legacy financial records and opening balances. Production posting also checks for an independently verified opening record. Submit source evidence and opening totals in Opening balance review; a second Finance user verifies the unchanged snapshot. Preview endpoints remain non-posting even when live finance is enabled.

Run database tests only on a separate PostgreSQL instance using the dedicated `madina_testing` database. The test bootstrap rejects a different database name. Do not run destructive test migrations against a development or production database.

## Verification and configured database deployment — 17 October 2026

The complete suite passed on the separate PostgreSQL instance: 195 tests and 1,402 assertions. Laravel Pint, TypeScript, the production Vite build, Composer validation and platform checks pass. The initial JavaScript bundle is approximately 349 kB, with the large portal loaded separately; the previous bundle-size warning is resolved.

A fresh private backup of the configured application database was restored on the isolated instance and upgraded successfully before deployment. Both previously pending commission migrations and the three additive operations migrations have now run on the configured local application database. All nine internal reconciliation checks pass, and the post-deployment totals and record counts agree with the rehearsal.

No production earnings, reward credits or payments were activated by deployment. The user subsequently clarified that the client will enter starting business data after delivery. No opening statement has been submitted or independently verified in the application, and financial posting remains disabled. The opening-review screen verifies existing records against business-source evidence; it is not a legacy opening-entry/import screen. The requirements review above records the remaining development gaps and handover steps.

Browser checks used a separate synthetic preview database and covered navigation, recipient-specific commission controls, scheduled area-share inputs, reconciliation results and native report download. The generated PDF was rendered and visually checked for Bengali glyphs, table alignment, totals and page numbering. Private backups, generated reports, preview credentials and client document drafts are excluded from the source commit.

The backup command uses the PostgreSQL client tools already installed on the deployment host and stores its output on the private local disk. It does not approve source balances or turn on financial posting.
