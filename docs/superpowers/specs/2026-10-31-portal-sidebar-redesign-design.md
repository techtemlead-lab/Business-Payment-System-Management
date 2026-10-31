# Stream 2 — Portal sidebar / shell redesign (design)

**Status:** Owner directed to proceed after empty place-code policy (2026-10-31).  
**Quality bar:** Enterprise mother-company portals — premium, simple, discoverable.

## Problem
`Shell.tsx` is a hardwired mega-nav with dual CSS (`app.css` vs `dashboard.css`), hard-to-find Branches / Product In-Out / Catalog tools, and weak mobile domain switching.

## Goals
1. Replace the dense accordion mega-sidebar with a **clear, premium, role-aware** navigation shell.
2. Surface Stream 1 tools: **Categories & Units**, **Product In / Out**, **Branches**, Place codes, Orders, Finance, Accounts, Settings.
3. One visual system (tokens), readable hierarchy: primary sections → secondary items.
4. Mobile: reachable domain switch + drawer that does not hide critical tools.
5. Keep capability gates; do not break portal hosts (sadmin / manage / dealer / subdealer).

## Approach (recommended)
- Extract nav config to a typed module (`portalNav.ts`) driven by role + capabilities + portal host.
- Slim `Shell.tsx` to layout chrome only (brand, search, theme, user, section list).
- Group sections: Overview · Catalog · Stock · Network · Orders · Finance · People · Settings.
- Preserve existing section query keys / routes where possible to avoid breaking deep links.
- Partners keep bottom nav; staff/owner get the redesigned sidebar.

## Non-goals
- Rewriting every hub page’s internals
- Changing financial posting rules
- Public website chrome (Stream 3/4)

## Success
Admin can find and open Branches, Catalog taxonomy, Product In/Out, and Place codes within one click from the sidebar on desktop and via drawer on mobile.
