<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\PartnerMoneyBook;
use App\Domain\Finance\EventLog;
use App\Domain\Finance\OrderMoneyReport;
use App\Domain\Finance\PartnerMoneyList;
use App\Domain\Finance\ReportData;
use App\Domain\Finance\TreasuryAccess;
use App\Domain\Finance\TreasuryFunds;
use App\Domain\Finance\TreasuryWork;
use App\Domain\Inventory\BranchOperations;
use App\Domain\Payouts\PayoutReceipts;
use App\Models\Order;
use App\Models\User;
use App\Support\OfficeHubs;
use App\Support\PortalHost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Treasury pages for the office. Each page is read-only; money is moved only by the existing
 * command endpoints.
 */
class TreasuryController extends Controller
{
    public function __construct(private TreasuryWork $work) {}

    public function funds(Request $request, TreasuryFunds $funds): Response
    {
        abort_unless(TreasuryAccess::mayOpenFunds($request->user()), 403);

        return Inertia::render('TreasuryFunds', [...$this->shared($request), 'cards' => $funds->cards($this->showsOwnerFunds($request)), 'partner_totals' => $funds->partnerTotals()]);
    }

    public function fund(Request $request, string $fund, TreasuryFunds $funds): Response
    {
        abort_unless(TreasuryAccess::mayOpenFunds($request->user()), 403);
        $card = $funds->card($fund, $this->showsOwnerFunds($request));
        $view = $request->query('view') === 'out' ? 'out' : 'in';

        return Inertia::render('TreasuryFund', [
            ...$this->shared($request),
            'card' => $card,
            'view' => $view,
            'table' => $view === 'out' ? $funds->moneyOut($request, $fund) : $funds->moneyIn($request, $fund),
            'spend' => $card['spendable'] ? [
                'heads' => DB::table('fund_expense_heads')->where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_bn', 'for_tours']),
                'methods' => PayoutReceipts::Methods,
                'available' => $funds->spendable($fund),
            ] : null,
        ]);
    }

    public function orders(Request $request, OrderMoneyReport $report, BranchOperations $branches): Response
    {
        $viewer = $request->user();
        abort_unless(TreasuryAccess::mayOpenOrders($viewer), 403);
        $scope = $branches->canViewAllBranches($viewer) ? null : $branches->scopeFor($viewer, $request);

        return Inertia::render('TreasuryOrders', [...$this->shared($request), 'table' => $report->table($request, $scope), 'downloads' => ['groups' => OfficeHubs::reportGroups($viewer, app(ReportData::class)->availableTo($viewer)), 'statements' => $viewer->staff() && $viewer->allows('finance')]]);
    }

    public function order(Request $request, Order $order, OrderMoneyReport $report, BranchOperations $branches): Response
    {
        $viewer = $request->user();
        abort_unless(TreasuryAccess::mayOpenOrders($viewer) && $branches->mayOpenOrder($viewer, $order), 403);

        return Inertia::render('TreasuryOrder', [...$this->shared($request), 'detail' => $report->detail($order, $this->showsOwnerFunds($request))]);
    }

    public function events(Request $request, EventLog $log): Response
    {
        abort_unless(TreasuryAccess::mayOpenEvents($request->user()), 403);

        return Inertia::render('TreasuryEvents', [...$this->shared($request), 'table' => $log->table($request, $request->user(), $this->portal($request))]);
    }

    public function partners(Request $request, PartnerMoneyList $list): Response
    {
        abort_unless(TreasuryAccess::mayOpenPartners($request->user()), 403);

        return Inertia::render('TreasuryPartners', [...$this->shared($request), 'table' => $list->table($request)]);
    }

    public function partner(Request $request, User $partner, PartnerMoneyBook $book): Response
    {
        abort_unless(TreasuryAccess::mayOpenPartner($request->user()), 403);
        abort_unless(in_array($partner->role, ['dealer', 'sub_dealer'], true), 404);

        return Inertia::render('TreasuryPartner', [
            ...$this->shared($request),
            'partner' => ['id' => $partner->id, 'name' => $partner->name, 'code' => $partner->dealer_code, 'role' => $partner->role, 'area' => OrderMoneyReport::areaOf((object) ['profile' => $partner->getAttribute('profile'), 'district' => $partner->district, 'division' => $partner->division])],
            'summary' => $book->summary($partner),
            'cards' => $book->incomeCards($partner),
            'table' => $book->table($request, $partner),
        ]);
    }

    public function work(Request $request): Response
    {
        abort_unless(TreasuryAccess::mayOpenFunds($request->user()), 403);

        return Inertia::render('TreasuryWork', [...$this->shared($request), ...$this->work->for($request->user(), $this->portal($request), $request)]);
    }

    /** @return array{section: string, tabs: array{waiting: int}} */
    private function shared(Request $request): array
    {
        return ['section' => 'treasury', 'tabs' => ['waiting' => $this->work->waitingCount($request->user(), $this->portal($request))]];
    }

    private function showsOwnerFunds(Request $request): bool
    {
        return TreasuryAccess::showsOwnerFunds($request->user(), $this->portal($request));
    }

    private function portal(Request $request): string
    {
        return (string) PortalHost::current($request);
    }
}
