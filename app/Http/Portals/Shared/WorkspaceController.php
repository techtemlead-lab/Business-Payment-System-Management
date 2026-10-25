<?php

namespace App\Http\Portals\Shared;

use App\Domain\Accounts\PartnerDashboardHome;
use App\Domain\Accounts\PartnerFullReport;
use App\Domain\Accounts\PartnerIdCard;
use App\Domain\Accounts\PartnerJoinPoster;
use App\Domain\Accounts\PartnerWalletStatement;
use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionService;
use App\Domain\Commissions\RetainedFundLedger;
use App\Domain\Finance\DueAdjustments;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Geography\PlaceCodeCatalog;
use App\Domain\Identity\BdrisLookup;
use App\Domain\Pricing\ProductPricing;
use App\Domain\Staff\StaffPermissions;
use App\Http\Controllers\Controller;
use App\Models\AreaDealerAssignment;
use App\Models\CommissionAllocation;
use App\Models\CommissionRuleParameter;
use App\Models\DealerRank;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\RewardMilestoneProgress;
use App\Models\User;
use App\Support\DashboardManagement;
use App\Support\JoinQr;
use App\Support\Money;
use App\Support\PortalCatalog;
use App\Support\PortalHost;
use App\Support\SiteCustomization;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WorkspaceController extends Controller
{
    public function index(Request $r, ProductPricing $pricing, string $section = 'overview'): Response
    {
        $u = $r->user();
        $portal = PortalHost::current($r) ?? PortalHost::forUser($u);
        $staff = in_array($portal, ['manage', 'sadmin'], true);
        if ($staff && $section === 'customization') {
            $section = 'website';
        }
        if ($staff && $section === 'capital-flow') {
            $section = 'treasury';
        }
        PortalCatalog::assertSection($u, $portal, $section);
        $r->validate([
            'q' => 'nullable|string|max:100',
            'status' => 'nullable|string|max:40',
            'page' => 'nullable|integer|min:1',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);
        $orders = Order::query()->when(! $staff, fn ($q) => $q->where('user_id', $u->id))->when($staff && ! $u->allows('orders') && ! $u->allows('finance'), fn ($q) => $q->whereRaw('1=0'));
        $data = [];
        if (in_array($section, ['overview', 'orders', 'deliveries', 'dues', 'reports'])) {
            $q = clone $orders;
            if ($r->filled('q')) {
                $q->where('number', 'like', '%'.$r->string('q').'%');
            }
            if ($r->filled('status')) {
                $q->where('status', $r->string('status'));
            }
            if ($section === 'deliveries') {
                $q->whereIn('status', ['approved', 'partially_dispatched', 'dispatched'])->whereHas('lines', fn ($lines) => $lines->whereColumn('dispatched', '<', 'quantity'));
            }
            if ($section === 'dues') {
                $q->whereIn('status', ['approved', 'partially_dispatched', 'dispatched', 'delivered'])->whereRaw('paid < total-due_credit');
            }
            $data['orders'] = $q->with(['user:id,name,dealer_code', 'lines'])->latest()->paginate(12)->withQueryString();
        }
        if ($section === 'overview') {
            $approved = (clone $orders)->whereIn('status', ['approved', 'partially_dispatched', 'dispatched', 'delivered']);
            $total = (string) (clone $approved)->sum('total');
            $credits = (string) (clone $approved)->sum('due_credit');
            $paid = (string) (clone $approved)->sum('paid');
            $data['stats'] = ['order_count' => (clone $orders)->count(), 'purchases' => Money::decimal(Money::cents($total)),
                'dues' => Money::decimal(Money::cents($total) - Money::cents($paid) - Money::cents($credits)),
                'pending' => (clone $orders)->whereIn('status', ['submitted', 'held'])->count(),
                'supply' => DB::table('order_lines')->whereIn('order_id', (clone $approved)->select('id'))->sum(DB::raw('quantity-dispatched')),
                'accounts' => $u->allows('accounts') ? User::whereIn('role', ['dealer', 'sub_dealer'])->count() : null];
            $data['lowStock'] = $u->allows('inventory') ? Product::where('active', true)->whereColumn('stock', '<=', 'low_stock_at')->limit(5)->get(['id', 'name', 'stock', 'sku']) : [];
            if (in_array($u->role, ['dealer', 'sub_dealer'], true)) {
                $data['partner_home'] = app(PartnerDashboardHome::class)->for($u);
            } elseif ($staff) {
                $data['admin_home'] = [
                    'profile' => [
                        'name' => $u->name,
                        'role' => $u->role,
                        'phone' => $u->phone,
                        'email' => $u->email,
                        'initial' => mb_strtoupper(mb_substr($u->name, 0, 1)),
                        'status' => $u->status,
                    ],
                    'snapshot' => [
                        'orders_today' => (clone $orders)->where('created_at', '>=', CarbonImmutable::now((string) config('madina.business_timezone', config('app.timezone')))->startOfDay()->utc())->count(),
                        'pending_review' => (clone $orders)->whereIn('status', ['submitted', 'held'])->count(),
                        'partner_accounts' => User::whereIn('role', ['dealer', 'sub_dealer'])->count(),
                        'purchases' => $data['stats']['purchases'],
                        'dues' => $data['stats']['dues'],
                    ],
                ];
            }
        }
        if (in_array($section, ['overview', 'catalog', 'inventory']) && (! $staff || $u->allows('catalog') || $u->allows('pricing') || $u->allows('inventory') || $u->allows('orders') || $u->allows('finance'))) {
            $productQuery = Product::query()->when(! $staff, fn ($q) => $q->where('active', true))->when($r->filled('q'), fn ($q) => $q->where(fn ($match) => $match->where('name', 'ilike', '%'.$r->string('q').'%')->orWhere('sku', 'ilike', '%'.$r->string('q').'%')->orWhere('category', 'ilike', '%'.$r->string('q').'%')))->orderBy('name');
            $productLimit = $staff ? 250 : 120;
            $data['products_truncated'] = (clone $productQuery)->count() > $productLimit;
            $products = (clone $productQuery)->limit($productLimit)->get();
            $revealStock = ! $staff || $u->allows('inventory');
            $revealDealerPrice = ! $staff || $u->allows('pricing') || $u->allows('orders') || $u->allows('finance');
            $data['products'] = $products->map(function ($p) use ($u, $pricing, $revealStock, $revealDealerPrice, $staff) {
                $base = [...$p->only(['id', 'sku', 'name', 'category', 'description', 'unit', 'mrp', 'active', 'featured', 'tone', 'is_stock_paused']), 'stock_status' => $p->stockStatus(), ...$p->media(), ...($u->allows('cost_read') ? ['cost_price' => $p->cost_price] : [])];
                if (! $staff) {
                    try {
                        $base['price'] = Money::decimal($pricing->unitPrice($p, $u));
                    } catch (ValidationException) {
                        $base['price'] = null;
                    }
                } elseif ($revealDealerPrice) {
                    $base['price'] = $p->dealer_price;
                }
                if ($revealStock) {
                    $base['stock'] = $p->stock;
                    $base['low_stock_at'] = $p->low_stock_at;
                }

                return $base;
            });
            if ($section === 'catalog') {
                $data['categories'] = SiteCustomization::getConfiguredCategoryNames();
                if ($staff) {
                    $data['customization'] = SiteCustomization::raw();
                    $data['defaults'] = SiteCustomization::defaults();
                    $data['all_catalog_products'] = Product::where('active', true)->select('id', 'name', 'sku', 'mrp', 'dealer_price', 'category', 'featured', 'stock')->orderBy('name')->get()->map(fn ($p) => [
                        'id' => $p->id,
                        'name' => $p->name,
                        'sku' => $p->sku,
                        'mrp' => $p->mrp,
                        ...($revealDealerPrice ? ['dealer_price' => $p->dealer_price] : []),
                        'category' => $p->category,
                        'featured' => (bool) $p->featured,
                        ...($revealStock ? ['stock' => (int) $p->stock] : []),
                        'image' => $p->media()['image_url'] ?? '',
                    ]);
                }
            }
        }
        if ($section === 'inventory') {
            $data['movements'] = DB::table('stock_movements')->join('products', 'products.id', '=', 'product_id')->select('stock_movements.*', 'products.name')->orderByDesc('stock_movements.id')->paginate(20);
        }
        if ($section === 'accounts') {
            $data['accounts'] = User::whereIn('role', ['dealer', 'sub_dealer'])->when($r->filled('q'), function ($q) use ($r) {
                $term = '%'.$r->string('q').'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)
                        ->orWhere('dealer_code', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })->orderByDesc('id')->paginate(20, ['id', 'name', 'email', 'phone', 'role', 'status', 'dealer_code', 'division', 'district', 'latitude', 'longitude', 'directory_visible', 'profile', 'sponsor_id', 'created_at']);
            $data['sponsors'] = User::where('role', 'dealer')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'dealer_code', 'district']);
        }
        if ($section === 'sub-dealers') {
            $data['accounts'] = User::where('role', 'sub_dealer')->when($r->filled('q'), function ($q) use ($r) {
                $term = '%'.$r->string('q').'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)
                        ->orWhere('dealer_code', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })->orderByDesc('id')->paginate(20, ['id', 'name', 'email', 'phone', 'role', 'status', 'dealer_code', 'division', 'district', 'latitude', 'longitude', 'directory_visible', 'profile', 'sponsor_id', 'created_at']);
            $data['sponsors'] = User::where('role', 'dealer')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'dealer_code', 'district']);
            $data['subdealer_stats'] = [
                'total' => User::where('role', 'sub_dealer')->count(),
                'active' => User::where('role', 'sub_dealer')->where('status', 'active')->count(),
                'orders_count' => Order::whereHas('user', fn ($q) => $q->where('role', 'sub_dealer'))->count(),
                'orders_volume' => (float) Order::whereHas('user', fn ($q) => $q->where('role', 'sub_dealer'))->whereIn('status', ['submitted', 'approved', 'dispatched', 'partially_dispatched', 'delivered'])->sum('total'),
            ];
        }
        if ($section === 'sub-dealer-orders') {
            $q = Order::query()->whereHas('user', fn ($qu) => $qu->where('role', 'sub_dealer'));
            if ($r->filled('q')) {
                $q->where('number', 'like', '%'.$r->string('q').'%');
            }
            if ($r->filled('status')) {
                $q->where('status', $r->string('status'));
            }
            $data['orders'] = $q->with(['user:id,name,dealer_code', 'lines'])->latest()->paginate(12)->withQueryString();
        }
        if ($section === 'employees') {
            $query = User::where(fn ($q) => $q->whereIn('role', ['employee', 'sr'])->orWhere('role', 'like', 'custom\\_%'));
            if ($r->filled('q')) {
                $term = '%'.$r->string('q').'%';
                $query->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            }
            if ($r->filled('status')) {
                $query->where('status', $r->string('status'));
            }
            $data['employees'] = $query->orderByDesc('id')->paginate(20, ['id', 'name', 'email', 'phone', 'role', 'status', 'profile', 'created_at'])
                ->through(fn (User $employee) => [...$employee->toArray(), 'effective_permissions' => $employee->capabilities()]);
            $staffQuery = User::where(fn ($q) => $q->whereIn('role', ['employee', 'sr'])->orWhere('role', 'like', 'custom\\_%'));
            $data['employee_stats'] = [
                'total' => (clone $staffQuery)->count(),
                'active' => (clone $staffQuery)->where('status', 'active')->count(),
                'suspended' => (clone $staffQuery)->where('status', 'suspended')->count(),
            ];
            $data['available_permissions'] = [
                ['key' => 'field_sales', 'label' => 'Field Sales Workspace', 'description' => 'Work with partners inside assigned territories', 'category' => 'Field Sales'],
                ['key' => 'sr_orders', 'label' => 'Territory Orders', 'description' => 'Submit and track orders for assigned partners', 'category' => 'Field Sales'],
                ['key' => 'sr_dues', 'label' => 'Territory Dues', 'description' => 'Read assigned partner dues and reports', 'category' => 'Field Sales'],
                ['key' => 'sr_onboarding', 'label' => 'Dealer Applications', 'description' => 'Submit new partner applications for review', 'category' => 'Field Sales'],
                ['key' => 'staff_admin', 'label' => 'Territories & Targets', 'description' => 'Assign territories and monthly targets', 'category' => 'Operations'],
                ['key' => 'payroll', 'label' => 'Payroll Preparation', 'description' => 'Prepare closed-month staff salary drafts', 'category' => 'Finance'],
                ['key' => 'orders', 'label' => 'Orders & Deliveries', 'description' => 'View orders, shipments, deliveries and payment claims', 'category' => 'Operations'],
                ['key' => 'order_review', 'label' => 'Order Review & Approvals', 'description' => 'Approve purchase requests and verify credit allocations', 'category' => 'Operations'],
                ['key' => 'inventory', 'label' => 'Inventory & Stock Movements', 'description' => 'Track stock levels, record receipts and stock adjustments', 'category' => 'Inventory'],
                ['key' => 'catalog', 'label' => 'Product Catalogue & Pricing', 'description' => 'Manage products, units, descriptions and promotional flags', 'category' => 'Inventory'],
                ['key' => 'accounts', 'label' => 'Partner Directory & Accounts', 'description' => 'Manage dealer and sub-dealer profiles and KYC', 'category' => 'Network'],
                ['key' => 'finance', 'label' => 'Treasury, Commissions & Payouts', 'description' => 'View capital waterfall, settlements, commissions and payouts', 'category' => 'Finance'],
                ['key' => 'reports', 'label' => 'Export Reports', 'description' => 'Export order CSVs and financial summaries', 'category' => 'Operations'],
                ['key' => 'content', 'label' => 'Content Management & Customization', 'description' => 'Manage site content pages and visual hero customization', 'category' => 'Marketing'],
                ['key' => 'audit', 'label' => 'Audit & Security Logs', 'description' => 'Inspect append-only system activity and security events', 'category' => 'Security'],
                ['key' => 'pricing', 'label' => 'Price Modification', 'description' => 'Change MRP and wholesale pricing structures', 'category' => 'Pricing'],
                ['key' => 'cost_read', 'label' => 'Cost Price Visibility', 'description' => 'View manufacturing/cost prices on products', 'category' => 'Finance'],
                ['key' => 'identity_write', 'label' => 'KYC / NID Management', 'description' => 'View and edit sensitive NID credentials', 'category' => 'Security'],
            ];
        }
        if ($section === 'dashboards') {
            abort_unless($u->role === 'super_admin' || $u->allows('accounts'), 403);
            $data['dashboard_settings'] = DashboardManagement::get();
            $data['dashboard_defaults'] = DashboardManagement::defaults();
            $data['stats'] = [
                'dealers_count' => User::where('role', 'dealer')->count(),
                'active_dealers' => User::where('role', 'dealer')->where('status', 'active')->count(),
                'sub_dealers_count' => User::where('role', 'sub_dealer')->count(),
                'active_sub_dealers' => User::where('role', 'sub_dealer')->where('status', 'active')->count(),
                'employees_count' => User::where('role', 'employee')->count(),
                'active_employees' => User::where('role', 'employee')->where('status', 'active')->count(),
                'super_admins_count' => User::where('role', 'super_admin')->count(),
            ];
            $data['recent_employees'] = User::where('role', 'employee')->latest()->limit(6)->get(['id', 'name', 'email', 'status', 'profile', 'created_at']);
        }
        if ($section === 'payments') {
            $data['claims'] = DB::table('payment_claims')->join('orders', 'orders.id', '=', 'order_id')->join('users', 'users.id', '=', 'orders.user_id')
                ->select('payment_claims.id', 'payment_claims.order_id', 'payment_claims.channel', 'payment_claims.reference', 'payment_claims.amount', 'payment_claims.status', 'payment_claims.reason', 'payment_claims.created_at', 'orders.number', 'users.name')
                ->orderByDesc('payment_claims.id')->paginate(20);
        }
        if ($section === 'policies') {
            $data['policies'] = DB::table('policy_drafts')->orderByDesc('id')->get();
        }
        if ($section === 'profile') {
            $profile = is_array($u->profile) ? $u->profile : [];
            $identityType = (string) ($profile['identity_type'] ?? ($u->nid ? 'nid' : ''));
            $hasBirthCert = filled($profile['birth_certificate_number'] ?? null);
            $identityOnFile = ($identityType === 'nid' && filled($u->nid))
                || ($identityType === 'birth_certificate' && $hasBirthCert)
                || filled($u->nid)
                || $hasBirthCert;
            $data['profile'] = [
                ...$u->safe(),
                'phone' => $u->phone,
                'shop_name' => $profile['shop_name'] ?? $profile['pharmacy'] ?? null,
                'place_code' => $profile['place_code'] ?? $profile['area_code'] ?? null,
                'name_bn' => $profile['name_bn'] ?? null,
                'upazila' => $profile['upazila'] ?? null,
                'union' => $profile['union'] ?? null,
                'village' => $profile['village'] ?? null,
                'refer_code' => $u->refer_code,
                'kyc' => [
                    'identity_type' => $identityType !== '' ? $identityType : null,
                    'identity_label' => match ($identityType) {
                        'nid' => __('portal.kyc_label_nid'),
                        'birth_certificate' => __('portal.kyc_label_birth_certificate'),
                        default => __('portal.kyc_label_identity'),
                    },
                    'identity_status' => $identityOnFile ? 'on_file' : 'missing',
                    'identity_status_label' => $identityOnFile
                        ? __('portal.kyc_status_on_file')
                        : __('portal.kyc_status_missing'),
                    'date_of_birth_on_file' => filled($profile['date_of_birth'] ?? null),
                ],
            ];
        }
        if ($section === 'network') {
            $data['members'] = DB::table('referral_tree_paths')->join('users', 'users.id', '=', 'descendant_id')->where('ancestor_id', $u->id)
                ->where('depth', '>', 0)->orderBy('depth')->get(['users.id', 'users.name', 'users.dealer_code', 'users.sponsor_id', 'depth', 'users.created_at']);
            if ($r->filled('member_id')) {
                $member = $r->validate(['member_id' => 'required|integer'])['member_id'];
                abort_unless(ReferralTreePath::isDescendant($u->id, (int) $member), 404);
                $data['selected_member'] = User::whereKey($member)->first(['id', 'name', 'dealer_code']);
                $data['member_orders'] = Order::where('user_id', $member)->latest()->paginate(12, ['id', 'number', 'status', 'total', 'paid', 'created_at'])->withQueryString();
            }
        }
        if ($section === 'salary') {
            $month = CommissionQualification::currentMonth();
            $rules = app(CommissionPolicy::class)->snapshot()['rules'];
            if ($staff) {
                $pool = CommissionAllocation::where('category', 'sub_dealer_salary')->where('metadata->period_month', $month);
                $tour = CommissionAllocation::where('category', 'sub_dealer_tour')->where('metadata->period_month', $month);
                $data['salary'] = [
                    'month' => $month,
                    'staff_view' => true,
                    'monthly_purchases' => Money::decimal(Money::cents((string) DB::table('commission_batches')
                        ->where('account_type', 'sub_dealer')->where('period_month', $month)->sum('cash_basis'))),
                    'salary_rate' => $rules['sub_dealer_salary']['percentage'],
                    'tour_rate' => $rules['sub_dealer_tour']['percentage'],
                    'salary_accrued' => (string) (clone $pool)->sum('amount'),
                    'salary_pending' => (string) (clone $pool)->where('status', 'pending')->sum('amount'),
                    'tour_reserved' => (string) $tour->sum('amount'),
                ];
            } else {
                $data['salary'] = ['month' => $month, 'staff_view' => false, 'monthly_purchases' => Money::decimal(app(CommissionQualification::class)->monthlyVolume($u->id, $month)),
                    'salary_rate' => $rules['sub_dealer_salary']['percentage'], 'tour_rate' => $rules['sub_dealer_tour']['percentage'],
                    'salary_accrued' => (string) CommissionAllocation::where('user_id', $u->id)->where('category', 'sub_dealer_salary')->where('metadata->period_month', $month)->sum('amount'),
                    'tour_reserved' => (string) CommissionAllocation::where('user_id', $u->id)->where('category', 'sub_dealer_tour')->where('metadata->period_month', $month)->sum('amount')];
            }
        }
        if (in_array($section, ['withdrawals', 'earnings'])) {
            $earningsFrom = $this->optionalDate($r->input('from'));
            $earningsTo = $this->optionalDate($r->input('to'));
            [$earningsFromUtc, $earningsToUtc] = $this->businessDayBounds($earningsFrom, $earningsTo);
            $data['wallet'] = DB::table('wallets')->where('user_id', $u->id)->first(['available', 'held', 'commission_held']);
            $data['earnings_filters'] = ['from' => $earningsFrom, 'to' => $earningsTo];
            $data['earnings'] = CommissionAllocation::where('user_id', $u->id)
                ->when($earningsFromUtc, fn ($query) => $query->where('created_at', '>=', $earningsFromUtc))
                ->when($earningsToUtc, fn ($query) => $query->where('created_at', '<', $earningsToUtc))
                ->latest()->limit(50)->get();
            $data['settlement_payouts'] = DB::table('commission_settlement_payouts')->where('user_id', $u->id)->latest()->limit(30)->get(['id', 'kind', 'period_month', 'amount', 'reversed_amount', 'created_at']);
            $data['withdrawals'] = DB::table('withdrawals')->when(! $staff, fn ($q) => $q->where('user_id', $u->id))->latest()->paginate(20, ['id', 'amount', 'channel', 'status', 'reason', 'created_at']);
            if (! $staff && $section === 'earnings') {
                $data['wallet_statement'] = app(PartnerWalletStatement::class)->for($u, $earningsFrom, $earningsTo);
            }
        }
        if ($section === 'full-report') {
            abort_unless(in_array($u->role, ['dealer', 'sub_dealer'], true), 403);
            $reportFrom = $this->optionalDate($r->input('from'));
            $reportTo = $this->optionalDate($r->input('to'));
            $data['full_report'] = app(PartnerFullReport::class)->for($u, $reportFrom, $reportTo);
        }
        if ($section === 'circulars') {
            abort_unless(in_array($u->role, ['dealer', 'sub_dealer'], true), 403);
            $data['circulars'] = DB::table('content_pages')
                ->where('published', true)
                ->where(function ($query): void {
                    $query->where('slug', 'notice')
                        ->orWhere('slug', 'like', 'circular-%');
                })
                ->orderByDesc('updated_at')
                ->get(['id', 'slug', 'title', 'body', 'updated_at']);
        }
        if ($section === 'ledger') {
            $data['entries'] = DB::table('journal_entries')->latest()->paginate(20);
        }
        if ($section === 'audit') {
            $data['events'] = DB::table('audit_events')->leftJoin('users', 'users.id', '=', 'actor_id')->select('audit_events.id', 'action', 'subject', 'audit_events.created_at', 'users.name')->orderByDesc('audit_events.id')->paginate(25);
        }
        if ($section === 'content') {
            $data['pages'] = DB::table('content_pages')
                ->whereIn('slug', ['home', 'about', 'contact', 'notice', 'circular-training', 'circular-price', 'circular-meeting', 'partnership', 'services', 'quality', 'faq', 'privacy', 'terms', 'products', 'dealers'])
                ->orderBy('slug')
                ->get();
        }
        if ($section === 'website') {
            $data['customization'] = SiteCustomization::raw();
            $data['defaults'] = SiteCustomization::defaults();
            $enabled = SiteCustomization::enabledPagesMap();
            $data['websitePages'] = collect([
                ['key' => 'home', 'slug' => '/', 'label' => 'Landing page', 'toggleable' => false],
                ['key' => 'about', 'slug' => '/about', 'label' => 'About'],
                ['key' => 'products', 'slug' => '/products', 'label' => 'Products'],
                ['key' => 'dealers', 'slug' => '/dealers', 'label' => 'Dealer directory'],
                ['key' => 'partnership', 'slug' => '/partnership', 'label' => 'Partnership'],
                ['key' => 'services', 'slug' => '/services', 'label' => 'Services'],
                ['key' => 'quality', 'slug' => '/quality', 'label' => 'Quality'],
                ['key' => 'faq', 'slug' => '/faq', 'label' => 'FAQ'],
                ['key' => 'contact', 'slug' => '/contact', 'label' => 'Contact'],
                ['key' => 'privacy', 'slug' => '/privacy', 'label' => 'Privacy'],
                ['key' => 'terms', 'slug' => '/terms', 'label' => 'Terms'],
            ])->map(function (array $page) use ($enabled) {
                $key = $page['key'];
                $toggleable = $page['toggleable'] ?? true;

                return [
                    'key' => $key,
                    'slug' => $page['slug'],
                    'label' => $page['label'],
                    'toggleable' => $toggleable,
                    'enabled' => $toggleable ? ($enabled[$key] ?? true) : true,
                    'flag' => $toggleable ? 'page_enabled_'.$key : null,
                ];
            })->values()->all();
            $revealStock = $u->allows('inventory');
            $revealDealerPrice = $u->allows('pricing') || $u->allows('orders') || $u->allows('finance');
            $data['products'] = Product::where('active', true)->select('id', 'name', 'sku', 'mrp', 'dealer_price', 'category', 'featured', 'stock')->orderBy('name')->get()->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'mrp' => $p->mrp,
                ...($revealDealerPrice ? ['dealer_price' => $p->dealer_price] : []),
                'category' => $p->category,
                'featured' => (bool) $p->featured,
                ...($revealStock ? ['stock' => (int) $p->stock] : []),
                'image' => $p->media()['image_url'] ?? '',
            ]);
        }
        if ($section === 'enquiries') {
            $data['enquiries'] = DB::table('enquiries')
                ->select(['id', 'name', 'phone', 'topic', 'message', 'status', 'created_at', 'updated_at'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(20);
            $data['enquiry_counts'] = [
                'total' => (int) DB::table('enquiries')->count(),
                'new' => (int) DB::table('enquiries')->where('status', 'new')->count(),
                'contacted' => (int) DB::table('enquiries')->where('status', 'contacted')->count(),
                'closed' => (int) DB::table('enquiries')->where('status', 'closed')->count(),
            ];
        }
        if ($section === 'partnerships') {
            $data['applications'] = DB::table('dealer_applications')
                ->select(['id', 'email', 'data', 'submitted_by', 'status', 'user_id', 'reason', 'source', 'created_at', 'reviewed_by'])
                ->orderByDesc('created_at')
                ->paginate(20)
                ->through(function ($row) {
                    $payload = json_decode((string) ($row->data ?? '{}'), true);
                    if (! is_array($payload)) {
                        $payload = [];
                    }

                    return [
                        'id' => $row->id,
                        'email' => $row->email,
                        'name' => $payload['name'] ?? '—',
                        'phone' => $payload['phone'] ?? null,
                        'role' => $payload['role'] ?? null,
                        'division' => $payload['division'] ?? null,
                        'district' => $payload['district'] ?? null,
                        'upazila' => $payload['upazila'] ?? null,
                        'message' => $payload['message'] ?? null,
                        'status' => $row->status,
                        'source' => $row->source ?? 'staff',
                        'submitted_by' => $row->submitted_by,
                        'user_id' => $row->user_id,
                        'reason' => $row->reason,
                        'created_at' => $row->created_at,
                    ];
                });
            $data['application_counts'] = [
                'total' => (int) DB::table('dealer_applications')->count(),
                'pending' => (int) DB::table('dealer_applications')->where('status', 'pending')->count(),
                'approved' => (int) DB::table('dealer_applications')->where('status', 'approved')->count(),
                'rejected' => (int) DB::table('dealer_applications')->where('status', 'rejected')->count(),
            ];
        }
        if ($section === 'notifications') {
            $data['notices'] = DB::table('account_notices')->where('user_id', $u->id)->latest()->paginate(20);
        }
        if ($section === 'commissions') {
            $ownerBenefits = $u->owner() && $portal === 'sadmin';
            $data['rules'] = array_values(app(CommissionPolicy::class)->snapshot(false)['rules']);
            $data['allocations'] = CommissionAllocation::with(['order:id,number,total', 'user:id,name,dealer_code'])
                ->when(! $ownerBenefits, fn ($q) => $q->where('category', '!=', RetainedFundLedger::BenefitsCategory))
                ->latest()->paginate(20);
            $data['pools_summary'] = CommissionAllocation::query()
                ->when(! $ownerBenefits, fn ($q) => $q->where('category', '!=', RetainedFundLedger::BenefitsCategory))
                ->selectRaw('category, sum(amount) as total_amount, count(*) as count')->groupBy('category')->get();
        }
        if ($section === 'network-tree' || ($staff && $section === 'network')) {
            $data['dealers'] = User::whereIn('role', ['dealer', 'sub_dealer'])
                ->with(['rank', 'sponsor:id,name,dealer_code'])
                ->get(['id', 'name', 'dealer_code', 'phone', 'district', 'division', 'sponsor_id', 'role', 'status', 'created_at'])
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'dealer_code' => $d->dealer_code,
                    'phone' => $d->phone,
                    'district' => $d->district,
                    'division' => $d->division,
                    'sponsor_id' => $d->sponsor_id,
                    'sponsor_name' => $d->sponsor?->name,
                    'role' => $d->role,
                    'status' => $d->status,
                    'rank' => $d->rank?->current_rank ?? 'dealer',
                    'lifetime_purchases' => (float) ($d->rank?->lifetime_purchase_total ?? 0),
                    'created_at' => $d->created_at?->toIso8601String(),
                ]);
        }
        if ($section === 'area-dealers') {
            $data['assignments'] = AreaDealerAssignment::where('is_active', true)
                ->with(['user:id,name,dealer_code,phone,district,division', 'placeCode:id,code,name,level'])
                ->orderBy('id')
                ->get();
            $data['divisions'] = PlaceCode::query()
                ->where('level', PlaceCode::LevelDivision)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('code')
                ->pluck('name')
                ->all();
            if ($data['divisions'] === []) {
                $data['divisions'] = ['Dhaka', 'Chattogram', 'Rajshahi', 'Khulna', 'Barishal', 'Sylhet', 'Rangpur', 'Mymensingh'];
            }
            $data['place_codes'] = PlaceCode::query()
                ->whereIn('level', PlaceCode::AssignmentLevels)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'level', 'parent_id']);
            $data['dealers_list'] = User::where('role', 'dealer')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'dealer_code', 'district', 'division']);
        }
        if ($section === 'place-codes') {
            $level = (string) $r->query('level', PlaceCode::LevelDivision);
            if (! in_array($level, PlaceCode::Levels, true)) {
                $level = PlaceCode::LevelDivision;
            }
            $query = $r->query('q');
            $data = array_merge($data, app(PlaceCodeCatalog::class)->modulePayload(
                $level,
                is_string($query) ? $query : null,
            ));
        }
        if ($section === 'ranks') {
            $data['ranks'] = DealerRank::with('user:id,name,dealer_code,phone,district,division,sponsor_id')
                ->when(! $staff, fn ($q) => $q->where('user_id', $u->id))
                ->orderByDesc('lifetime_purchase_total')
                ->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'user_id' => $r->user_id,
                    'name' => $r->user?->name,
                    'dealer_code' => $r->user?->dealer_code,
                    'phone' => $r->user?->phone,
                    'district' => $r->user?->district,
                    'division' => $r->user?->division,
                    'current_rank' => $r->current_rank,
                    'achieved_at' => $r->achieved_at?->toIso8601String(),
                    'lifetime_purchase_total' => (float) $r->lifetime_purchase_total,
                    'monthly_purchase_total' => (float) $r->monthly_purchase_total,
                    'one_time_incentive_paid' => (bool) $r->one_time_incentive_paid,
                    'qualification_history' => collect($r->qualification_history ?? [])
                        ->filter(fn ($entry) => is_array($entry) && isset($entry['rank'], $entry['achieved_at']))
                        ->map(fn ($entry) => [
                            'rank' => (string) $entry['rank'],
                            'achieved_at' => (string) $entry['achieved_at'],
                        ])
                        ->values()
                        ->all(),
                ]);
            $data['stats'] = [];
            foreach (['dealer', 'mo', 'am', 'sm', 'me'] as $code) {
                $data['stats'][$code.'_count'] = $data['ranks']->where('current_rank', $code)->count();
            }
            $policy = app(CommissionPolicy::class)->snapshot()['rules'];
            $data['rank_policy'] = ['qualification' => $policy['rank_royalty']['parameters'], 'incentives' => $policy['rank_incentive']['parameters']];
        }
        if ($section === 'rewards') {
            $mapMilestone = fn ($row) => [...$row->toArray(), 'target_volume' => $row->target_amount, 'is_claimed' => $row->claimed,
                'capped_leg_breakdown' => array_map(fn ($leg) => [...$leg, 'leg_user_id' => $leg['user_id'] ?? $leg['dealer_id'],
                    'leg_user_name' => $leg['name'] ?? $leg['dealer_name'] ?? '', 'capped_volume' => $leg['qualifying_volume']], $row->leg_breakdown ?? [])];
            $data['bike_milestones'] = RewardMilestoneProgress::where('milestone_type', 'bike')->with('user:id,name,dealer_code,phone,district')->orderByDesc('qualifying_volume')->get()->map($mapMilestone);
            $data['car_milestones'] = RewardMilestoneProgress::where('milestone_type', 'car')->with('user:id,name,dealer_code,phone,district')->orderByDesc('qualifying_volume')->get()->map($mapMilestone);
            $data['rule_params'] = app(CommissionPolicy::class)->snapshot()['rules']['car_bike_incentive']['parameters'];
        }
        if ($section === 'capital-flow' || $section === 'treasury') {
            $receipts = DB::table('receipts')->join('orders', 'orders.id', '=', 'receipts.order_id')->join('users', 'users.id', '=', 'orders.user_id');
            $cash = (clone $receipts)->sum('receipts.amount');
            $allocated = CommissionAllocation::where('status', '!=', 'reversed')
                ->where('category', '!=', 'company_commission_benefits_fund')
                ->sum('amount');
            $company = DB::table('commission_batches')->selectRaw('COALESCE(SUM(company_share * (cash_basis - refunded_amount) / cash_basis), 0) AS amount')->value('amount');
            $buffer = DB::table('commission_batches')->selectRaw('COALESCE(SUM(unallocated * (cash_basis - refunded_amount) / cash_basis), 0) AS amount')->value('amount');
            $management = CommissionAllocation::where('category', 'area_management')->where('status', '!=', 'reversed')->sum('amount');
            $unassigned = CommissionAllocation::where('status', 'unassigned_reserve')->sum('amount');
            $data['flow'] = [
                'gross_cash_inflow' => (float) $cash,
                'dealer_cash_inflow' => (float) (clone $receipts)->where('users.role', 'dealer')->sum('receipts.amount'),
                'sub_dealer_cash_inflow' => (float) (clone $receipts)->where('users.role', 'sub_dealer')->sum('receipts.amount'),
                'channel_breakdown' => DB::table('receipts')->selectRaw('channel, SUM(amount) AS total_amount, COUNT(*) AS count')->groupBy('channel')->get(),
                'company_share_60' => round((float) $company, 2),
                'management_reserve_2' => (float) $management,
                'unassigned_territory_reserve' => (float) $unassigned,
                'total_company_deposits' => round((float) $company + (float) $management + (float) $unassigned, 2),
                'commission_pool_40' => (float) $allocated,
                'allocated_so_far' => (float) $allocated,
                'settled_disbursements' => (float) DB::table('withdrawals')->where('status', 'paid')->sum(DB::raw('COALESCE(net_amount, amount)')),
                'maturing_commissions' => (float) CommissionAllocation::where('status', 'maturing')->sum('amount'),
                'marketing_fund' => (float) CommissionAllocation::where('category', 'company_marketing_fund')->sum('amount'),
                'car_bike_fund' => (float) CommissionAllocation::where('category', 'car_bike_incentive_fund')->sum('amount'),
                'tds_tax_withheld' => (float) DB::table('withdrawals')->where('status', 'paid')->sum('withheld_amount'),
                'refunded_purchase_value' => (float) DB::table('commission_refunds')->sum('amount'),
            ];
            $ownerBenefits = $u->owner() && $portal === 'sadmin';
            // Owner-only: purchase surplus after company share + commissions.
            if ($ownerBenefits) {
                $data['flow']['unallocated_pool_buffer'] = round((float) $buffer, 2);
                $data['flow']['commission_benefits_fund'] = round((float) $buffer, 2);
                $data['flow']['owner_benefits_fund_visible'] = true;
            }
            $data['allocations_by_category'] = CommissionAllocation::where('status', '!=', 'reversed')
                ->when(! $ownerBenefits, fn ($q) => $q->where('category', '!=', 'company_commission_benefits_fund'))
                ->selectRaw('category, SUM(amount) AS total_amount, COUNT(*) AS count')->groupBy('category')->get();
            $data['recent_allocations'] = CommissionAllocation::with('user:id,name,dealer_code')
                ->when(! $ownerBenefits, fn ($q) => $q->where('category', '!=', 'company_commission_benefits_fund'))
                ->latest()->limit(20)->get();
            $data['retained_fund_details'] = app(RetainedFundLedger::class)->detail($ownerBenefits);
            $data['payment_accounts'] = $u->allows('finance')
                ? app(PaymentAccounts::class)->listing($u, false, false)
                : [];
        }
        if ($section === 'benefits-fund') {
            $ledger = app(RetainedFundLedger::class);
            $balance = DB::table('commission_batches')
                ->selectRaw('COALESCE(SUM(unallocated * (cash_basis - refunded_amount) / cash_basis), 0) AS amount')
                ->value('amount');
            $entries = CommissionAllocation::query()
                ->where('category', RetainedFundLedger::BenefitsCategory)
                ->with(['order:id,number,user_id,total,commission_policy_snapshot', 'order.user:id,name,dealer_code,division,district,role,profile'])
                ->latest()
                ->paginate(25)
                ->withQueryString()
                ->through(fn (CommissionAllocation $allocation): array => $ledger->benefitsEntry($allocation));
            $data['benefits_fund'] = [
                'balance' => round((float) $balance, 2),
                'entry_count' => CommissionAllocation::where('category', RetainedFundLedger::BenefitsCategory)->where('status', '!=', 'reversed')->count(),
                'description' => __('portal.benefits_fund_desc_full'),
            ];
            $data['benefits_entries'] = $entries;
        }
        if ($section === 'settlements') {
            $currentMonth = $r->input('period_month', now('Asia/Dhaka')->subMonthNoOverflow()->format('Y-m'));
            CommissionQualification::period($currentMonth);
            $salaryPoolAllocations = CommissionAllocation::whereIn('category', ['dealer_monthly_salary_pool', 'sub_dealer_salary'])->where('metadata->period_month', $currentMonth)
                ->where('status', 'pending')
                ->get();
            $rankPoolAllocations = CommissionAllocation::where('category', 'rank_royalty_pool')->where('metadata->period_month', $currentMonth)
                ->where('status', 'pending')
                ->get();
            $maturingAllocations = CommissionAllocation::where('status', 'maturing')
                ->whereNotNull('user_id')
                ->get();

            $salaryRule = CommissionRuleParameter::getRule('dealer_monthly_salary');
            $minSalaryVolume = $salaryRule && isset($salaryRule->parameters['min_monthly_volume']) ? (float) $salaryRule->parameters['min_monthly_volume'] : 10000.00;

            $qualification = app(CommissionQualification::class);
            $salaryCandidates = DealerRank::whereHas('user', fn ($q) => $q->where('status', 'active')->where('role', 'dealer'))
                ->with('user:id,name,dealer_code,phone,district')
                ->get();
            $rankCandidates = DealerRank::whereIn('current_rank', ['mo', 'am', 'sm', 'me'])
                ->whereHas('user', fn ($q) => $q->where('status', 'active'))
                ->with('user:id,name,dealer_code,phone,district')
                ->get();
            $volumeByUser = $qualification->monthlyVolumesFor(
                $salaryCandidates->pluck('user_id')->merge($rankCandidates->pluck('user_id'))->unique()->values()->all(),
                $currentMonth,
            );
            $minSalaryCents = Money::cents((string) $minSalaryVolume);
            $qualifiedSalaryDealers = $salaryCandidates
                ->filter(fn ($rank) => ($volumeByUser[$rank->user_id] ?? 0) >= $minSalaryCents)
                ->values();

            $qualifiedRankLeaders = $rankCandidates->map(function ($r) use ($currentMonth, $qualification, $volumeByUser) {
                $eval = $qualification->paidAs($r->user_id, $currentMonth);

                return [
                    'id' => $r->id,
                    'user_id' => $r->user_id,
                    'name' => $r->user?->name,
                    'dealer_code' => $r->user?->dealer_code,
                    'rank' => $r->current_rank,
                    'paid_as_rank' => $eval['paid_as_rank'],
                    'is_qualified' => $eval['is_qualified'],
                    'in_grace_period' => $eval['in_grace_period'],
                    'monthly_volume' => (float) ($eval['monthly_volume'] ?? Money::decimal($volumeByUser[$r->user_id] ?? 0)),
                    'one_time_incentive_paid' => (bool) $r->one_time_incentive_paid,
                ];
            });

            $settledHistory = DB::table('journal_entries')
                ->where('event_key', 'like', 'commissions.%')
                ->latest()
                ->limit(20)
                ->get();

            $data['settlement_state'] = [
                'current_month' => $currentMonth,
                'salary_pool_pending' => (float) $salaryPoolAllocations->sum('amount'),
                'dealer_salary_pool_pending' => (float) $salaryPoolAllocations->where('category', 'dealer_monthly_salary_pool')->sum('amount'),
                'sub_dealer_salary_pending' => (float) $salaryPoolAllocations->where('category', 'sub_dealer_salary')->sum('amount'),
                'salary_pool_allocations_count' => $salaryPoolAllocations->count(),
                'qualified_salary_dealers_count' => $qualifiedSalaryDealers->count(),
                'qualified_salary_dealers' => $qualifiedSalaryDealers->map(fn ($d) => [
                    'id' => $d->id,
                    'user_id' => $d->user_id,
                    'name' => $d->user?->name,
                    'dealer_code' => $d->user?->dealer_code,
                    'monthly_volume' => ($volumeByUser[$d->user_id] ?? 0) / 100,
                ])->values(),
                'rank_pool_pending' => (float) $rankPoolAllocations->sum('amount'),
                'rank_pool_allocations_count' => $rankPoolAllocations->count(),
                'qualified_rank_leaders_count' => $qualifiedRankLeaders->where('paid_as_rank', '!=', 'dealer')->count(),
                'qualified_rank_leaders' => $qualifiedRankLeaders,
                'maturing_count' => $maturingAllocations->count(),
                'maturing_amount' => (float) $maturingAllocations->sum('amount'),
                'settled_history' => $settledHistory,
            ];
        }
        if ($section === 'simulator') {
            $commissionService = app(CommissionService::class);
            $data['simulation'] = $commissionService->simulatePlan([]);
            $data['active_rules'] = CommissionRuleParameter::orderBy('id')->get();
        }
        if ($section === 'payouts-hub') {
            $data['withdrawals'] = DB::table('withdrawals')
                ->join('users', 'users.id', '=', 'withdrawals.user_id')
                ->select('withdrawals.id', 'withdrawals.user_id', 'withdrawals.amount', 'withdrawals.withheld_amount', 'withdrawals.net_amount', 'withdrawals.channel', 'withdrawals.status', 'withdrawals.reason', 'withdrawals.reviewed_by', 'withdrawals.paid_by', 'withdrawals.payment_reference', 'withdrawals.paid_at', 'withdrawals.created_at', 'users.name as user_name', 'users.dealer_code', 'users.phone')
                ->latest('withdrawals.created_at')
                ->paginate(20);
            $tdsRule = CommissionRuleParameter::getRule('payout_tax_withholding');
            $data['tds_rate'] = $tdsRule ? (float) $tdsRule->percentage : 0.00;
        }

        return Inertia::render(PortalCatalog::page($portal), ['section' => $section, 'data' => $data, 'filters' => $r->only(['q', 'status'])]);
    }

    public function createAccount(Request $r): Response
    {
        $u = $r->user();
        abort_unless($u->allows('accounts'), 403);
        $roles = ['dealer', 'sub_dealer'];
        if ($u->role === 'super_admin') {
            $roles = [...$roles, 'employee', ...array_keys(config('madina.roles')), ...app(StaffPermissions::class)->roleKeys()];
        }
        $sponsors = User::where('role', 'dealer')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'dealer_code', 'refer_code', 'district']);
        $divisions = PlaceCode::query()
            ->where('level', PlaceCode::LevelDivision)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'level', 'parent_id']);

        return Inertia::render('AccountCreate', [
            'roles' => $roles,
            'sponsors' => $sponsors,
            'place_divisions' => $divisions,
            'default_identity_type' => 'birth_certificate',
            'bdris_enabled' => app(BdrisLookup::class)->enabled(),
        ]);
    }

    public function fullReportPdf(Request $r): BinaryFileResponse
    {
        $u = $r->user();
        abort_unless(in_array($u->role, ['dealer', 'sub_dealer'], true), 403);

        return app(PartnerFullReport::class)->downloadPdf(
            $u,
            $this->optionalDate($r->input('from')),
            $this->optionalDate($r->input('to')),
        );
    }

    public function idCardPdf(Request $r): BinaryFileResponse
    {
        $u = $r->user();
        abort_unless(in_array($u->role, ['dealer', 'sub_dealer'], true), 403);

        return app(PartnerIdCard::class)->downloadPdf($u);
    }

    public function joinPosterPdf(Request $r): BinaryFileResponse
    {
        $u = $r->user();
        abort_unless(in_array($u->role, ['dealer', 'sub_dealer'], true), 403);

        return app(PartnerJoinPoster::class)->downloadPdf($u);
    }

    public function referralQrSvg(Request $r): \Illuminate\Http\Response
    {
        $u = $r->user();
        abort_unless(in_array($u->role, ['dealer', 'sub_dealer'], true), 403);
        abort_unless(filled($u->refer_code), 404);
        $url = PortalHost::url('public', '/join/'.$u->refer_code);

        return response(JoinQr::svg($url, 180), 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    private function optionalDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function businessDayBounds(?string $from, ?string $to): array
    {
        $tz = (string) config('madina.business_timezone', 'Asia/Dhaka');
        $fromUtc = $from
            ? CarbonImmutable::createFromFormat('Y-m-d', $from, $tz)->startOfDay()->utc()
            : null;
        $toUtc = $to
            ? CarbonImmutable::createFromFormat('Y-m-d', $to, $tz)->addDay()->startOfDay()->utc()
            : null;

        return [$fromUtc, $toUtc];
    }

    public function show(Request $r, Order $order): Response
    {
        $u = $r->user();
        abort_unless($order->user_id === $u->id || $u->allows('orders') || $u->allows('finance'), 404);

        $replacementSlots = [];
        $replacementCandidates = [];
        if ($u->canAct('finance', 'edit') && in_array($order->status, ['submitted', 'held'], true)) {
            foreach (array_slice($order->commission_policy_snapshot['uplines'] ?? [], 0, 3) as $index => $id) {
                $original = User::find($id);
                if ($original && ($original->status !== 'active' || ! in_array($original->role, ['dealer', 'sub_dealer'], true))) {
                    $replacementSlots[] = ['generation' => $index + 1, 'name' => $original->name];
                }
            }
            if ($replacementSlots !== []) {
                $replacementCandidates = User::where('status', 'active')->whereIn('role', ['dealer', 'sub_dealer'])->where('id', '!=', $order->user_id)
                    ->when($r->filled('replacement_q'), fn ($q) => $q->where(fn ($q) => $q->where('name', 'ilike', '%'.$r->string('replacement_q').'%')->orWhere('dealer_code', 'ilike', '%'.$r->string('replacement_q').'%')))
                    ->orderBy('name')->limit(200)->get(['id', 'name', 'dealer_code']);
            }
        }

        return Inertia::render('OrderDetail', ['replacementSlots' => $replacementSlots, 'replacementCandidates' => $replacementCandidates, 'order' => $order->load(['lines', 'user:id,name,dealer_code']),
            'outstanding' => $order->outstanding(), 'adjustments' => app(DueAdjustments::class)->history($order),
            'claims' => DB::table('payment_claims')->where('order_id', $order->id)->get(['id', 'channel', 'reference', 'amount', 'status', 'reason', 'created_at']),
            'events' => DB::table('order_events')->where('order_id', $order->id)->orderBy('id')->get(['id', 'action', 'metadata', 'created_at']),
            'shipments' => DB::table('shipments')->where('order_id', $order->id)->orderBy('id')->get(['id', 'courier', 'tracking', 'eta', 'status', 'lines', 'delivered_at', 'created_at'])]);
    }
}
