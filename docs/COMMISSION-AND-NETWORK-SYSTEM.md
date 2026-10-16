# Madina Natural Health Care — Backend and Commission Integration

Updated: 16 October 2026. This describes the implemented backend and integration boundaries. The quotation and client-facing business plan are separate documents.

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

Sub-dealer monthly salary is a flat 3% across all qualifying purchases. The former 1%, 2%, 3%, 4% purchase tiers are replaced. Tour allocations are separately reserved and are not an additional spendable salary credit. Additional-rank, marketing and vehicle funds remain earmarked reserves until their approved disbursement workflow is implemented; milestone achievement does not mint an award.

## Recognition, history and rounding

An order must be fully paid and delivered before earnings are posted. Payment verification and delivery both call the same posting service, so a credit sale paid after delivery is handled. A unique order batch and transaction locks prevent duplicate posting.

New orders capture their account type, prices, referral chain, geography and complete financial rule snapshot. Later rule changes apply to new orders. Every policy update validates the merged schedule, including unchanged rows, and appends an immutable revision with an effective timestamp. Generation, area and royalty sub-shares must reconcile to their parent pools. Monthly settlement uses each source order's retained policy.

Money mutations use integer paisa. Allocation rounding reconciles largest remainders first between parent pools and then between recipients, without exceeding the funded batch. Original allocation amounts remain recorded when net allocations are reduced by refunds.

Calendar settlement periods follow Asia/Dhaka; database timestamps remain UTC. Current or future months cannot be settled. Salary qualification uses actual posted, net-of-refund volume for the selected month, not an accumulating monthly counter. Each settlement payout retains its source allocation. Retrying a settlement cannot pay the same source twice, including when further eligible source allocations are added later.

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

Commission cooling-off holds are tracked separately from withdrawal reservations. Maturation releases only the corresponding commission hold and cannot consume funds reserved for a withdrawal. Suspended recipients do not receive an available credit.

The default withdrawal minimum is BDT 200. Requests reserve available funds atomically, encrypt destination details and enforce idempotent payloads. Outstanding clawbacks block further requests, approval and payment recording. Rejecting an unpaid request releases its reserved funds through clawback reconciliation. An approved but unpaid request can also be rejected. A paid request cannot be cancelled through review.

Manual payment recording requires a private receipt, payment reference and actual payment time. The payer must differ from the reviewer and beneficiary. Finance can view a destination on the authorized detail page; that access is audited. Bulk listing responses exclude encrypted destinations and private storage paths. The application records manual payments and commission adjustments; these endpoints do not execute bank transfers.

Withholding defaults to zero. A configured withholding rate is snapshotted at withdrawal request time and journals the recorded net payment and withholding liability. This is an application setting, not a representation of a legally required tax rate.

## Inventory and permissions

The stock-status button pauses or resumes ordering. It never deletes counted inventory or invents 50 units. Inventory receipts and adjustments retain explicit quantities, reasons, actor and unique references. Products with inventory or order history are archived rather than deleted; unused products may be deleted after the database transaction succeeds.

Only a super administrator may assign employee permissions. Custom permissions apply only to the employee role and must come from the capability whitelist. Partner profile JSON cannot grant staff access. Product pricing, cost access, initial stock and identity changes enforce their separate permissions. An employee with pricing permission cannot erase hidden product costs.

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
php artisan migrate --no-interaction
php artisan db:seed --class=CommissionRuleSeeder --no-interaction
npm ci
npm run build
php artisan config:cache
```

`MADINA_FINANCIAL_ACTIVATION=false` is the default. Enable it only after the business owner verifies the approved policy, legacy financial records and opening balances. Preview endpoints remain non-posting even when live finance is enabled.

Run database tests only on a separate PostgreSQL instance using the dedicated `madina_testing` database. The test bootstrap rejects a different database name. Do not run destructive test migrations against a development or production database.
