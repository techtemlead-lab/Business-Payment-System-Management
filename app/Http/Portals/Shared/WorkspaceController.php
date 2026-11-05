<?php

namespace App\Http\Portals\Shared;

use App\Domain\Accounts\PartnerAccountList;
use App\Domain\Accounts\PartnerCertificates;
use App\Domain\Accounts\PartnerEarningsPresenter;
use App\Domain\Accounts\PartnerFullReport;
use App\Domain\Accounts\PartnerIdCard;
use App\Domain\Accounts\PartnerJoinPoster;
use App\Domain\Finance\DueAdjustments;
use App\Domain\Geography\ManagementReferral;
use App\Domain\Identity\BdrisLookup;
use App\Domain\Orders\OrderInvoice;
use App\Domain\Orders\UplineReplacementOptions;
use App\Domain\Pricing\ProductPricing;
use App\Domain\Staff\StaffPermissions;
use App\Domain\Support\GuidePrerequisites;
use App\Domain\Support\TourGuide;
use App\Http\Controllers\Controller;
use App\Http\Portals\Shared\Sections\AccountsSection;
use App\Http\Portals\Shared\Sections\AgreementSection;
use App\Http\Portals\Shared\Sections\BranchesSection;
use App\Http\Portals\Shared\Sections\CatalogSection;
use App\Http\Portals\Shared\Sections\CommissionsSection;
use App\Http\Portals\Shared\Sections\ContentSection;
use App\Http\Portals\Shared\Sections\EmployeesSection;
use App\Http\Portals\Shared\Sections\EventsSection;
use App\Http\Portals\Shared\Sections\FeedbackSection;
use App\Http\Portals\Shared\Sections\FinanceSection;
use App\Http\Portals\Shared\Sections\InventorySection;
use App\Http\Portals\Shared\Sections\NetworkSection;
use App\Http\Portals\Shared\Sections\OrdersSection;
use App\Http\Portals\Shared\Sections\OverviewSection;
use App\Http\Portals\Shared\Sections\ProfileSection;
use App\Http\Portals\Shared\Sections\SalarySection;
use App\Http\Portals\Shared\Sections\SectionContext;
use App\Http\Portals\Shared\Sections\SectionDates;
use App\Http\Portals\Shared\Sections\SupportSection;
use App\Http\Portals\Shared\Sections\TerritorySection;
use App\Http\Portals\Shared\Sections\TopSellersSection;
use App\Http\Portals\Shared\Sections\TrainingSection;
use App\Http\Portals\Shared\Sections\WithdrawalsSection;
use App\Http\Portals\Shared\Sections\WorkspaceSection;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\User;
use App\Support\JoinQr;
use App\Support\PortalCatalog;
use App\Support\PortalHost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WorkspaceController extends Controller
{
    /**
     * Workspace areas in the order they fill the page props. The order matters
     * where two areas serve one section: orders before overview, and catalog
     * before inventory, which reads the catalog's product list.
     *
     * @var list<class-string<WorkspaceSection>>
     */
    private const Sections = [
        OrdersSection::class,
        OverviewSection::class,
        CatalogSection::class,
        InventorySection::class,
        BranchesSection::class,
        AccountsSection::class,
        EmployeesSection::class,
        FinanceSection::class,
        ContentSection::class,
        ProfileSection::class,
        NetworkSection::class,
        SalarySection::class,
        WithdrawalsSection::class,
        SupportSection::class,
        FeedbackSection::class,
        CommissionsSection::class,
        TerritorySection::class,
        TopSellersSection::class,
        AgreementSection::class,
        TrainingSection::class,
        EventsSection::class,
    ];

    public function index(Request $r, ProductPricing $pricing, string $section = 'overview'): Response|RedirectResponse
    {
        $u = $r->user();
        $portal = PortalHost::current($r) ?? PortalHost::forUser($u);
        $staffWorkspace = $u->usingStaffWorkspace();
        $staff = in_array($portal, ['manage', 'sadmin'], true) || $staffWorkspace;
        $catalogPortal = $staffWorkspace ? 'manage' : $portal;
        if ($staff && $section === 'customization') {
            $section = 'website';
        }
        if ($staff && $section === 'capital-flow') {
            $section = 'treasury';
        }
        PortalCatalog::assertSection($u, $catalogPortal, $section);
        $periodRule = $section === 'branch-performance'
            ? 'nullable|in:today,month,custom'
            : 'nullable|in:today,week,month,last_month,year,day,custom';
        $r->validate([
            'q' => 'nullable|string|max:100',
            'status' => 'nullable|string|max:40',
            'channel' => 'nullable|string|max:20',
            'buyer_role' => 'nullable|in:dealer,sub_dealer',
            'page' => 'nullable|integer|min:1',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'period' => $periodRule,
            'branch_id' => 'nullable|integer|min:1',
            'category' => 'nullable|string|max:100',
            'sub_category' => 'nullable|string|max:100',
            'unit' => 'nullable|string|max:30',
            'min_mrp' => 'nullable|numeric|min:0',
            'max_mrp' => 'nullable|numeric|min:0',
            'availability' => 'nullable|in:published,draft,in_stock,out_of_stock',
            'sort' => 'nullable|in:name_asc,name_desc,price_asc,price_desc,newest,stock_asc,stock_desc',
            ...Arr::only(PartnerAccountList::rules(), ['division', 'district', 'rank', 'no_order_days']),
        ]);
        if ($staff && $section === 'sub-dealer-orders') {
            return redirect('/orders?'.http_build_query(array_filter([
                'buyer_role' => 'sub_dealer',
                'q' => $r->query('q'),
                'status' => $r->query('status'),
                'page' => $r->query('page'),
            ], fn ($value) => $value !== null && $value !== '')));
        }
        $orders = Order::query()->when(! $staff, fn ($q) => $q->where('user_id', $u->id))->when($staff && ! $u->allows('orders') && ! $u->allows('finance'), fn ($q) => $q->whereRaw('1=0'));
        $context = new SectionContext(
            request: $r,
            user: $u,
            portal: $portal,
            section: $section,
            isStaff: $staff,
            usingStaffWorkspace: $staffWorkspace,
            catalogPortal: $catalogPortal,
            orders: $orders,
            pricing: $pricing,
        );
        $data = [];
        foreach (self::Sections as $workspaceSection) {
            $data = array_merge($data, app($workspaceSection)->data($context, $data));
        }

        $tourGuide = app(TourGuide::class);
        $tourDefinitions = $tourGuide->definitionsFor($u);
        if ($tourDefinitions !== []) {
            $tourKey = array_key_exists($section, $tourDefinitions) ? $section : 'overview';
            $data['tour'] = $tourGuide->forPage($u, $tourKey);
        }
        if (in_array($u->role, ['dealer', 'sub_dealer'], true) && ! $staffWorkspace) {
            $data['guide_prerequisites'] = app(GuidePrerequisites::class)->forSection($u, $section);
        }

        return Inertia::render(PortalCatalog::page($portal), ['section' => $section, 'data' => $data, 'filters' => $r->only(['q', 'status', 'buyer_role', 'channel', 'category', 'sub_category', 'unit', 'min_mrp', 'max_mrp', 'availability', 'sort', 'period', 'from', 'to', 'division', 'district', 'rank', 'no_order_days'])]);
    }

    public function createAccount(Request $r): Response
    {
        $u = $r->user();
        abort_unless($u->allows('accounts'), 403);
        $intent = $r->string('intent')->toString();
        if (! in_array($intent, ['dealer', 'sub_dealer', 'staff'], true)) {
            $intent = 'dealer';
        }
        $roles = match ($intent) {
            'sub_dealer' => ['sub_dealer'],
            'staff' => $u->role === 'super_admin'
                ? ['employee', ...array_keys(config('madina.roles')), ...app(StaffPermissions::class)->roleKeys()]
                : ['employee'],
            default => ['dealer'],
        };
        abort_if($intent === 'staff' && $u->role !== 'super_admin', 403);
        $sponsors = User::where('role', 'dealer')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'dealer_code', 'refer_code', 'district']);
        $divisions = PlaceCode::query()
            ->where('level', PlaceCode::LevelDivision)
            ->where('is_active', true)
            ->whereNotNull('code')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'level', 'parent_id', 'is_active'])
            ->filter(fn (PlaceCode $place): bool => $place->isReadyForAssignment())
            ->values();

        return Inertia::render('AccountCreate', [
            'intent' => $intent,
            'roles' => array_values(array_unique($roles)),
            'sponsors' => $sponsors,
            'place_divisions' => $divisions,
            'default_identity_type' => 'birth_certificate',
            'bdris_enabled' => app(BdrisLookup::class)->enabled(),
            'management_referral' => app(ManagementReferral::class)->settings(),
        ]);
    }

    public function fullReportPdf(Request $r): BinaryFileResponse
    {
        $u = $r->user();
        abort_unless(in_array($u->role, ['dealer', 'sub_dealer'], true), 403);
        $presenter = app(PartnerEarningsPresenter::class);
        $resolved = $presenter->resolvePreset(
            $r->string('preset')->toString() ?: null,
            SectionDates::optionalDate($r->input('from')),
            SectionDates::optionalDate($r->input('to')),
        );

        return app(PartnerFullReport::class)->downloadPdf(
            $u,
            $resolved['from'],
            $resolved['to'],
        );
    }

    public function orderInvoice(Request $r, Order $order): BinaryFileResponse
    {
        return app(OrderInvoice::class)->downloadPdf($r->user(), $order);
    }

    public function orderInvoicePrint(Request $r, Order $order): \Illuminate\Http\Response
    {
        return app(OrderInvoice::class)->printHtml($r->user(), $order);
    }

    public function idCardPdf(Request $r): BinaryFileResponse
    {
        $u = $r->user();
        abort_unless(in_array($u->role, ['dealer', 'sub_dealer'], true), 403);

        return app(PartnerIdCard::class)->downloadPdf($u);
    }

    /** Yearly income certificate PDF for the signed-in partner (?year=2026, default this year). */
    public function incomeCertificatePdf(Request $r): BinaryFileResponse
    {
        $year = (int) ($r->validate(['year' => 'nullable|integer|min:2020|max:2100'])['year'] ?? 0) ?: (int) now('Asia/Dhaka')->format('Y');

        return app(PartnerCertificates::class)->incomePdf($r->user(), $year);
    }

    /** Rank certificate PDF for a dealer who holds a rank above dealer. */
    public function rankCertificatePdf(Request $r): BinaryFileResponse
    {
        return app(PartnerCertificates::class)->rankPdf($r->user());
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

    public function show(Request $r, Order $order): Response
    {
        $u = $r->user();
        abort_unless($order->user_id === $u->id || $u->allows('orders') || $u->allows('finance'), 404);

        ['slots' => $replacementSlots, 'candidates' => $replacementCandidates] = app(UplineReplacementOptions::class)->for(
            $u,
            $order,
            $r->filled('replacement_q') ? $r->string('replacement_q')->toString() : null,
        );

        return Inertia::render('OrderDetail', ['replacementSlots' => $replacementSlots, 'replacementCandidates' => $replacementCandidates, 'order' => $order->load(['lines', 'user:id,name,dealer_code']),
            'outstanding' => $order->outstanding(), 'adjustments' => app(DueAdjustments::class)->history($order),
            'claims' => DB::table('payment_claims')->where('order_id', $order->id)->get(['id', 'channel', 'reference', 'amount', 'status', 'reason', 'created_at']),
            'events' => DB::table('order_events')->where('order_id', $order->id)->orderBy('id')->get(['id', 'action', 'metadata', 'created_at']),
            'shipments' => DB::table('shipments')->where('order_id', $order->id)->orderBy('id')->get(['id', 'courier', 'tracking', 'eta', 'status', 'lines', 'delivered_at', 'created_at'])]);
    }
}
