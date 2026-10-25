# Madina go-live checklist

Use this before enabling financial posting on a production deployment. Keep `MADINA_FINANCIAL_ACTIVATION=false` until every gate below is verified.

## Environment

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, strong `APP_KEY`
- [ ] `APP_URL` and `APP_DOMAIN` match the live public host; portal subdomains resolve (`dealer.`, `subdealer.`, `manage.`, `sadmin.`)
- [ ] PostgreSQL is dedicated (not shared with another app); backups scheduled and restore-tested
- [ ] Queue worker running: `php artisan queue:work --queue=imports,default --sleep=3 --tries=1 --timeout=1800` — durable driver (not `sync`/`null`); `app:readiness` must not warn `queue_driver_is_sync` or `queue_worker_heartbeat_missing`
- [ ] Scheduler running: `php artisan schedule:work` (or cron equivalent); `app:readiness` must not warn `scheduler_heartbeat_missing`
- [ ] `php artisan app:readiness` reports PASS for migrations, DB timezone UTC, cache, private storage, preview posting disabled
- [ ] `composer audit --locked` and `npm audit` reviewed; known issues accepted or remediated
- [ ] Frontend built: `npm ci --ignore-scripts && npm run build`

## Policy and money (activation gate)

- [ ] Commission policy draft reviewed and dual-control published for the live schedule
- [ ] Opening balances imported and reconciled against bank / cash books
- [ ] Payment accounts configured (bank / MFS) with correct labels — at least one active account with collections enabled and one with payouts enabled; `app:readiness` must not warn `payment_collection_accounts_missing` or `payment_payout_accounts_missing`
- [ ] Retained / owner benefits fund visibility confirmed for owners only
- [ ] Partner benefit overrides (unconditional salary / rank) audited — none unintended
- [ ] Only after the above: set `MADINA_FINANCIAL_ACTIVATION=true` on the production host and re-run `app:readiness`
- [ ] Confirm `MADINA_PREVIEW_MODE=false` on production

## Access and portals

- [ ] Owner (`sadmin`) and company (`manage`) staff accounts created with least privilege
- [ ] Seed dealer / sub-dealer accounts use real place codes and unique refer codes
- [ ] Public partnership page + `/join/{refer_code}` deep-link smoke-tested
- [ ] Partner home shows referral share URL; full-report PDF downloads for dealer and sub-dealer
- [ ] Locale (EN/BN) toggles on each portal without breaking forms
- [ ] Password reset emails land on the correct portal host

## Operations smoke

- [ ] Create → approve → dispatch → deliver one dealer order on a non-production clone first
- [ ] Commission allocations appear under the expected heads after delivery (clone only until activation)
- [ ] Withdrawal request → staff review → payout receipt path works
- [ ] Manual payment claim verification works
- [ ] Data import templates download; a dry-run import succeeds
- [ ] Report exports (xlsx/pdf) succeed for permitted staff

## Bangladesh field readiness

- [ ] SMS: enable a Service integrations SMS provider (base URL + API key) and/or set a non-log `MADINA_SMS_DRIVER`; verify `app:readiness` clears SMS warnings
- [ ] Partner login OTP verified end-to-end (`MADINA_LOGIN_OTP=true`) with a real provider before go-live
- [ ] SMTP (or SES/Postmark) uses a real host — not Mailpit/`mail`/`localhost` — and `MAIL_FROM_ADDRESS` is not `@example.com`
- [ ] `php artisan app:readiness` lists no unresolved go-live mail/SMS warnings (or each warning has an accepted ops waiver)
- [ ] Password-reset and owner-contact mail verified on the live portal hosts
- [ ] Place-code catalog seeded (`php artisan db:seed --class=BangladeshPlaceCodeSeeder`) — `app:readiness` must not warn `place_code_catalog_incomplete` (8 divisions + 64 districts minimum)
- [ ] BDRIS / identity lookup credentials set only if the feature is contracted
- [ ] Public dealer directory GPS + district filters verified on mobile

## Rollback

- [ ] Document how to flip `MADINA_FINANCIAL_ACTIVATION=false` and drain queues (steps below kept with the runbook)
- [ ] Keep a known-good DB dump taken immediately before activation (`php artisan app:backup` with a strong `MADINA_BACKUP_PASSWORD`, then `php artisan app:backup-verify`)
- [ ] Owner contact for dual-control emergency freeze is listed offline

### Emergency freeze (financial posting)

1. Set `MADINA_FINANCIAL_ACTIVATION=false` (and keep `MADINA_PREVIEW_MODE=false`) on the production host / secret store.
2. Signal workers to exit after the current job: `php artisan queue:restart`, then stop `queue:work` / scheduler processes until config is reloaded.
3. Confirm the gate: `php artisan app:readiness` must show `financial activation verified` PASS while activation remains off; `php artisan finance:readiness` inspects reconciliation without posting.
4. Do **not** run `php artisan finance:release-matured` or `madina:demo` during a freeze.
5. Restore from the pre-activation backup only after owner dual-control approval; verify with `php artisan app:backup-verify` before bringing workers back.
