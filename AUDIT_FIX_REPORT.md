# Modina Natural Health Care — QA Audit & AI Agent Remediation Specification

> **Target Platform:** Modina Natural Health Care (Multi-Portal SaaS & Partner Network)  
> **Applicable Workspace:** `d:\works\Business-Payment-System-Management`  
> **Repository File:** [AUDIT_FIX_REPORT.md](file:///d:/works/Business-Payment-System-Management/AUDIT_FIX_REPORT.md)  
> **Tech Stack:** Laravel 12 (PHP 8.4), PostgreSQL, Inertia.js v3, React 19, Tailwind CSS  
> **Audited Portals:**
> - **Public Site:** `https://modinanhac.techteamlead.com`
> - **Owner Portal:** `https://sadmin.modinanhac.techteamlead.com`
> - **Staff Portal:** `https://manage.modinanhac.techteamlead.com`
> - **Dealer Portal:** `https://dealer.modinanhac.techteamlead.com`
> - **Sub-dealer Portal:** `https://subdealer.modinanhac.techteamlead.com`

---

## 🤖 AI Agent Implementation Prompt

*Copy and give the prompt below to your AI agent or developer to execute the fixes:*

```text
You are an expert full-stack developer. Open and read the complete audit specification at `AUDIT_FIX_REPORT.md` in the root of the workspace. 
Execute all fixes in order of priority (P0 -> P1 -> P2 -> P3):
1. Fix Dealer Code login in AuthController.php and auth.blade.php.
2. Restore clean UTF-8 for Bengali translations in lang/bn/site.php and lang/bn/portal.php (fixing the mojibake corruption from commit 46887d3).
3. Display an explicit status notice on the withdrawal page when MADINA_FINANCIAL_ACTIVATION is false.
4. Add "New Purchase Request" CTA button in Portal.tsx when on /orders.
5. Filter dead '#' social links in footer.blade.php.
6. Provide dynamic remaining seconds in AuthController.php login rate limiting.
7. Standardize currency display to ৳ in Blade templates and UI.tsx.
8. Add mobile sticky checkout summary bar in Portal.tsx & dashboard.css.
9. Add sub-dealer sponsor call & WhatsApp contact card in DashboardOverview.tsx.
10. Ensure PDF statements use explicit download and target="_blank" attributes.
11. Fix header logo dimensions in header.blade.php to eliminate CLS.
12. Enrich empty state messaging across all portal tabs.
After applying code changes, run `vendor/bin/pint --format agent`, `php artisan test --compact`, and `npm run build`.
```

---

## 1. Executive Summary & Severity Matrix

A rigorous live audit was conducted using automated headless browser sessions (Puppeteer) across all desktop and mobile viewports, covering 15 public pages, the Owner portal, Staff portal, Dealer portal (15 sections), and Sub-dealer portal (14 sections).

| Priority | Issue ID | Area | Problem Summary | Impact |
| :--- | :--- | :--- | :--- | :--- |
| **P0** | **BUG-01** | Auth Controller | Login with Dealer Code (`MNHC-1001`) fails immediately | **Blocker:** Partners cannot log in using their ID cards/codes |
| **P0** | **BUG-02** | Remote Database | Owner preview account credentials rejected | **Blocker:** Super admin / MD cannot access management portal |
| **P0** | **BUG-03** | Portal Finance | Withdrawal form silently hidden when finance inactive | **Blocker:** Partners see empty page with zero feedback |
| **P0** | **BUG-04** | Localization | `lang/bn/site.php` & `portal.php` corrupted with `` mojibake | **Critical:** Bangla language toggle broken across entire app |
| **P1** | **UX-01** | Portal Orders | Missing "New Purchase Request" CTA on Orders page | **High:** Partners cannot order from their order history view |
| **P1** | **UX-02** | Public Site | Footer social links are dead anchors (`href="#"`) | **High:** Unprofessional; redirects user to page top |
| **P1** | **UX-03** | Auth Security | Rate limiter throws static lockout with no countdown timer | **High:** Users locked out with no indication of remaining time |
| **P2** | **UI-01** | Formatting | Currency symbol mismatch (`BDT` suffix vs `৳` prefix) | **Medium:** Inconsistent pricing presentation |
| **P2** | **UI-02** | Mobile UX | Catalog page lacks persistent sticky checkout summary bar | **Medium:** Mobile partners struggle to finalize order requests |
| **P2** | **UI-03** | Sub-dealer Hub| Sub-dealer dashboard lacks direct sponsor Call/WhatsApp CTAs | **Medium:** Communication barrier between partner levels |
| **P2** | **UI-04** | Reports | PDF route triggers direct download instead of inline view | **Medium:** Disrupts user workflow during report review |
| **P3** | **PERF-01**| Public Layout | Header logo has hardcoded 500x500 dimensions | **Low:** Causes Cumulative Layout Shift (CLS) on slow loads |
| **P3** | **UI-05** | Portal Hub | Generic "Nothing here yet" empty states across 6+ tabs | **Low:** Unpolished, impersonal SaaS experience |

---

## 2. Detailed Technical Fix Specifications

---

### BUG-01 (P0): Dealer & Sub-Dealer Cannot Login Using Dealer Code

- **Affected Files:**
  - [`app/Http/Controllers/AuthController.php`](file:///d:/works/Business-Payment-System-Management/app/Http/Controllers/AuthController.php#L68-L83)
  - [`resources/views/reference/auth.blade.php`](file:///d:/works/Business-Payment-System-Management/resources/views/reference/auth.blade.php#L35-L45)
  - `lang/en/auth_ui.php` & `lang/bn/auth_ui.php`
- **Symptom:**
  When a partner inputs their assigned code (e.g. `MNHC-1001` or `MNHC-100001-000001`) into the login input, the system fails with: *"These credentials could not be verified."*
- **Root Cause:**
  `AuthController.php` only checks if the identity is an email or matches a normalized phone number regex. If neither matches, it hits `$query->whereRaw('1=0')`, unconditionally failing dealer codes:
  ```php
  // app/Http/Controllers/AuthController.php
  if ($isEmail) {
      $query->whereRaw('LOWER(email) = ?', [Str::lower($identity)]);
  } elseif ($phone) {
      $query->whereNotNull('phone')->whereRaw("phone ~ '^[0-9[:space:]()+.-]+$'")->whereRaw('('.PhoneIdentity::SQL.') = ?', [$phone]);
  } else {
      $query->whereRaw('1=0'); // <--- FAILS HERE
  }
  ```
- **Remediation Steps:**
  1. In [`AuthController.php`](file:///d:/works/Business-Payment-System-Management/app/Http/Controllers/AuthController.php), modify the authentication lookup to check `dealer_code` case-insensitively:
     ```php
     $query = User::where('status', 'active');
     if ($isEmail) {
         $query->whereRaw('LOWER(email) = ?', [Str::lower($identity)]);
     } elseif ($phone) {
         $query->whereNotNull('phone')
             ->whereRaw("phone ~ '^[0-9[:space:]()+.-]+$'")
             ->whereRaw('('.PhoneIdentity::SQL.') = ?', [$phone]);
     } else {
         // Fallback to dealer_code (e.g., MNHC-1001, MNHC-100001-000001)
         $query->whereRaw('UPPER(dealer_code) = ?', [Str::upper($identity)]);
     }
     ```
  2. Update the rate limiter key calculation to include dealer codes:
     ```php
     $rateLimiterIdentity = $isEmail ? Str::lower($identity) : ($phone ?? Str::upper($identity));
     $key = $rateLimiterIdentity.'|'.$r->ip();
     ```
  3. In [`auth.blade.php`](file:///d:/works/Business-Payment-System-Management/resources/views/reference/auth.blade.php), update the input label and placeholder:
     ```blade
     <label for="account-email">{{ $mode === 'login' ? __('auth_ui.email_phone_or_code') : __('auth_ui.email') }}</label>
     <input id="account-email" name="email" value="{{ old('email') }}" required autocomplete="username"
            placeholder="{{ $mode === 'login' ? __('auth_ui.email_phone_or_code_placeholder') : __('auth_ui.email_placeholder') }}">
     ```
  4. Ensure translation keys exist in `lang/en/auth_ui.php` and `lang/bn/auth_ui.php`:
     - English:
       - `'email_phone_or_code' => 'E-Mail, Phone, or Dealer Code',`
       - `'email_phone_or_code_placeholder' => 'Enter e-mail, phone number, or dealer code',`
     - Bengali:
       - `'email_phone_or_code' => 'ই-মেইল, ফোন নম্বর অথবা ডিলার কোড',`
       - `'email_phone_or_code_placeholder' => 'ই-মেইল, ফোন নম্বর অথবা ডিলার কোড লিখুন',`
- **Verification:**
  ```bash
  php artisan test --filter=AuthControllerTest
  ```
  Submit login request with `email=MNHC-1001` and valid password; confirm HTTP 302 redirect to `/overview`.

---

### BUG-02 (P0): Owner Account Credentials Rejected on Live Server

- **Affected Target:** Remote preview database `modinanhac.techteamlead.com`
- **Symptom:**
  Logging in at `https://sadmin.modinanhac.techteamlead.com/login` with `preview-admin@modinanhac.techteamlead.com` / `Ijh4E4n81sMw82Aa1!` returns *"These credentials could not be verified."*
- **Root Cause:**
  1. The user record either does not exist in the remote database, is set to `status != 'active'`, or has an incompatible password hash.
  2. The role is not registered as an owner role (`super_admin`, `md`, or `chairman`) as checked by `app/Support/PortalHost.php` (`$user->owner()`).
- **Remediation Steps:**
  1. Run this Artisan command on the target deployment server (or execute via SSH / deployment pipeline):
     ```bash
     php artisan tinker --execute '
     $u = \App\Models\User::firstOrNew(["email" => "preview-admin@modinanhac.techteamlead.com"]);
     $u->name = "Super Admin";
     $u->role = "super_admin";
     $u->status = "active";
     $u->password = \Illuminate\Support\Facades\Hash::make("Ijh4E4n81sMw82Aa1!");
     $u->save();
     echo "Owner user verified with ID: " . $u->id . " and role: " . $u->role . PHP_EOL;
     '
     ```
  2. Also ensure staff permissions are seeded for the role if required by [`app/Domain/Staff/StaffPermissions.php`](file:///d:/works/Business-Payment-System-Management/app/Domain/Staff/StaffPermissions.php).

---

### BUG-03 (P0): Withdrawal Request Form Silently Hidden with Zero Feedback

- **Affected Files:**
  - [`resources/js/pages/Portal.tsx`](file:///d:/works/Business-Payment-System-Management/resources/js/pages/Portal.tsx#L106-L115)
  - `lang/en/portal.php` & `lang/bn/portal.php`
- **Symptom:**
  When a partner visits `/withdrawals`, the page displays balances (e.g., Available: ৳0.00, Minimum: ৳500.00) followed directly by *"Nothing here yet."* The withdrawal submission form is completely missing, leaving partners confused.
- **Root Cause:**
  In `Portal.tsx`, the form is gated by `{!staff && financeActive && ...}`. In preview mode or whenever `MADINA_FINANCIAL_ACTIVATION=false`, `financeActive` is false, so React silently drops the entire section without explaining why:
  ```tsx
  {['earnings','withdrawals'].includes(section)&&<>
    <FinanceGate/>
    {!staff && financeActive && (
      <section className="panel" data-tour="withdrawal-form">
        <h2>{translate('portal','request_withdrawal')}</h2>
        <ActionForm action="/commands/withdrawals" ... />
      </section>
    )}
  </>}
  ```
- **Remediation Steps:**
  1. In [`Portal.tsx`](file:///d:/works/Business-Payment-System-Management/resources/js/pages/Portal.tsx), add an explicit, friendly feedback banner when `!financeActive`:
     ```tsx
     {['earnings', 'withdrawals'].includes(section) && (
       <>
         <FinanceGate />
         {!staff && (
           financeActive ? (
             <section className="panel" data-tour="withdrawal-form">
               <h2>{translate('portal', 'request_withdrawal')}</h2>
               <ActionForm action="/commands/withdrawals" intent="portal.withdrawal_request" ... />
             </section>
           ) : (
             <div className="notice warning" style={{ marginBottom: 16 }}>
               <h3>{translate('portal', 'withdrawals_temporarily_locked')}</h3>
               <p>{translate('portal', 'withdrawals_pending_activation_notice')}</p>
             </div>
           )
         )}
       </>
     )}
     ```
  2. Add translations to `lang/en/portal.php` and `lang/bn/portal.php`:
     - English:
       - `'withdrawals_temporarily_locked' => 'Withdrawals In Preview Mode',`
       - `'withdrawals_pending_activation_notice' => 'Online withdrawal requests will be activated upon completion of financial verification and policy approval.',`
     - Bengali:
       - `'withdrawals_temporarily_locked' => 'উত্তোলন বর্তমানে প্রিভিউ মোডে রয়েছে',`
       - `'withdrawals_pending_activation_notice' => 'আর্থিক যাচাইকরণ এবং নীতি অনুমোদনের পর অনলাইন উত্তোলন সেবা চালু করা হবে।',`

---

### BUG-04 (P0): UTF-8 Mojibake Corruption in `lang/bn/site.php` & `portal.php`

- **Affected Files:**
  - [`lang/bn/site.php`](file:///d:/works/Business-Payment-System-Management/lang/bn/site.php)
  - [`lang/bn/portal.php`](file:///d:/works/Business-Payment-System-Management/lang/bn/portal.php)
- **Symptom:**
  Switching the public site or portal to Bangla displays garbled question marks and replacement glyphs (e.g. `ݩ݅ݨ ?s ΅ݾ`) instead of proper Bengali script.
- **Root Cause:**
  In git commit `46887d3`, `lang/bn/site.php` and `lang/bn/portal.php` were saved using an incompatible encoding (Windows-1252 / ANSI), corrupting multi-byte UTF-8 Bengali characters into raw replacement markers (``).
- **Remediation Steps:**
  1. Retrieve the clean, uncorrupted UTF-8 version from commit `522ac22`:
     ```bash
     git show 522ac22:lang/bn/site.php > lang/bn/site.php.clean
     git show 522ac22:lang/bn/portal.php > lang/bn/portal.php.clean
     ```
  2. Carefully merge any newly added translation keys introduced in commit `46887d3` (such as `user_manual`, `support_tickets`, `tour_guide`, `referral`) into the clean files using strict UTF-8 without BOM encoding.
  3. Replace the corrupted files with the clean UTF-8 files.
  4. Ensure your text editor / IDE is set to **UTF-8 (without BOM)** before saving.
- **Verification:**
  ```bash
  php -l lang/bn/site.php
  php -l lang/bn/portal.php
  php artisan test --filter=EmployeesSubDealerLocaleTest
  ```
  Check public site with `?lang=bn` and verify all texts render in authentic Bengali script.

---

### UX-01 (P1): Missing "New Purchase Request" CTA on Orders Page

- **Affected File:**
  - [`resources/js/pages/Portal.tsx`](file:///d:/works/Business-Payment-System-Management/resources/js/pages/Portal.tsx#L75-L90)
- **Symptom:**
  When a dealer or sub-dealer navigates to `/orders`, they see their list of past orders. However, there is no button anywhere on the page to create a new purchase order. Users are forced to manually locate and click `/catalog`.
- **Root Cause:**
  In `Portal.tsx`, `action` (the header toolbar CTA button) is only defined when `section === 'overview'`:
  ```tsx
  const action = section === 'overview' ? (
      <Link className="button dark" href={staff ? ... : (base + '/catalog')}>
          {staff ? ... : translate('portal', 'new_purchase_request')}
          <ArrowUpRight size={17}/>
      </Link>
  ) : undefined;
  ```
- **Remediation Steps:**
  Update `action` in `Portal.tsx` to render the purchase button on `orders` and `deliveries`:
  ```tsx
  const action = section === 'overview' ? (
      <Link className="button dark" href={staff ? (base + '/orders') : (base + '/catalog')}>
          {staff ? translate('portal', 'manage_orders') : translate('portal', 'new_purchase_request')}
          <ArrowUpRight size={17}/>
      </Link>
  ) : (!staff && (section === 'orders' || section === 'deliveries')) ? (
      <Link className="button dark" href={`${base}/catalog`}>
          <Plus size={17}/>
          {translate('portal', 'new_purchase_request')}
      </Link>
  ) : undefined;
  ```

---

### UX-02 (P1): Footer Social Media Icons Are Dead Links (`href="#"`)

- **Affected File:**
  - [`resources/views/reference/footer.blade.php`](file:///d:/works/Business-Payment-System-Management/resources/views/reference/footer.blade.php#L70-L85)
- **Symptom:**
  Clicking Facebook, YouTube, LinkedIn, or Instagram icons in the footer causes the page to jump back to `#` at the top of the browser.
- **Root Cause:**
  `footer.blade.php` uses fallback `'#'` when customization keys are not set:
  ```blade
  $socialMap = [
      'facebook' => $siteCustomization['social_facebook'] ?? '#',
      'youtube' => $siteCustomization['social_youtube'] ?? '#',
      'linkedin' => $siteCustomization['social_linkedin'] ?? '#',
      'instagram' => $siteCustomization['social_instagram'] ?? '#',
      'whatsapp' => $siteCustomization['social_whatsapp'] ?? 'https://wa.me/8801730532966',
  ];
  ```
- **Remediation Steps:**
  1. Only render icons when a valid external URL is configured (i.e. not empty and not `'#'`):
     ```blade
     @foreach($socialMap as $platform => $url)
         @if(filled($url) && $url !== '#')
             <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" aria-label="{{ ucfirst($platform) }}">
                 <i class="fa-brands fa-{{ $platform }}"></i>
             </a>
         @endif
     @endforeach
     ```
  2. In `app/Support/SiteChrome.php` or `config/madina.php`, provide default official URLs for Modina's verified social pages.

---

### UX-03 (P1): Rate Limiter Hard Lockout Without Wait Timer

- **Affected Files:**
  - [`app/Http/Controllers/AuthController.php`](file:///d:/works/Business-Payment-System-Management/app/Http/Controllers/AuthController.php#L72-L78)
  - `lang/en/portal.php` & `lang/bn/portal.php`
- **Symptom:**
  After 5 failed login attempts within 60 seconds, the login screen displays a static error message: *"Too many login attempts. Please try again in 60 seconds."* The countdown does not update, and users cannot tell how much time remains.
- **Remediation Steps:**
  1. In `app/Http/Controllers/AuthController.php`, calculate remaining seconds using `RateLimiter::availableIn($key)`:
     ```php
     if (RateLimiter::tooManyAttempts($key, 5)) {
         $seconds = RateLimiter::availableIn($key);
         throw ValidationException::withMessages([
             'email' => __('portal.flash_too_many_attempts_seconds', ['seconds' => $seconds]),
         ]);
     }
     ```
  2. Add translations:
     - English: `'flash_too_many_attempts_seconds' => 'Too many login attempts. Please wait :seconds seconds before trying again.',`
     - Bengali: `'flash_too_many_attempts_seconds' => 'অতিরিক্ত লগইন চেষ্টা করা হয়েছে। অনুগ্রহ করে :seconds সেকেন্ড পর আবার চেষ্টা করুন।',`

---

### UI-01 (P2): Currency Symbol Inconsistency (`BDT` vs `৳`)

- **Affected Files:**
  - [`resources/views/reference/product.blade.php`](file:///d:/works/Business-Payment-System-Management/resources/views/reference/product.blade.php#L43-L58)
  - `resources/views/reference/partials/product-card.blade.php`
  - [`resources/js/components/UI.tsx`](file:///d:/works/Business-Payment-System-Management/resources/js/components/UI.tsx) (`money` formatter)
- **Symptom:**
  Public views show `120.00 BDT`, while portal dashboards show `BDT 120.00` or `৳ 120.00`.
- **Remediation Steps:**
  Standardize on Bangladeshi Taka symbol `৳` across all customer- and partner-facing interfaces:
  1. In `resources/views/reference/product.blade.php`:
     ```blade
     <span>{{ __('site.mrp') }}: <b>৳ {{ number_format((float)$item['mrp'], 2) }}</b></span>
     ```
  2. In `resources/js/components/UI.tsx`:
     ```typescript
     export const money = (value: unknown) => {
         const num = Number(value ?? 0);
         return `৳ ${num.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
     };
     ```

---

### UI-02 (P2): Mobile Catalog Lacks Sticky Checkout Summary Bar

- **Affected Files:**
  - [`resources/js/pages/Portal.tsx`](file:///d:/works/Business-Payment-System-Management/resources/js/pages/Portal.tsx) (`Catalog` component)
  - `resources/css/dashboard.css`
- **Symptom:**
  On mobile devices (`viewport width < 768px`), partners must scroll past all products to find the submit/checkout button after entering product quantities.
- **Remediation Steps:**
  1. In `Portal.tsx` inside the `Catalog` component:
     ```tsx
     {selected.length > 0 && (
         <div className="mobile-sticky-cart-bar">
             <div className="cart-summary">
                 <span className="cart-count">{translate('portal', 'selected_items', { count: String(selected.length) })}</span>
                 <strong className="cart-total">{money(cartTotal)}</strong>
             </div>
             <button type="button" className="button dark" onClick={() => setCheckout(true)}>
                 {translate('portal', 'proceed_to_checkout')}
             </button>
         </div>
     )}
     ```
  2. In `resources/css/dashboard.css`:
     ```css
     @media (max-width: 768px) {
         .mobile-sticky-cart-bar {
             position: fixed;
             bottom: 56px;
             left: 0;
             right: 0;
             background: #ffffff;
             border-top: 1px solid #e2e8f0;
             padding: 12px 16px;
             display: flex;
             justify-content: space-between;
             align-items: center;
             z-index: 50;
             box-shadow: 0 -4px 14px rgba(0, 0, 0, 0.08);
         }
     }
     ```

---

### UI-03 (P2): Sub-Dealer Overview Missing Direct Sponsor Contact CTAs

- **Affected Files:**
  - [`resources/js/components/DashboardOverview.tsx`](file:///d:/works/Business-Payment-System-Management/resources/js/components/DashboardOverview.tsx)
  - `lang/en/portal.php` & `lang/bn/portal.php`
- **Symptom:**
  A sub-dealer's dashboard shows their sponsor dealer code as plain unclickable text with no immediate action to call or message them.
- **Remediation Steps:**
  In `DashboardOverview.tsx`, render a dedicated Sponsor Quick Contact Card:
  ```tsx
  {u.role === 'sub_dealer' && sponsor && (
      <div className="sponsor-card panel">
          <div className="sponsor-info">
              <span className="eyebrow">{translate('portal', 'assigned_sponsor_dealer')}</span>
              <h4>{sponsor.name} ({sponsor.dealer_code})</h4>
              <p>{sponsor.phone || translate('portal', 'no_phone_provided')}</p>
          </div>
          {sponsor.phone && (
              <div className="sponsor-actions">
                  <a href={`tel:${sponsor.phone}`} className="button small">
                      <Phone size={14}/> {translate('portal', 'call_dealer')}
                  </a>
                  <a href={`https://wa.me/${sponsor.phone.replace(/[^0-9]/g, '')}`} target="_blank" rel="noopener noreferrer" className="button small green">
                      <MessageCircle size={14}/> {translate('portal', 'whatsapp')}
                  </a>
              </div>
          )}
      </div>
  )}
  ```

---

### UI-04 (P2): PDF Route Triggers Immediate Download Aborting Navigation

- **Affected Files:**
  - `app/Http/Portals/Shared/WorkspaceController.php`
  - [`resources/js/components/PartnerFullReportHub.tsx`](file:///d:/works/Business-Payment-System-Management/resources/js/components/PartnerFullReportHub.tsx)
- **Symptom:**
  Clicking the PDF statement link triggers a file download header (`Content-Disposition: attachment`), which cancels current page navigation or triggers browser warnings.
- **Remediation Steps:**
  In `PartnerFullReportHub.tsx`, ensure PDF links use an explicit `download` attribute and `target="_blank"`:
  ```tsx
  <a href={`${base}/full-report?format=pdf`} target="_blank" rel="noopener noreferrer" download="partner-statement.pdf" className="button secondary">
      <Download size={16}/> {translate('portal', 'download_pdf_statement')}
  </a>
  ```

---

### PERF-01 (P3): Header Logo Hardcoded 500x500 Dimensions

- **Affected File:**
  - [`resources/views/reference/header.blade.php`](file:///d:/works/Business-Payment-System-Management/resources/views/reference/header.blade.php#L25)
- **Symptom:**
  The logo markup specifies `width="500" height="500"`, causing the browser layout engine to allocate a large square box prior to CSS execution, creating a noticeable layout shift (CLS).
- **Remediation Steps:**
  Replace with responsive aspect-ratio attributes matching rendered size (e.g. 180x48):
  ```blade
  <a href="/" class="ref-logo ref-madina-logo" aria-label="{{ __('site.brand_home') }}">
      <img src="/madina-header-logo.png?v=6" alt="{{ __('site.company_bar') }}" width="180" height="48" style="height: auto; max-height: 48px; width: auto;">
  </a>
  ```

---

### UI-05 (P3): Contextual Empty States Across Portals

- **Affected Files:**
  - `resources/js/pages/Portal.tsx`
  - [`resources/js/components/UI.tsx`](file:///d:/works/Business-Payment-System-Management/resources/js/components/UI.tsx) (`EmptyState` component)
- **Symptom:**
  Deliveries, Dues, and Withdrawals tabs display the generic string: *"Nothing here yet. New activity will appear here as it happens."*
- **Remediation Steps:**
  Pass contextual titles, descriptions, and icon props:
  - **Outstanding Dues:**
    - Title: `"No Outstanding Dues"` / `"কোনো বকেয়া নেই"`
    - Description: `"Your partner account is in good standing with zero overdue balance."`
    - Icon: `CheckCircle2` (green)
  - **Deliveries:**
    - Title: `"No Dispatches In Transit"` / `"চলতি কোনো ডেলিভারি নেই"`
    - Description: `"Dispatched orders with courier tracking numbers will appear here."`
    - Icon: `Truck`
  - **Withdrawals:**
    - Title: `"No Withdrawal History"` / `"উত্তোলনের কোনো তথ্য নেই"`
    - Description: `"Submitted payout requests and disbursement logs will be tracked here."`
    - Icon: `Wallet`

---

## 3. Step-by-Step AI Agent Execution Checklist

```markdown
- [ ] 1. Apply BUG-01: Update AuthController.php for dealer_code login & update auth.blade.php
- [ ] 2. Apply BUG-04: Restore clean UTF-8 for lang/bn/site.php and lang/bn/portal.php
- [ ] 3. Apply BUG-03: Add withdrawal locked notice in Portal.tsx when financeActive is false
- [ ] 4. Apply UX-01: Add "New Purchase Request" CTA in Portal.tsx for orders and deliveries
- [ ] 5. Apply UX-02: Prevent dead href="#" social links in footer.blade.php
- [ ] 6. Apply UX-03: Implement dynamic rate-limit remaining seconds in AuthController.php
- [ ] 7. Apply UI-01: Standardize currency symbol to ৳ in Blade templates and UI.tsx
- [ ] 8. Apply UI-02: Implement mobile sticky checkout bar in Portal.tsx & dashboard.css
- [ ] 9. Apply UI-03: Add sub-dealer sponsor call/WhatsApp contact card in DashboardOverview.tsx
- [ ] 10. Apply UI-04: Add target="_blank" and download attributes to PDF links
- [ ] 11. Apply PERF-01: Fix logo dimensions in header.blade.php
- [ ] 12. Apply UI-05: Enrich EmptyState components with contextual messages
- [ ] 13. Run Pint formatter: vendor/bin/pint --format agent
- [ ] 14. Run Tests: php artisan test --compact
- [ ] 15. Build frontend: npm run build
```

---
*Report prepared following comprehensive live headless browser audit and codebase trace.*
