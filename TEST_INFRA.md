# E2E Test Infrastructure Specification & Architecture

## 1. Overview & Testing Philosophy

This document defines the comprehensive End-to-End (E2E) testing infrastructure for the **Business Payment System Management (Madina)** platform. The test suite is designed as an **opaque-box, requirement-driven verification engine** that enforces the functional, contractual, and operational requirements documented in `PROJECT.md` and `ORIGINAL_REQUEST.md`.

### Core Principles
1. **Opaque-Box Verification**: Tests interact with the application through public HTTP interfaces (`routes/web.php`, `routes/portals/*`, `routes/backend.php`), domain service contracts (`ProductPricing`), and database persistence layers without relying on internal implementation private methods.
2. **Strict Test Isolation**: Every test runs inside an isolated transaction/schema refresh against the dedicated test database `madina_testing`. Access to the development database `madina` is strictly prevented at runtime.
3. **No Facade Tests**: Every test exercises genuine business logic, executes real database queries, triggers PostgreSQL constraints and triggers, and asserts observable responses and database states.
4. **Comprehensive 4-Tier Coverage**: The test suite covers all features in the Feature Inventory across 4 progressive tiers:
   - **Tier 1: Feature Coverage** (>=5 test methods per feature across core flows: auth, purchase matrix, claims, approval, dispatch, delivery, withdrawal, settlements, pricing, operations, and backend invariants).
   - **Tier 2: Boundary & Corner Cases** (zero/negative numbers, out-of-stock orders, invalid role permissions, illegal status transitions, idempotency collisions, and unverified financial safety gates).
   - **Tier 3: Cross-Feature Combinations** (multi-step lifecycle workflows combining ordering, payment claims, verification, branch dispatch, delivery confirmation, commission distribution, and settlement).
   - **Tier 4: Real-World Scenarios** (enterprise workloads including multi-tier MLM hierarchy ordering, sponsor override distributions, and batch operations).

---

## 2. Environment & Database Configuration

### Database Topology
- **Isolated Test Database**: `madina_testing` (user `madina_test` or configured test user).
- **Protected Dev Database**: `madina` (Never touched by test runs).
- **Protection Mechanism**: Enforced in `tests/TestCase.php:createApplication()`:
  ```php
  if ($app->make('config')->get('database.connections.pgsql.database') !== 'madina_testing') {
      throw new \RuntimeException('Tests must use the isolated madina_testing database.');
  }
  ```

### Key Environment Variables (`phpunit.xml`)
| Variable | Value | Purpose |
|---|---|---|
| `APP_ENV` | `testing` | Application environment flag |
| `DB_CONNECTION` | `pgsql` | PostgreSQL database driver |
| `DB_DATABASE` | `madina_testing` | Dedicated test database |
| `CACHE_STORE` | `array` | Ephemeral array cache |
| `SESSION_DRIVER` | `array` | Ephemeral session storage |
| `QUEUE_CONNECTION` | `sync` | Synchronous queue processing |
| `MAIL_MAILER` | `array` | In-memory mail capture |
| `MADINA_FINANCIAL_ACTIVATION` | `false` | Default safety gate off; armed only via `$this->enableFinancialPosting()` |

---

## 3. Test Suite Architecture & Directory Layout

All E2E test suites reside in `tests/E2E/` and inherit from `Tests\E2E\E2ETestCase`:

```
tests/E2E/
├── E2ETestCase.php                           # Base test case with domain helpers & fixtures
├── Tier1/                                    # Tier 1: Requirement & Feature Coverage
│   ├── AuthFeatureTest.php                   # F01 (Partner OTP), F02 (Staff 2FA)
│   ├── CatalogPricingFeatureTest.php         # F03 (Purchase Matrix), F15 (Catalog), B01 (Pricing Contract)
│   ├── OrderLifecycleFeatureTest.php         # F04 (Tracking), F05 (Claims), F06 (Verify), F07 (Decision), F08 (Dispatch), F09 (Delivery)
│   ├── FinanceSettlementFeatureTest.php      # F10 (Withdrawal), F11 (Settlement), F12 (Batch Payout), F16 (Commissions), F17 (Salaries)
│   ├── OperationsAndAdminFeatureTest.php     # F13 (Provisioning), F14 (Inventory), F18 (MLM Tree), F19 (Imports), F20 (Operations)
│   └── BackendContractsAndDbFeatureTest.php  # B02–B07 (Safety, Previews, Snapshots, Idempotency, OpenAPI, Probes), D01–D05 (DB & Constraints)
├── Tier2/                                    # Tier 2: Boundary & Corner Cases
│   └── BoundaryAndCornerCasesTest.php        # Zero/negative inputs, stock bounds, illegal transitions, role bounds, idempotency keys
├── Tier3/                                    # Tier 3: Cross-Feature Combinations
│   └── CrossFeatureCombinationsTest.php      # Full end-to-end commerce & commission lifecycle, cancellation, split dispatches
└── Tier4/                                    # Tier 4: Real-World Application Scenarios
    └── RealWorldApplicationScenariosTest.php # Multi-tier dealer-subdealer network purchasing, rank progression, and payout cycles
```

---

## 4. Test Fixtures, State Helpers & Base Harness

The base class `Tests\E2E\E2ETestCase` provides standardized domain fixtures:
- `createDealer(array $attrs = []): User` — Provisions an active dealer with a unique `dealer_code`.
- `createSubDealer(array $attrs = []): User` — Provisions an active sub-dealer with a unique `dealer_code`.
- `createStaff(string $role = 'super_admin', array $attrs = []): User` — Provisions staff (`super_admin`, `finance`, `operations`, `field_manager`).
- `createProduct(array $attrs = []): Product` — Generates a product with valid MRP and wholesale prices.
- `createBranch(array $attrs = []): CompanyBranch` — Generates a company branch for inventory and fulfillment.
- `seedCommissionRules(): void` — Seeds baseline dynamic commission parameters.
- `enableFinancialPosting(bool $preview = false): void` — Seeds verified dual-control opening balance to satisfy the financial safety gate.
- `onPortal(string $portal): static` — Routes HTTP requests to the target portal subdomain (`dealer`, `subdealer`, `manage`, `sadmin`, `public`).
- `currentTotpCode(string $secret): string` — Computes a valid RFC 6238 TOTP 6-digit code for 2FA tests.

---

## 5. Test Execution Commands

### Run Full E2E Test Suite
```bash
php artisan test tests/E2E --compact
```
or via direct PHPUnit binary:
```bash
vendor/bin/phpunit tests/E2E
```

### Run by Specific Tier
```bash
# Tier 1: Feature & Contract Coverage
php artisan test tests/E2E/Tier1 --compact

# Tier 2: Boundary & Corner Cases
php artisan test tests/E2E/Tier2 --compact

# Tier 3: Cross-Feature Combinations
php artisan test tests/E2E/Tier3 --compact

# Tier 4: Real-World Scenarios
php artisan test tests/E2E/Tier4 --compact
```

### Run Individual Test Class or Method
```bash
php artisan test tests/E2E/Tier1/CatalogPricingFeatureTest.php --compact
php artisan test tests/E2E --filter=test_sub_dealer_pricing_enforces_wholesale_discount_bounds
```

---

## 6. Coverage & Traceability Matrix

| Feature ID | Feature Name | Test Suite | Coverage Level |
|---|---|---|---|
| **F01** | Partner Authentication & OTP | `Tier1/AuthFeatureTest.php` | Comprehensive (5+ tests) |
| **F02** | Staff Authentication & 2FA | `Tier1/AuthFeatureTest.php` | Comprehensive (5+ tests) |
| **F03** | Wholesale Purchase Booking | `Tier1/CatalogPricingFeatureTest.php` | Comprehensive (5+ tests) |
| **F04** | Live Order Lifecycle Tracking | `Tier1/OrderLifecycleFeatureTest.php` | Comprehensive (5+ tests) |
| **F05** | Payment Claim Submission | `Tier1/OrderLifecycleFeatureTest.php` | Comprehensive (5+ tests) |
| **F06** | Payment Claim Verification | `Tier1/OrderLifecycleFeatureTest.php` | Comprehensive (5+ tests) |
| **F07** | Order Decision Workflow | `Tier1/OrderLifecycleFeatureTest.php` | Comprehensive (5+ tests) |
| **F08** | Multi-Branch Stock Dispatch | `Tier1/OrderLifecycleFeatureTest.php` | Comprehensive (5+ tests) |
| **F09** | Delivery Confirmation | `Tier1/OrderLifecycleFeatureTest.php` | Comprehensive (5+ tests) |
| **F10** | Partner Withdrawal Request | `Tier1/FinanceSettlementFeatureTest.php` | Comprehensive (5+ tests) |
| **F11** | Withdrawal Settlement | `Tier1/FinanceSettlementFeatureTest.php` | Comprehensive (5+ tests) |
| **F12** | Batch Payout Disbursements | `Tier1/FinanceSettlementFeatureTest.php` | Comprehensive (5+ tests) |
| **F13** | Partner Account Provisioning | `Tier1/OperationsAndAdminFeatureTest.php` | Comprehensive (5+ tests) |
| **F14** | Inventory Stock Movements | `Tier1/OperationsAndAdminFeatureTest.php` | Comprehensive (5+ tests) |
| **F15** | Catalog & Product Management | `Tier1/CatalogPricingFeatureTest.php` | Comprehensive (5+ tests) |
| **F16** | Commission Studio & Policy Drafts | `Tier1/FinanceSettlementFeatureTest.php` | Comprehensive (5+ tests) |
| **F17** | Monthly Salary & Royalty Settlement | `Tier1/FinanceSettlementFeatureTest.php` | Comprehensive (5+ tests) |
| **F18** | MLM Referral Tree Explorer | `Tier1/OperationsAndAdminFeatureTest.php` | Comprehensive (5+ tests) |
| **F19** | Data Import Engine | `Tier1/OperationsAndAdminFeatureTest.php` | Comprehensive (5+ tests) |
| **F20** | Operations Submodules | `Tier1/OperationsAndAdminFeatureTest.php` | Comprehensive (5+ tests) |
| **B01** | Product Pricing Contract | `Tier1/CatalogPricingFeatureTest.php` | Contractual Verification |
| **B02** | Financial Safety Gate | `Tier1/BackendContractsAndDbFeatureTest.php` | Invariant Enforcement |
| **B03** | Financial Previews Non-Posting | `Tier1/BackendContractsAndDbFeatureTest.php` | Invariant Enforcement |
| **B04** | Policy Snapshots | `Tier1/BackendContractsAndDbFeatureTest.php` | Snapshot Immutability |
| **B05** | Transaction Idempotency | `Tier1/BackendContractsAndDbFeatureTest.php` & `Tier2` | Payload Hash Verification |
| **B06** | OpenAPI 3.0 Specification | `Tier1/BackendContractsAndDbFeatureTest.php` | Schema Validation |
| **B07** | Observability & Probes | `Tier1/BackendContractsAndDbFeatureTest.php` | Probes & Headers |
| **D01–D05** | DB Schema, Constraints, Indexes & Isolation | `Tier1/BackendContractsAndDbFeatureTest.php` | Integrity Verification |
| **T2** | Boundary & Corner Cases | `Tier2/BoundaryAndCornerCasesTest.php` | Negative & Boundary Matrix |
| **T3** | Cross-Feature Combinations | `Tier3/CrossFeatureCombinationsTest.php` | Multi-Step End-to-End |
| **T4** | Real-World Application Scenarios | `Tier4/RealWorldApplicationScenariosTest.php` | Multi-User Enterprise Workloads |
