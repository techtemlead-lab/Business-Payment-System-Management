<?php

namespace App\Http\Portals\Shared;

use App\Domain\Accounts\PartnerAccountList;
use App\Domain\Accounts\PartnerAccountProvisioning;
use App\Domain\Accounts\PartnerCertificates;
use App\Domain\Accounts\PartnerEarningsPresenter;
use App\Domain\Accounts\PartnerFullReport;
use App\Domain\Accounts\PartnerIdCard;
use App\Domain\Accounts\PartnerJoinPoster;
use App\Domain\Finance\FinanceSettings;
use App\Domain\Geography\ManagementReferral;
use App\Domain\Identity\BdrisLookup;
use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\BranchScope;
use App\Domain\Inventory\StockMovementList;
use App\Domain\Orders\AccountOrderPayment;
use App\Domain\Orders\CourierOptions;
use App\Domain\Orders\MixedOutcomeDecisions;
use App\Domain\Orders\OrderInvoice;
use App\Domain\Orders\OrderPaymentPrompt;
use App\Domain\Orders\OrderRefund;
use App\Domain\Orders\ShipmentTracking;
use App\Domain\Orders\UplineReplacementOptions;
use App\Domain\Pricing\ProductPricing;
use App\Domain\Staff\StaffPermissions;
use App\Domain\Support\GuidePrerequisites;
use App\Http\Controllers\Controller;
use App\Http\Portals\Shared\Sections\AccountsSection;
use App\Http\Portals\Shared\Sections\AgreementSection;
use App\Http\Portals\Shared\Sections\BranchesSection;
use App\Http\Portals\Shared\Sections\CatalogSection;
use App\Http\Portals\Shared\Sections\CommissionsSection;
use App\Http\Portals\Shared\Sections\ContentSection;
use App\Http\Portals\Shared\Sections\DataCleanupSection;
use App\Http\Portals\Shared\Sections\DealerToolsSection;
use App\Http\Portals\Shared\Sections\EmployeesSection;
use App\Http\Portals\Shared\Sections\EventsSection;
use App\Http\Portals\Shared\Sections\FeedbackSection;
use App\Http\Portals\Shared\Sections\FinanceSection;
use App\Http\Portals\Shared\Sections\InventorySection;
use App\Http\Portals\Shared\Sections\NetworkSection;
use App\Http\Portals\Shared\Sections\OfficeHubSection;
use App\Http\Portals\Shared\Sections\OrdersSection;
use App\Http\Portals\Shared\Sections\OverviewSection;
use App\Http\Portals\Shared\Sections\PrescriberPublicSection;
use App\Http\Portals\Shared\Sections\PrescriptionsSection;
use App\Http\Portals\Shared\Sections\PrescriptionToolsSection;
use App\Http\Portals\Shared\Sections\PrivacyRequestsSection;
use App\Http\Portals\Shared\Sections\ProfileSection;
use App\Http\Portals\Shared\Sections\PublicTrustSection;
use App\Http\Portals\Shared\Sections\PurchasingSection;
use App\Http\Portals\Shared\Sections\SectionContext;
use App\Http\Portals\Shared\Sections\SectionDates;
use App\Http\Portals\Shared\Sections\StockCountsSection;
use App\Http\Portals\Shared\Sections\StockLotsSection;
use App\Http\Portals\Shared\Sections\SupportSection;
use App\Http\Portals\Shared\Sections\TerritorySection;
use App\Http\Portals\Shared\Sections\TopSellersSection;
use App\Http\Portals\Shared\Sections\TrainingSection;
use App\Http\Portals\Shared\Sections\WithdrawalsSection;
use App\Http\Portals\Shared\Sections\WorkspaceSection;
use App\Jobs\RefreshShipmentTracking;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\User;
use App\Support\JoinQr;
use App\Support\Money;
use App\Support\OfficeHubs;
use App\Support\PartnerHubTabs;
use App\Support\PortalCatalog;
use App\Support\PortalHost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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
        OfficeHubSection::class,
        WithdrawalsSection::class,
        SupportSection::class,
        FeedbackSection::class,
        CommissionsSection::class,
        TerritorySection::class,
        TopSellersSection::class,
        AgreementSection::class,
        TrainingSection::class,
        EventsSection::class,
        DataCleanupSection::class,
        PrescriptionsSection::class,
        PrescriptionToolsSection::class,
        PublicTrustSection::class,
        DealerToolsSection::class,
        PrescriberPublicSection::class,
        PrivacyRequestsSection::class,
        PurchasingSection::class,
        StockCountsSection::class,
        StockLotsSection::class,
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
            'all_branches' => 'nullable|boolean',
            'category' => 'nullable|string|max:100',
            'sub_category' => 'nullable|string|max:100',
            'unit' => 'nullable|string|max:30',
            'min_mrp' => 'nullable|numeric|min:0',
            'max_mrp' => 'nullable|numeric|min:0',
            'availability' => 'nullable|in:published,draft,in_stock,out_of_stock',
            'sort' => in_array($section, ['inventory', 'payments', 'audit', 'payouts-hub'], true) ? 'nullable|string|max:40' : 'nullable|in:name,mrp,stock,created,name_asc,name_desc,price_asc,price_desc,newest,stock_asc,stock_desc',
            ...Arr::only(PartnerAccountList::rules(), ['division', 'district', 'rank', 'no_order_days']),
            ...Arr::only(StockMovementList::rules(), ['product_id', 'movement']),
        ]);
        if ($staff && $section === 'sub-dealer-orders') {
            return redirect('/orders?'.http_build_query(array_filter([
                'buyer_role' => 'sub_dealer',
                'q' => $r->query('q'),
                'status' => $r->query('status'),
                'page' => $r->query('page'),
            ], fn ($value) => $value !== null && $value !== '')));
        }
        if ($staff && $section === 'reports') {
            return redirect(OfficeHubs::oldReportsAddress($u, in_array($portal, ['manage', 'sadmin'], true), $r->query()));
        }
        $orders = Order::query()->when(! $staff, fn ($q) => $q->where('user_id', $u->id))->when($staff && ! $u->allows('orders') && ! $u->allows('finance'), fn ($q) => $q->whereRaw('1=0'));
        // Staff pages that follow the top-bar branch switcher show only the picked branch.
        $branchScope = $staff && BranchScope::covers($section) ? app(BranchOperations::class)->scopeFor($u, $r) : null;
        if ($branchScope !== null) {
            $branchScope->restrictOrders($orders);
        }
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
            branchScope: $branchScope,
        );
        $data = [];
        foreach (self::Sections as $workspaceSection) {
            $data = array_merge($data, app($workspaceSection)->data($context, $data));
        }

        // The page tour is built once, by the shared `tour` prop in HandleInertiaRequests.
        if (in_array($u->role, ['dealer', 'sub_dealer'], true) && ! $staffWorkspace) {
            $data['guide_prerequisites'] = app(GuidePrerequisites::class)->forSection($u, $section);
        }
        if ($branchScope !== null) {
            $data['branch_scope'] = $branchScope->chip();
        }
        $partnerHub = PartnerHubTabs::forSection($section, $staff);
        if ($partnerHub !== null) {
            $data['partner_hub'] = $partnerHub;
        }

        return Inertia::render(PortalCatalog::page($portal), ['section' => $section, 'data' => $data, 'filters' => $r->only(['q', 'status', 'buyer_role', 'channel', 'category', 'sub_category', 'unit', 'min_mrp', 'max_mrp', 'availability', 'sort', 'period', 'from', 'to', 'division', 'district', 'rank', 'no_order_days', 'branch_id', 'all_branches', 'product_id', 'movement'])]);
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
        // Only the staff form has the sponsor dropdown; partners get their sponsor from a typed reference code.
        $sponsors = $intent === 'staff'
            ? User::whereIn('role', PartnerAccountProvisioning::SponsorRoles)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'dealer_code'])
            : collect();
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
        abort_unless($order->user_id === $u->id
            || (($u->allows('orders') || $u->allows('finance')) && app(BranchOperations::class)->mayOpenOrder($u, $order)), 404);

        ['slots' => $replacementSlots, 'candidates' => $replacementCandidates] = app(UplineReplacementOptions::class)->for(
            $u,
            $order,
            $r->filled('replacement_q') ? $r->string('replacement_q')->toString() : null,
        );

        $claims = DB::table('payment_claims')->where('order_id', $order->id)->get(['id', 'channel', 'reference', 'amount', 'status', 'reason', 'created_at']);

        return Inertia::render('OrderDetail', ['replacementSlots' => $replacementSlots, 'replacementCandidates' => $replacementCandidates, 'order' => $order->load(['lines', 'user:id,name,dealer_code']),
            'commissionOutcome' => app(MixedOutcomeDecisions::class)->outcomeFor($order, $u),
            'outstanding' => $order->outstanding(),
            'paymentPrompt' => app(OrderPaymentPrompt::class)->for($order, $claims),
            'fullPaymentRequired' => app(FinanceSettings::class)->fullPaymentWhenOrdering()['enabled'],
            'claims' => $claims,
            'events' => $this->orderHistory($order, showCourierDetails: (int) $order->user_id !== (int) $u->id),
            'shipments' => $this->shipmentsWithTracking($order, showBookingErrors: (int) $order->user_id !== (int) $u->id),
            'courierChoices' => $u->allows('inventory') || $u->allows('order_review') ? app(CourierOptions::class)->enabled() : [],
            'refund' => DB::table('order_refunds as f')->join('users as staff', 'staff.id', '=', 'f.refunded_by')->where('f.order_id', $order->id)
                ->first(['f.amount', 'f.to_account', 'f.reason', 'f.created_at', 'staff.name as refunded_by']),
            'payOptions' => (int) $u->id === (int) $order->user_id && ! $u->staff() && ! $order->is_opening_balance ? app(AccountOrderPayment::class)->options($u) : null,
            'canRefund' => OrderRefund::canRefund($u) && (int) $u->id !== (int) $order->user_id
                && in_array($order->status, OrderRefund::RefundableStatuses, true) && Money::cents($order->paid) > 0]);
    }

    /**
     * The order's history. The courier's error text and consignment id stay with staff, the
     * same as on the parcel itself.
     *
     * @return Collection<int, \stdClass>
     */
    private function orderHistory(Order $order, bool $showCourierDetails): Collection
    {
        $events = DB::table('order_events')->where('order_id', $order->id)->orderBy('id')->get(['id', 'action', 'metadata', 'created_at']);
        if ($showCourierDetails) {
            return $events;
        }

        return $events->each(function (\stdClass $event): void {
            if (in_array($event->action, ['order.courier_booking_failed', 'order.courier_booked', 'order.courier_auto_skipped', 'shipment.received_back'], true)) {
                $metadata = json_decode((string) $event->metadata, true) ?: [];
                unset($metadata['message'], $metadata['consignment_id'], $metadata['reason']);
                $event->metadata = json_encode($metadata, JSON_THROW_ON_ERROR);
            }
        });
    }

    /**
     * The order's parcels with their courier tracking. Opening the page also asks the courier
     * for news in the background when the last question is more than a few minutes old, per
     * parcel, so the page (which refreshes itself every few seconds) never waits for the courier.
     *
     * @return Collection<int, \stdClass>
     */
    private function shipmentsWithTracking(Order $order, bool $showBookingErrors): Collection
    {
        $shipments = DB::table('shipments')->where('order_id', $order->id)->orderBy('id')->get([
            'id', 'courier', 'tracking', 'courier_contact', 'eta', 'status', 'courier_booking_status', 'courier_booking_error', 'courier_provider',
            'courier_consignment_id', 'courier_status', 'courier_status_raw', 'courier_status_at', 'courier_tracked_at', 'courier_answered_at', 'courier_timeline',
            'lines', 'delivered_at', 'created_at',
        ]);
        $manualPages = $shipments->contains(fn (\stdClass $shipment): bool => CourierOptions::isManual($shipment->courier_provider))
            ? app(CourierOptions::class)->manualTrackingPages()
            : [];
        foreach ($shipments as $shipment) {
            $shipment->tracking_url = CourierOptions::trackingUrl($shipment->courier_provider, $shipment->tracking, $shipment->courier_consignment_id, $manualPages);
            $shipment->courier_timeline = json_decode((string) ($shipment->courier_timeline ?? '[]'), true) ?: [];
            $shipment->courier_answered_minutes_ago = $shipment->courier_answered_at !== null ? (int) Carbon::parse($shipment->courier_answered_at)->diffInMinutes(now()) : null;
            if (ShipmentTracking::isDueForRefresh($shipment) && Cache::add('courier-tracking:'.$shipment->id, true, now()->addMinutes(ShipmentTracking::RefreshAfterMinutes))) {
                RefreshShipmentTracking::dispatch((int) $shipment->id);
            }
            unset($shipment->courier_consignment_id);
            if (! $showBookingErrors) {
                unset($shipment->courier_booking_error);
            }
        }

        return $shipments;
    }
}
