# Catalog hierarchy & product ops — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admin/CMS-managed category → subcategory → product → Strength + Unit, with full MRP/pricing block, Product In/Out, and expiry support.

**Architecture:** New Eloquent catalogs (`product_categories`, `product_subcategories`, `product_units`) owned by Domain + portal commands; products reference subcategory + unit; inventory exposes Product In/Out kinds; ProductEditor cascades CMS lists and keeps MRP/dealer/sub-dealer pricing.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL, Inertia React, PHPUnit on `madina_testing`.

## Global Constraints

- Enterprise mother-company quality; no hardcoded category/subcategory/unit lists as truth.
- Preserve financial safety; do not enable financial activation.
- Prefer `php artisan make:* --no-interaction`; run `vendor/bin/pint --dirty --format agent` on PHP edits.
- Tests: `php artisan test --compact` against `madina_testing` only.
- Categorical commits + push after each completed task group.
- Branch: `xai-cursore/catalog-product-ops-cms-e7fb` off `main`.
- Product form **must** keep MRP, dealer price/discount, sub-dealer price/discount, cost (gated), Strength, Unit.

---

## Task A — Schema & models

- [ ] Migration: `product_categories`, `product_subcategories`, `product_units`
- [ ] Migration: products FKs + `expires_on`; backfill categories/units from distinct existing strings
- [ ] Migration: stock_movements kind values / check for `product_in` / `product_out`
- [ ] Models + factories; Product relations
- [ ] Feature test: migrate + create product with CMS FKs and MRP
- [ ] Commit: `Add CMS catalog tables for categories, units, and product FKs.`

## Task B — Domain & HTTP (catalog + stock)

- [ ] Domain services for category/subcategory/unit CRUD
- [ ] Extend stock adjust to Product In / Product Out (explicit kinds, reasons)
- [ ] Controllers + Form requests + routes in `routes/backend.php` / `commands.php`
- [ ] Stop hardcoded subcategory defaults as source of truth in `SiteCustomization`
- [ ] Tests for CMS CRUD + stock in/out
- [ ] Commit: `Add catalog CMS APIs and Product In/Out stock kinds.`

## Task C — Portal UI

- [ ] CMS hub(s) for categories / subcategories / units (or extend ProductManagementHub)
- [ ] ProductEditor: cascading Category → Subcategory; Unit select from CMS; keep full pricing (MRP, dealer, sub-dealer, cost); Strength; expires_on
- [ ] Replace single movement CTA with Product In + Product Out flows in Portal inventory UI
- [ ] Commit: `Wire product editor and stock UI to CMS catalog.`

## Task D — Verify & PR

- [ ] `vendor/bin/pint --dirty --format agent`
- [ ] Narrow then broader `php artisan test --compact` for touched tests
- [ ] `npm run build` if JS changed
- [ ] Push + update/create PR
