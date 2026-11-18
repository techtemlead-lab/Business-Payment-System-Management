<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\PartnerIncomeBook;
use App\Domain\Accounts\PartnerMoneyBook;
use App\Domain\Finance\CompanyFunds;
use App\Domain\Finance\EventLog;
use App\Domain\Finance\OrderMoneyReport;
use App\Domain\Finance\PartnerMoneyList;
use App\Domain\Finance\ProfitAndLoss;
use App\Domain\Finance\ReportData;
use App\Domain\Finance\RewardLeaderList;
use App\Domain\Finance\TreasuryAccess;
use App\Domain\Finance\TreasuryFunds;
use App\Domain\Finance\TreasuryWork;
use App\Domain\Inventory\BranchOperations;
use App\Domain\Payouts\PayoutReceipts;
use App\Models\Order;
use App\Models\User;
use App\Support\OfficeHubs;
use App\Support\PortalHost;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
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

    public function fund(Request $request, string $fund, TreasuryFunds $funds, RewardLeaderList $leaders): Response
    {
        abort_unless(TreasuryAccess::mayOpenFunds($request->user()), 403);
        $card = $funds->card($fund, $this->showsOwnerFunds($request));
        $leaderFigures = $fund === RewardLeaderList::Fund ? $leaders->summary() : null;
        $view = $this->fundView($request, $leaderFigures !== null);

        return Inertia::render('TreasuryFund', [
            ...$this->shared($request),
            'card' => $card,
            'view' => $view,
            'table' => match ($view) {
                'in' => $funds->moneyIn($request, $fund),
                'out' => $funds->moneyOut($request, $fund),
                default => $leaders->table($request, $view, $leaderFigures['targets'][$view]),
            },
            'leaders' => $leaderFigures,
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

    public function partner(Request $request, User $partner, PartnerMoneyBook $book, PartnerIncomeBook $income): Response
    {
        abort_unless(TreasuryAccess::mayOpenPartner($request->user()), 403);
        abort_unless(in_array($partner->role, ['dealer', 'sub_dealer'], true), 404);

        $incomeView = $request->query('view') === 'income';

        return Inertia::render('TreasuryPartner', [
            ...$this->shared($request),
            'partner' => ['id' => $partner->id, 'name' => $partner->name, 'code' => $partner->dealer_code, 'role' => $partner->role, 'area' => OrderMoneyReport::areaOf((object) ['profile' => $partner->getAttribute('profile'), 'district' => $partner->district, 'division' => $partner->division])],
            'summary' => $book->summary($partner),
            'cards' => $book->incomeCards($partner),
            'view' => $incomeView ? 'income' : 'money',
            'table' => $incomeView ? null : $book->table($request, $partner),
            'income' => $incomeView ? $income->page($request, $partner) : null,
        ]);
    }

    public function work(Request $request): Response
    {
        abort_unless(TreasuryAccess::mayOpenFunds($request->user()), 403);

        return Inertia::render('TreasuryWork', [...$this->shared($request), ...$this->work->for($request->user(), $this->portal($request), $request)]);
    }

    /** The owner's profit and loss for a month (this month by default) or any from-to range. */
    public function profit(Request $request, ProfitAndLoss $report): Response
    {
        abort_unless(TreasuryAccess::mayOpenProfit($request->user(), $this->portal($request)), 403);
        $asked = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
        ]);
        [$from, $to] = $this->profitPeriod($asked);

        return Inertia::render('TreasuryProfit', [
            ...$this->shared($request),
            'report' => $report->build($from, $to),
            'month' => isset($asked['from']) ? null : substr($from, 0, 7),
            'copy' => Lang::get('profit_loss'),
        ]);
    }

    /**
     * Dates asked for, else the whole month asked for, else this month so far. A range is at most
     * a year, so one page never reads the whole history at once.
     *
     * @param  array{month?: ?string, from?: ?string, to?: ?string}  $asked
     * @return array{0: string, 1: string}
     */
    private function profitPeriod(array $asked): array
    {
        if (isset($asked['from'], $asked['to'])) {
            $from = CarbonImmutable::parse($asked['from']);
            if ($from->addYear()->lessThanOrEqualTo(CarbonImmutable::parse($asked['to']))) {
                throw ValidationException::withMessages(['to' => __('profit_loss.err_range_too_long')]);
            }

            return [$asked['from'], $asked['to']];
        }
        if (isset($asked['month'])) {
            $month = CarbonImmutable::parse($asked['month'].'-01');

            return [$month->toDateString(), $month->endOfMonth()->toDateString()];
        }
        $default = CompanyFunds::defaultPeriod();

        return [$default['from'], $default['to']];
    }

    /** The view asked for in the address, when this fund has it; otherwise the money that came in. */
    private function fundView(Request $request, bool $hasLeaders): string
    {
        $asked = $request->query('view');

        return is_string($asked) && in_array($asked, ['out', ...($hasLeaders ? RewardLeaderList::Awards : [])], true) ? $asked : 'in';
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
