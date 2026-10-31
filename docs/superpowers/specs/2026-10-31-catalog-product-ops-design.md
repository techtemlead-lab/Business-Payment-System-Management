# Stream 1 — Catalog hierarchy & product operations (design)

**Status:** Approved by owner (2026-10-31 Asia/Dhaka), with pricing-block clarification.  
**Quality bar:** Enterprise mother-company site — admin/CMS-driven, no hardcoded catalog lists as source of truth.

## Goals

1. Mother **Category** → **Subcategory** → **Product** → **Strength** + **Unit** (professional names; owner’s “power/unit” is explanatory only).
2. Category, Subcategory, Product name, and Unit are all **created by admin/CMS**.
3. Product create/edit keeps the full commercial pricing block (not only hierarchy).
4. Explicit **Product In** and **Product Out** stock actions (replace single “movement” as primary UX).
5. **Expiry date** on stock/product lots; warn owners; prefer nearer expiry on fulfilment (FEFO) when lots exist.

## Product create / edit fields (required in UI)

| Group | Fields |
|-------|--------|
| Identity | Name, SKU |
| Hierarchy (CMS) | Category → Subcategory (cascading) |
| Spec | **Strength**, **Unit** (unit from CMS list) |
| Content | Description, image, video, publication, featured |
| **Pricing (must remain)** | **MRP**, dealer cash price, dealer discount %, **sub-dealer cash price**, sub-dealer discount % (commission-style partner terms), cost (capability-gated), low-stock threshold |
| Expiry | Expiry / spare date (lot-aware where stock is received) |

Dealer / sub-dealer “commission” in owner language maps to the existing partner purchase terms on the product form (discount % and cash prices vs MRP). Both dealer and sub-dealer blocks stay visible and complete.

## Data model

- `product_categories` — id, name, slug, sort, active, timestamps  
- `product_subcategories` — id, product_category_id, name, slug, sort, active  
- `product_units` — id, name, symbol/code, sort, active  
- `products` — add FKs `product_subcategory_id`, `product_unit_id`; keep `strength`; add nullable `expires_on` (date) for simple single-expiry products; migrate string `category` / `sub_category` / `unit` into CMS rows then stop treating strings as source of truth for new edits  
- `stock_movements.kind` — use explicit `product_in` / `product_out` (retain legacy `receipt`/`adjustment` readability in audit if needed)  
- Optional later: `stock_lots` for multi-expiry FEFO; MVP may attach `expires_on` on in-movements / product until lots land

## CMS / API

- Staff CRUD for categories, subcategories, units (Inertia portals + `routes/backend.php` / commands).  
- Product registry validation: category/subcategory/unit must exist and be active; subcategory must belong to selected category.  
- Remove hardcoded subcategory default lists from `SiteCustomization` as source of truth; derive public nav from CMS.

## Stock UX

- **Product In** — qty &gt; 0, reason, reference, branch, optional expiry on receipt.  
- **Product Out** — qty &gt; 0 as outflow, reason (damage, write-off, unofficial out, etc.), reference, branch.  
- Dealer return → Product In with reason type `return`.

## Non-goals (later streams)

- Portal sidebar redesign (Stream 2)  
- Place-code empty-slot policy (Stream 3)  
- Public About / Privacy / Terms polish (Stream 4)  
- Enabling `MADINA_FINANCIAL_ACTIVATION`

## Testing

- Feature tests on `madina_testing` only.  
- Cover CMS CRUD, product save with MRP + dealer + sub-dealer pricing, Product In/Out, expiry validation.
