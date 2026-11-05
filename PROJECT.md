# Project: Business Payment System Management — Production Hardening

## Architecture
- **Framework & Runtime**: Laravel 12 on PHP 8.4, Inertia.js v3 with React 19, TypeScript, Vite.
- **Database**: PostgreSQL 16 (`madina` dev DB, `madina_testing` isolated test DB), double-entry ledger, append-only audit triggers, database advisory locks.
- **Domain Architecture**: 18 domain modules in `app/Domain/` (`Pricing`, `Commissions`, `Finance`, `Orders`, `Inventory`, etc.), Eloquent models in `app/Models/`.
- **Portal Surfaces**: 5 subdomains (`public`, `dealer`, `subdealer`, `manage`, `sadmin`) served through Inertia and `routes/portals/*`.
- **API Boundary**: REST API v1 (`routes/backend.php`) under `/api/v1/*` bound to `ProductPricing` contract, policy snapshots, and idempotency guarantees.

## Feature Inventory
| # | Feature | Description | Milestone | Source |
|---|---------|-------------|-----------|--------|
| F01 | Partner Authentication & OTP | Partner login, OTP issuance and verification | M3 | survey (UI) |
| F02 | Staff Authentication & 2FA | Staff login, TOTP verification and recovery codes | M3 | survey (UI) |
| F03 | Wholesale Purchase Booking | Product catalog matrix, cart computation, pricing checks, checkout modal | M3 | survey (UI) |
| F04 | Live Order Lifecycle Tracking | Visual status tracking and in-flight updates on order detail page | M4 | survey (UI/R3) |
| F05 | Payment Claim Submission | Multi-channel payment submission with transaction reference and slip upload | M4 | survey (UI/R3) |
| F06 | Payment Claim Verification | Staff verification or rejection of payment claims with wallet balance impact | M2 | survey (Backend) |
| F07 | Order Decision Workflow | Order review, approval, hold, or rejection with replacement slot support | M2 | survey (Backend) |
| F08 | Multi-Branch Stock Dispatch | Fulfillment by branch stock allocation, courier tracking, and line item dispatch | M2 | survey (Backend) |
| F09 | Delivery Confirmation | Physical delivery verification triggering final order completion | M2 | survey (Backend) |
| F10 | Partner Withdrawal Request | Threshold-validated payout request with available balance deduction | M4 | survey (UI/R3) |
| F11 | Withdrawal Settlement | Payout processing, tax withholding (TDS), receipt upload, and completion | M2 | survey (Backend) |
| F12 | Batch Payout Disbursements | Batch payout management and bank disbursement export | M2 | survey (Backend) |
| F13 | Partner Account Provisioning | BDRIS identity check, placement tree linking, KYC document collection | M3 | survey (UI) |
| F14 | Inventory Stock Movements | Branch stock in/out, adjustments, low-stock threshold monitoring | M3 | survey (UI) |
| F15 | Catalog & Product Management | Product CRUD, pricing matrix, image handling, discount limits | M3 | survey (UI) |
| F16 | Commission Studio & Policy Drafts | Dynamic commission rule configuration and policy draft editing | M2 | survey (Backend) |
| F17 | Monthly Salary & Royalty Settlement | Asia/Dhaka monthly salary distribution, rank royalty pools | M2 | survey (Backend) |
| F18 | MLM Referral Tree Explorer | Visual genealogy tree explorer, closure table navigation | M3 | survey (UI) |
| F19 | Data Import Engine | Excel/CSV batch import, validation preview with `usePoll`, atomic commit | M3 | survey (UI) |
| F20 | Operations Submodules | Field sales, payroll, payment accounts, dues adjustments | M2 | survey (Backend) |
| B01 | Product Pricing Contract | `ProductPricing` unitPrice calculation, discount bounds (20-50%), MRP ceiling | M2 | survey (Backend) |
| B02 | Financial Safety Gate | `MADINA_FINANCIAL_ACTIVATION=false`, dual-control opening balance verification | M2 | survey (Backend) |
| B03 | Financial Previews Non-Posting | Distribution and salary calculators return `posts_earnings => false` | M2 | survey (Backend) |
| B04 | Policy Snapshots | Freeze policy rules at order submission and approval | M2 | survey (Backend) |
| B05 | Transaction Idempotency | Idempotency key and SHA-256 payload hash verification | M2 | survey (Backend) |
| B06 | OpenAPI 3.0 Specification | Schema accuracy (`number`, valid statuses, 201 withdrawal response) | M2 | survey (Backend) |
| B07 | Observability & Probes | `/healthz`, `/readyz`, `X-Correlation-ID` header propagation | M2 | survey (Backend) |
| D01 | Database Schema & Migrations | 45 existing migrations, PostgreSQL check constraints, additive migration policy | M1 | survey (DB) |
| D02 | Performance Indexes | Composite indexes on `order_lines`, `payment_claims`, `shipments`, `commission_allocations` | M1 | survey (DB) |
| D03 | Eloquent Model Relationships | Inverse/missing relations in `OrderLine`, `Order`, `Product`, `User` | M1 | survey (DB) |
| D04 | Audit Trail Append-Only | PostgreSQL trigger enforcing append-only on `audit_events` | M1 | survey (DB) |
| D05 | Test Isolation & Baseline Fixes | Isolated `madina_testing` DB, Bengali locale string alignment in test assertions | M1 | survey (DB) |
| E01 | Full E2E Test Suite (Tiers 1-4) | 100% test pass on feature coverage, boundaries, combinations, and workloads | M5 | survey (E2E Track) |
| E02 | Adversarial Hardening (Tier 5) | Race condition, lock contention, and edge case hardening via Challenger | M5 | survey (E2E Track) |

## Milestones
| # | Name | Scope | Dependencies | Status |
|---|------|-------|-------------|--------|
| M1 | Database, Models & Test Baseline | Additive migration for performance indexes, Eloquent relationship enrichment (`OrderLine`, `Order`, `Product`), test copy alignment (`ActiveAccountLocaleTest`, `OrderServiceLocaleTest`), clean baseline test pass on `madina_testing`. | none | DONE |
| M2 | Backend APIs, Pricing & OpenAPI Remediation | Correct OpenAPI 3.0 specification (`OpenApiController.php`), validate all API endpoints for standard status codes and structured payloads, verify `ProductPricing` contract adherence and financial preview non-posting invariants (`posts_earnings: false`). | M1 | DONE |
| M3 | Frontend TypeScript Fixes & UI Reliability | Fix 32 TypeScript errors (`tsc --noEmit`) in `StockOnHand.tsx` and `ProductManagementHub.tsx`, verify all 5 portal surfaces and 12 Inertia page components for reliable interaction and error-free rendering, preserve completed luxury UI design. | M2 | DONE |
| M4 | Live Transaction Progress & State Updates | Implement `TransactionProgressStepper.tsx` on `OrderDetail.tsx`, add live polling (`usePoll`) on `OrderDetail.tsx` and `WithdrawalDetail.tsx` for active in-flight states, enable reactive wallet balance and notification attention updates on `Portal.tsx`. | M3 | IN_PROGRESS |
| M5 | E2E Test Pass & Adversarial Hardening | Phase 1: Pass 100% of E2E tests published by E2E Testing Track (`TEST_READY.md`). Phase 2: Adversarial coverage hardening (Tier 5) via Challenger -> Worker -> Reviewer loop. | M4, E2E_READY | PLANNED |

## Interface Contracts
### ProductPricing Contract
- Interface: `App\Domain\Pricing\ProductPricing`
- Method: `public function unitPrice(Product $product, User $buyer): int;`
- Invariant: Wholesale price must be between 20% and 50% discount from MRP, non-negative, and strictly server-calculated.

### Live Transaction Progress Contract
- Component: `TransactionProgressStepper` in `resources/js/components/TransactionProgressStepper.tsx`
- Props: `order: OrderData`, `currentStatus: OrderStatus`, `claims: PaymentClaim[]`, `shipments: Shipment[]`
- Lifecycle Stages: `Submitted` -> `Reviewed` -> `Payment Verified` -> `Dispatched` -> `Delivered`
- Polling Hook: `@inertiajs/react` `usePoll(interval, { only: [...] })` active while status in non-terminal states (`submitted`, `held`, `approved`, `partially_dispatched`, `dispatched`).

### Financial Safety Invariant
- Financial Posting: requires `MADINA_FINANCIAL_ACTIVATION=true` AND dual-control verified opening balances in `financial_verifications`.
- Financial Previews: `posts_earnings` MUST be strictly `false`.
- Advisory Locks: PostgreSQL transaction-level locks `81416001` and `81416004` on financial mutations.

## Code Layout
- Backend routes: `routes/backend.php`, `routes/web.php`, `routes/portals/*`
- Domain logic: `app/Domain/*`
- Eloquent models: `app/Models/*`
- Controllers: `app/Http/Controllers/*`
- Migrations: `database/migrations/*` (Additive only)
- Frontend pages: `resources/js/pages/*`
- Frontend components: `resources/js/components/*`
- Tests: `tests/Unit/*`, `tests/Feature/*`, `tests/E2E/*`
