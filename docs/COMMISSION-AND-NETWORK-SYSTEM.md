# Madina Natural Health Care — Distribution, Commission & Network System Specification

**Document Version:** 1.0.0  
**Date:** 16 October 2026  
**System Classification:** Distribution ERP / DMS + Multi-Tier Affiliate Network & Financial Engine  
**Project Workspace:** `F:\workplace1\MADINA PROJECT`  

---

## 1. Executive Summary & Core Philosophy

This document defines the architectural blueprint and business logic for the **Dynamic Multi-Tier Commission, Network Distribution, and Product Tracking Engine** for **Madina Natural Health Care**.

The system merges three enterprise capabilities into a single, cohesive platform:
1. **Distribution ERP & Inventory Management:** Physical warehouse tracking, atomic stock movements, 1-click in-stock/out-of-stock toggles, B2B wholesale order lifecycles, and partial dispatches.
2. **Multi-Tier Affiliate & Referral Network:** Crypto/affiliate-style sponsor genealogy trees, 3-generation downline overrides, leadership rank progression, and infinite-depth reward milestones with anti-whale branch capping.
3. **Double-Entry Financial Ledger:** Zero-deficit budget allocation, immutable audit logging, and manual payment verification/settlement with private receipt uploads.

---

## 2. The Core Financial Model: The 100% Zero-Deficit Waterfall

The foundation of the entire system is that **the company only pays commissions from real cash physically collected**. 

### Terminology:
* **Retail MRP (Maximum Retail Price):** The nominal consumer selling price.
* **Dealer Retail Margin / Deduction:** The discount kept directly by the dealer upon selling to retail customers (e.g. 50% or 40%). It is **not** received by the company and **not** an electronic wallet credit.
* **Approved Dealer Price (Cash Inflow to System):** The actual money collected by the company when an order is placed. **This collected cash represents 100% of the company's gross cash inflow.**
* **Company Operating & Profit Share:** 60% of the collected cash (equivalent to 30% of Retail MRP).
* **Total Commission & Reward Pool:** 40% of the collected cash (equivalent to 20% of Retail MRP).

$$\mathbf{Company\ Retained\ Share\ (60\%)} + \mathbf{Commission\ Pool\ (40\%)} = \mathbf{100\%\ of\ Cash\ Collected}$$

---

### Mathematical Proof & Worked Examples

| Financial Metric | Example A (MRP = ৳200.00) | Example B (MRP = ৳500.00) | Example C (MRP = ৳1,000.00) | Percentage Basis |
|---|---|---|---|---|
| **Retail MRP** | ৳200.00 | ৳500.00 | ৳1,000.00 | $100\%$ Consumer Price |
| **Dealer Retail Margin** (Kept by Dealer) | ৳100.00 ($50\%$) | ৳250.00 ($50\%$) | ৳500.00 ($50\%$) | $50\%$ Retail Deduction |
| **Gross Cash Inflow to Company** | **৳100.00** | **৳250.00** | **৳500.00** | **$100\%$ Cash Inflow** |
| **Company Retained Share** (Cost & Profit) | **৳60.00** | **৳150.00** | **৳300.00** | **$60\%$ of Cash Collected** ($30\%$ MRP) |
| **Total Commission & Reward Pool** | **৳40.00** | **৳100.00** | **৳200.00** | **$40\%$ of Cash Collected** ($20\%$ MRP) |

> **Crucial Rule:** The Total Commission Pool is **strictly capped at 40% of Cash Inflow**. Every single bonus, referral payment, salary, rank royalty, marketing reserve, and car/bike fund must be funded out of this 40% slice. The company will never operate at a deficit.

---

## 3. The 9 Allocation Slices (Sum = Exactly 40% of Cash Inflow)

The 40% Commission Fund is distributed across 9 transparent categories:

```
Total Collected Cash (100%)
  ├── Company Share (60%) ── Manufacturing, Bottling, Packaging, Operations & Net Profit
  └── Commission Pool (40%) ── Distributed Across 9 Categories:
        ├── 1. Area Dealer Pool ────────── 10% (5-way geographic split)
        ├── 2. Direct Referral ─────────── 10% (Direct sponsor reward)
        ├── 3. Dealer Monthly Salary ────── 2% (Monthly qualification pool)
        ├── 4. Own Dealer Cashback ──────── 5% (Cashback on own purchase price)
        ├── 5. Rank Royalty ─────────────── 4% (1% each for MO, AM, SM, ME)
        ├── 6. Rank Incentive ───────────── 2% (One-time career milestone reward)
        ├── 7. D2D Multi-Tier Referral ──── 3% (2% Gen 2 + 1% Gen 3)
        ├── 8. Company Marketing Fund ───── 2% (Retained for ads & campaigns)
        └── 9. Car & Bike Incentive ─────── 2% (60 Lakh & 25 Crore milestones)
        ─────────────────────────────────────────────────────────────
        TOTAL ALLOCATIONS:                40% (Zero-deficit balance)
```

---

### Detailed Specification of the 9 Rules

#### Rule 1: Area Dealer Commission (10%)
Divided equally across 5 administrative tiers (2% each):
1. **Management Reserve Fund:** 2% (Retained in company reserve with transparent audit tracking).
2. **Division Area Dealer:** 2%
3. **District Area Dealer:** 2%
4. **Upazila / Thana Area Dealer:** 2%
5. **Union Area Dealer:** 2%

* **Monthly Area Dealer Qualification (Minimum monthly company purchases):**
  * **Union Area Dealer:** $\ge \text{৳}20,000 / \text{month}$
  * **Upazila Area Dealer:** $\ge \text{৳}40,000 / \text{month}$
  * **District Area Dealer:** $\ge \text{৳}50,000 / \text{month}$
  * **Division Area Dealer:** $\ge \text{৳}50,000 / \text{month}$
* If an area level is vacant or the assigned dealer fails to meet the monthly purchase quota, the 2% remains in the unallocated territory reserve for company audit reconciliation.

#### Rule 2: Direct Referral Commission (10%)
* Paid directly to the active sponsor who directly introduced the purchasing dealer.
* **Separation of Codes:** Dealer Code (staff-assigned, unique account identifier) is distinct from Referral/Sponsor Code (used for network tree placement).

#### Rule 3: Dealer Monthly Salary Pool (2%)
* 2% of all company sales cash inflow is pooled into a monthly distribution fund.
* Distributed at month-end to all active dealers who meet monthly qualifying sales volume.

#### Rule 4: Own Dealer Commission / Cashback (5%)
* When a dealer purchases products from the company, they receive **5% cashback** credited directly to their wallet.
* **Basis:** Calculated on their actual purchase price (Cash Inflow), not MRP.

#### Rule 5: Rank Royalty (4% — Monthly Recurring)
Distributed monthly to active rank holders who maintain their rank:
* **Marketing Officer (MO) [1% Pool]:** Personal cumulative purchase $\ge \text{৳}2,50,000$ (2.5 Lakh).
* **Area Manager (AM) [1% Pool]:** Must have **10 Marketing Officers (MO)** in their referral tree across **3 generations** (Gen 1, Gen 2, Gen 3 combined).
* **Sales Manager (SM) [1% Pool]:** Must have **10 Area Managers (AM)** in their referral tree across **3 generations**.
* **Marketing Executive (ME) [1% Pool]:** Must have **10 Sales Managers (SM)** in their referral tree across **3 generations**.

#### Rule 6: Rank Incentive (2% — One-Time Achievement Reward)
* Awarded **only once in a dealer's lifetime** when they reach a new rank for the first time.
* If a dealer drops rank and re-achieves it later, no duplicate incentive is paid. Reaching a higher rank unlocks the higher rank's one-time bonus.

#### Rule 7: Dealer-to-Dealer (D2D) Multi-Tier Referral (3%)
Multi-tier generational rewards for building a downline organization:
* **Generation 1 (Direct Sponsor):** Already receives the **10% Direct Referral** (Rule 2).
* **Generation 2 (Sponsor of Sponsor):** Receives **2%**.
* **Generation 3 (3rd-Level Upline):** Receives **1%**.
$$\text{Gen 2 } (2\%) + \text{Gen 3 } (1\%) = 3\% \text{ Total D2D}$$

#### Rule 8: Company Marketing Cost Reserve (2%)
* 2% is retained by the company in a dedicated marketing ledger for print media, digital advertising, event sponsorships, and brand collateral.

#### Rule 9: Car & Bike Incentive Reserve (2%)
2% is reserved into a designated capital asset pool. Dealers qualify based on total referral tree sales across **infinite depth**, governed by an **Anti-Whale Maximum Leg Capping Rule**:

* **🏍️ Bike Milestone:**
  * **Target Cumulative Tree Volume:** $\text{৳}60 \text{ Lakh}$ ($\text{৳}6,000,000$).
  * **Anti-Whale Branch Cap:** Maximum **$\text{৳}8 \text{ Lakh}$** countable from any single frontline leg/branch.
  * *Reasoning:* Prevents a dealer from winning a bike through luck by recruiting a single high-volume member. Requires developing multiple balanced legs.
* **🚗 Car Milestone:**
  * **Target Cumulative Tree Volume:** $\text{৳}25 \text{ Crore}$ ($\text{৳}250,000,000$).
  * **Anti-Whale Branch Cap:** Maximum **$\text{৳}2 \text{ Crore}$** countable from any single frontline leg/branch.

---

## 4. Technical Architecture & Database Design

### A. Graph Traversal: The Closure Table Pattern (`referral_tree_paths`)
Rather than slow recursive SQL or fragile nested sets, the system uses a **Closure Table** to index every ancestor-descendant path and relative depth.

```sql
CREATE TABLE referral_tree_paths (
    ancestor_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    descendant_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    depth INT NOT NULL, -- 0=Self, 1=Gen 1, 2=Gen 2, 3=Gen 3, ... N=Infinite
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ancestor_id, descendant_id)
);

CREATE INDEX idx_tree_descendant_depth ON referral_tree_paths(descendant_id, depth);
CREATE INDEX idx_tree_ancestor_depth ON referral_tree_paths(ancestor_id, depth);
```

* **Instant Upline Resolution ($O(1)$ query):**
  ```sql
  SELECT ancestor_id, depth 
  FROM referral_tree_paths 
  WHERE descendant_id = :purchasing_dealer_id AND depth IN (1, 2, 3);
  ```
* **Instant Infinite-Depth Downline Aggregation (for Car/Bike):**
  ```sql
  SELECT SUM(o.total_cash)
  FROM referral_tree_paths p
  JOIN orders o ON o.user_id = p.descendant_id
  WHERE p.ancestor_id = :candidate_id AND o.status = 'delivered';
  ```

---

### B. Dynamic Rule Registry (`commission_rule_registry`)
Every percentage, threshold, quota, and branch cap is stored in the database and fully editable from the Company Dashboard with **effective-date versioning**:

```sql
CREATE TABLE commission_rule_registry (
    id SERIAL PRIMARY KEY,
    rule_key VARCHAR(64) NOT NULL, -- 'area_pool_pct', 'bike_target', 'bike_leg_cap', etc.
    rule_category VARCHAR(32) NOT NULL, -- 'area', 'referral', 'salary', 'rank', 'incentive'
    name VARCHAR(128) NOT NULL,
    percentage NUMERIC(5, 2) DEFAULT 0.00,
    amount_threshold NUMERIC(14, 2) DEFAULT 0.00,
    cap_limit NUMERIC(14, 2) DEFAULT 0.00,
    parameters JSONB DEFAULT '{}',
    version INT NOT NULL DEFAULT 1,
    effective_from TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    effective_until TIMESTAMP WITH TIME ZONE NULL,
    updated_by BIGINT REFERENCES users(id)
);
```

---

### C. Double-Entry Financial Ledger
Commissions and cash movements are recorded as immutable double-entry journal entries:
* `ledger_transactions`: UUID, reference order ID, idempotency key, timestamp.
* `ledger_entries`: Debit/Credit lines, exact decimal amount, account ID.
* **Balances are computed dynamically:** `Available = SUM(Credit) - SUM(Debit) - PendingHolds`.

---

## 5. Company Dashboard: Dedicated Visual Modules

In accordance with user requirements, the dashboard avoids cramming features into a single screen. Each domain has its own dedicated, first-class section in the sidebar:

```
🏢 COMPANY ADMINISTRATION
│
├── 📦 Product Management (/company/catalog) [EXISTING]
│   ├── All Products Catalog (1-click In/Out of Stock toggle, Centered Modal)
│   ├── Special Discounts & Package Bundles (Mode A & Mode B)
│   └── Featured Products Showcase (Curated Homepage Grid)
│
├── 👥 Dealer & Account Hub (/company/accounts)
│   ├── All Accounts Directory (Search by Name, Code, Phone, Division)
│   ├── + Create New Dealer (Manual Dealer Code, KYC, GPS coordinates)
│   └── 👤 Dealer 360° Comprehensive Profile Modal:
│       ├── Tab 1: KYC, National ID, Address & Pharmacy Point
│       ├── Tab 2: Orders, Cash Paid & 5% Cashback Earned
│       ├── Tab 3: Referral Downline (Gen 1, Gen 2, Gen 3 & Full Tree)
│       ├── Tab 4: Current Rank (MO, AM, SM, ME) & 3-Gen Downline Audit
│       ├── Tab 5: Car & Bike Milestone Progress (Leg-by-leg capping audit)
│       └── Tab 6: Wallet Balance, Manual Claims & Uploaded Receipts
│
├── 🌳 Network & Genealogy Tree (/company/network)
│   ├── Interactive Visual Node Graph (Zoom, pan, search dealer, expand downline)
│   ├── Generation-by-generation inspector (Gen 1 in Green, Gen 2 in Blue, Gen 3 in Purple)
│   └── Team Sales Volume Leaderboard
│
├── ⚙️ Commission & Rules Studio (/company/commissions)
│   ├── Visual Sliders & % Steppers for all 9 pools
│   ├── Live Zero-Deficit Budget Guard (Locks Save button if total > 40%)
│   ├── Real-Time Simulation Calculator (Type order amount -> see exact Taka allocations)
│   └── Version Changelog & Historical Reversion
│
├── 📍 Area Dealers Hub (/company/area-dealers)
│   ├── 8 Divisions, 64 Districts, Upazilas, and Unions Matrix
│   ├── Circular Progress Rings for Monthly Quotas (৳20k / ৳40k / ৳50k)
│   └── Active Territory Dealer Assignments & Vacancy Management
│
├── 🏅 Ranks & Royalties (/company/ranks)
│   ├── Live Rosters for MO, AM, SM, and ME Leaders
│   ├── 3-Generation Downline Auditor (Verifies 10-leader threshold)
│   └── One-Time Rank Incentive Approval Queue
│
├── 🚗 Car & Bike Rewards (/company/rewards)
│   ├── Real-Time Milestone Progress Bars (৳60 Lakh & ৳25 Crore)
│   ├── Anti-Whale Leg Contribution Charts (Visualizing capped vs. uncapped volume)
│   └── Reward Fulfillment & Handover Management
│
└── 💰 Financial Waterfall & Payouts (/company/finance)
    ├── Capital Waterfall (Gross Cash In -> 60% Company Retained -> 40% Commission Pools)
    ├── Manual Payment Claims Verification (Reviewing incoming bank/mobile slips)
    └── Manual Payout Settlements (Processing withdrawals & uploading private slips)
```

---

## 6. Implementation Plan & Milestones

1. **Milestone 1: Database Migration & Model Layer**
   * Create `referral_tree_paths` closure table with trigger-based or event-based path propagation.
   * Create `commission_rule_registry` seeded with the 9 baseline rules.
   * Create `dealer_ranks`, `area_dealer_assignments`, and `reward_milestone_progress`.
2. **Milestone 2: Calculation Engine (`CommissionEngine.php`)**
   * Pure service calculating allocations atomically upon order delivery/payment.
   * Anti-whale branch capping algorithm for Car and Bike tracking.
   * Automated unit and feature test suite ensuring 100% mathematical balance.
3. **Milestone 3: Company Dashboard Visual Modules**
   * Build the dedicated React/Inertia views across `/company/commissions`, `/company/network`, `/company/area-dealers`, `/company/ranks`, and `/company/rewards`.
4. **Milestone 4: Partner Workspace Integration**
## 7. Hardening, Logic Validation & Edge-Case Protection

In accordance with enterprise MLM and financial ledger security standards, the commission system includes the following defensive protections:

1. **Closure Table Graph Cycle Prevention**:
   - Guard against self-sponsorship: `$sponsorId !== $userId`.
   - Directed acyclic graph verification: Uses `ReferralTreePath::isDescendant($newSponsorId, $userId)` ($O(1)$ closure table check) to prevent circular sponsorship loops before any tree path insertion.
2. **Subtree Re-parenting & Depth Recalculation**:
   - When an existing partner's sponsor is changed, `reparentUser($userId, $newSponsorId)` atomically purges obsolete ancestor connections for the moving user and all descendants.
   - Reconnects the moving subtree to the new sponsor's lineage with updated generational depths: `new_depth = ancestor_depth + 1 + descendant_depth`.
3. **Pessimistic Concurrency & Row-Level Locking**:
   - Wallet credits execute within `DB::transaction()` using `lockForUpdate()` on wallet rows to prevent race conditions (lost updates) under concurrent order deliveries.
4. **Suspended / Fraudulent Upline Isolation**:
   - Commission slices destined for inactive, suspended, or flagged accounts (Gen 1, Gen 2, Gen 3) are held with status `'held'` and are not credited to active available balances until administrative compliance clearance.
5. **Double-Entry General Ledger Integrity**:
   - Order commission distributions post atomic double-entry records (`journal_entries` and balanced `journal_lines` with equal debits to `commission_expense` and credits to `dealer_wallet_payable`).
6. **Anti-Whale Milestone Volume Qualification**:
   - Milestone progress calculations strictly filter orders by `orders.status = 'delivered'` to eliminate volume spoofing with submitted or unpaid orders.
7. **Dynamic Upline Compression & Breakage Reserve**:
   - Intelligently traverses the upline hierarchy skipping inactive/suspended partners when `dynamic_compression => true` to award next active partners.
   - Routes unearned generational slices to Company Breakage Reserve (`category: '..._breakage'`, `user_id: null`) when ancestry line exhausts, preserving the exact 13% referral budget.
8. **Exact Penny Remainder Reconciliation**:
   - Multi-way percentage splits (Area Dealer 10% across 5 tiers) calculate total pool first and reconcile fractional cent discrepancies onto the Management Reserve so that $\sum \text{all 5 slices} \equiv \text{exact 10\% pool total}$.
9. **Unassigned Geographic Fallback**:
   - Territory percentages without an appointed active dealer are cleanly marked `'unassigned_reserve'` and retained in company geographic reserve without unpayable ledger liabilities.
10. **Atomic Order Cancellation & Clawback (`reverseOrder`)**:
    - Reverses commission allocations, claws back wallet balances with non-negative bounds protection, posts offsetting double-entry ledger entries (debit `dealer_wallet_payable`, credit `commission_clawback_recovery`), decrements rank turnover, and recalculates ancestor milestones.
11. **Idempotent Milestone Delivery Refresh**:
    - Orders paid prior to shipment delivery refresh ancestor Car & Bike milestones once marked delivered without duplicating commission allocations or wallet credits.

---

*This specification is maintained under version control in the project workspace for continuity across all agents and developers.*

