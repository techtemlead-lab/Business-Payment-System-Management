# Stream 3 — Public About, Privacy, Terms polish (design)

**Status:** Owner said go ahead after Streams 1–2 (2026-10-31 Asia/Dhaka).  
**Quality bar:** Enterprise mother-company public site — CMS-driven, premium, not thin marketing stubs.

## Goals
1. Deepen **/about** with professional company narrative (history, mission, vision, values/objectives, network role) via SiteCustomization + `lang/*/site.php`.
2. Professionalize **/privacy** and **/terms** bodies (clear sections, company identity, contact path) still editable in CMS.
3. Remove hardcoded legal meta bar strings from Blade where practical — move Effective date / governance labels into CMS keys.
4. Keep BN parity through `lang/bn/site.php`.
5. Preserve page toggles and `content_pages` override precedence.

## Approach
- Enrich defaults in `SiteCustomization.php` + mirror in `lang/en/site.php` / `lang/bn/site.php`.
- Extend `information.blade.php` only if new About sections are required; prefer filling existing slots first.
- Add CMS keys for legal meta (effective label, governance badge) if currently hardcoded.
- Feature tests: About renders expanded sections; privacy/terms titles/bodies present; BN chrome; XSS unchanged.

## Non-goals
- Lawyer-certified PDPA text (staff can replace CMS body before go-live).
- Cookie consent banner (separate follow-up unless trivial).
- Portal sidebar / catalog (already Stream 1–2).
