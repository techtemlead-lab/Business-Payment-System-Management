<?php

namespace App\Domain\Orders;

use App\Domain\Audience\SiteVisitReport;
use App\Domain\Finance\EventLog;
use App\Domain\Finance\TreasuryAccess;
use App\Domain\Finance\TreasuryFunds;
use App\Domain\Staff\LeadershipAccess;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CR-18: the bordered summary sections of the office dashboard. Every section reads figures the
 * Treasury, the visitor analysis, the order lists or the event log already produce; nothing here
 * adds a new way of counting money. A section is offered only to people who may open the page it
 * summarises, and its numbers load after the dashboard shows.
 */
final class OfficeDashboardSections
{
    public const Sections = ['money', 'site', 'business', 'events', 'activity'];

    /** The partner activity table is built by CR-17; until it exists the section stays away. */
    public const ActivityTable = 'partner_activity';

    /** "Online now" means seen in the last few minutes, the same window the visitor count uses. */
    public const OnlineMinutes = 5;

    private const Most = 5;

    /**
     * @return list<string>
     */
    public static function visibleTo(User $viewer, string $portal): array
    {
        return array_values(array_filter(self::Sections, fn (string $section): bool => self::mayOpen($viewer, $portal, $section)));
    }

    public static function mayOpen(User $viewer, string $portal, string $section): bool
    {
        return match ($section) {
            'money' => TreasuryAccess::mayOpenFunds($viewer) || TreasuryAccess::mayOpenOrders($viewer),
            'site' => self::ownerOnly($viewer, $portal),
            'business' => $viewer->allows('orders') || $viewer->allows('finance'),
            'events' => TreasuryAccess::mayOpenEvents($viewer),
            'activity' => self::ownerOnly($viewer, $portal) && Schema::hasTable(self::ActivityTable),
            default => false,
        };
    }

    /** The visitor page's own rule: the owner panel, with the owner-tools right. */
    private static function ownerOnly(User $viewer, string $portal): bool
    {
        return $portal === 'sadmin' && LeadershipAccess::granted($viewer, 'owner_tools');
    }

    /**
     * @param  array<string, mixed>  $adminHome  The home figures already built for this viewer (snapshot, month, work queue).
     * @param  Builder<Order>  $orders  Orders this viewer may see, already narrowed to the picked branch.
     * @return array<string, array<string, mixed>>
     */
    public function for(User $viewer, string $portal, array $adminHome, Builder $orders): array
    {
        $data = [];
        foreach (self::visibleTo($viewer, $portal) as $section) {
            $data[$section] = match ($section) {
                'money' => $this->money($viewer, $portal, $adminHome),
                'site' => $this->site(),
                'business' => $this->business($orders),
                'events' => ['rows' => app(EventLog::class)->latest($viewer, $portal)],
                default => $this->activity(),
            };
        }

        return $data;
    }

    /**
     * The Treasury's own fund cards and total, the delivered-goods sales the home screen already
     * counts, and what waits for a check.
     *
     * @param  array<string, mixed>  $adminHome
     * @return array<string, mixed>
     */
    private function money(User $viewer, string $portal, array $adminHome): array
    {
        $money = ['funds' => null, 'sales' => null, 'waiting' => null];
        if (TreasuryAccess::mayOpenFunds($viewer)) {
            $cards = app(TreasuryFunds::class)->cards(TreasuryAccess::showsOwnerFunds($viewer, $portal));
            $total = 0;
            foreach ($cards as $card) {
                $total += self::signedCents((string) $card['balance']);
            }
            $money['funds'] = [
                'total' => self::decimal($total),
                'main' => array_map(fn (array $card): array => ['key' => $card['key'], 'name' => $card['name'], 'balance' => $card['balance']], array_slice($cards, 0, 4)),
            ];
        }
        if (TreasuryAccess::mayOpenOrders($viewer) && is_array($adminHome['month'] ?? null)) {
            $money['sales'] = [
                'to_date' => (string) ($adminHome['snapshot']['purchases'] ?? '0.00'),
                'month' => (string) $adminHome['month']['sales'],
                'today' => (string) $adminHome['month']['delivered_today'],
            ];
        }
        if ($viewer->allows('finance')) {
            $queue = collect($adminHome['work_queue'] ?? [])->keyBy('key');
            $money['waiting'] = [
                'payments' => (int) ($queue['payments_to_check']['count'] ?? 0),
                'withdrawals' => (int) ($queue['withdrawals_to_review']['count'] ?? 0),
            ];
        }

        return $money;
    }

    /**
     * Today's visitors, who is on the site now, and this week's daily trend and busiest pages,
     * all from the visitor analysis page's own report.
     *
     * @return array<string, mixed>
     */
    private function site(): array
    {
        $report = app(SiteVisitReport::class)->for('week', null, null);
        $trend = $report['trend'];
        $today = end($trend);

        return [
            'active_now' => $report['active_now'],
            'today_visitors' => $today === false ? 0 : $today['visitors'],
            'week_visitors' => $report['summary']['visitors'],
            'week_views' => $report['summary']['views'],
            'trend' => $trend,
            'pages' => array_slice($report['pages'], 0, self::Most),
        ];
    }

    /**
     * Orders placed today and this month, the areas they came from, and the products ordered most.
     * Counts follow the order lists: every order placed, in the viewer's branch.
     *
     * @param  Builder<Order>  $orders
     * @return array<string, mixed>
     */
    private function business(Builder $orders): array
    {
        $now = CarbonImmutable::now((string) config('madina.business_timezone', config('app.timezone')));
        $startOfDay = $now->startOfDay()->utc();
        $startOfMonth = $now->startOfMonth()->utc();
        $placed = fn (CarbonImmutable $since) => (clone $orders)->toBase()->reorder()->where('orders.created_at', '>=', $since);

        $todayRow = $placed($startOfDay)->selectRaw('COUNT(*) AS orders, COALESCE(SUM(orders.total), 0) AS amount')->first();
        $areas = function (string $column, CarbonImmutable $since) use ($placed): array {
            return $placed($since)->join('users as buyer', 'buyer.id', '=', 'orders.user_id')
                ->whereNotNull("buyer.{$column}")->where("buyer.{$column}", '<>', '')
                ->selectRaw("buyer.{$column} AS name, COUNT(*) AS orders")->groupBy("buyer.{$column}")
                ->orderByDesc('orders')->orderBy('name')->limit(self::Most)->get()
                ->map(fn (object $row): array => ['name' => (string) $row->name, 'orders' => (int) $row->orders])->all();
        };
        $products = DB::table('order_lines as line')
            ->whereIn('line.order_id', (clone $orders)->where('orders.created_at', '>=', $startOfMonth)->select('orders.id'))
            ->where('line.is_free', false)
            ->selectRaw('line.product_id, MAX(line.name) AS name, SUM(line.quantity) AS units')->groupBy('line.product_id')
            ->orderByDesc('units')->orderBy('name')->limit(self::Most)->get()
            ->map(fn (object $row): array => ['name' => (string) $row->name, 'units' => (int) $row->units])->all();

        return [
            'today' => ['orders' => (int) $todayRow->orders, 'amount' => Money::decimal(Money::cents((string) $todayRow->amount))],
            'districts' => ['today' => $areas('district', $startOfDay), 'month' => $areas('district', $startOfMonth)],
            'divisions' => ['today' => $areas('division', $startOfDay), 'month' => $areas('division', $startOfMonth)],
            'products' => $products,
        ];
    }

    /**
     * Dealers and sub-dealers seen in the last few minutes and today, read from CR-17's table.
     *
     * @return array{online: int, today: int}
     */
    private function activity(): array
    {
        $startOfDay = CarbonImmutable::now((string) config('madina.business_timezone', config('app.timezone')))->startOfDay()->utc();
        $row = DB::table(self::ActivityTable.' as a')->join('users as u', 'u.id', '=', 'a.user_id')
            ->whereIn('u.role', ['dealer', 'sub_dealer'])
            ->selectRaw('COUNT(*) FILTER (WHERE a.last_seen_at >= ?) AS online, COUNT(*) FILTER (WHERE a.last_seen_at >= ?) AS today', [now()->subMinutes(self::OnlineMinutes), $startOfDay])
            ->first();

        return ['online' => (int) ($row->online ?? 0), 'today' => (int) ($row->today ?? 0)];
    }

    private static function signedCents(string $value): int
    {
        return str_starts_with($value, '-') ? -Money::cents(substr($value, 1)) : Money::cents($value);
    }

    private static function decimal(int $cents): string
    {
        return $cents < 0 ? '-'.Money::decimal(-$cents) : Money::decimal($cents);
    }
}
