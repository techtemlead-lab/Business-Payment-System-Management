<?php

namespace App\Http\Controllers;

use App\Domain\Finance\FundCatalog;
use App\Domain\Finance\TreasuryAccess;
use App\Models\User;
use App\Support\PortalHost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Old money page addresses. The pages are gone, but bookmarks, notices and links in old messages
 * still arrive here, so each one moves to the new page this person can really open.
 */
class OldMoneyAddressController extends Controller
{
    /** The old Treasury page. */
    public function treasury(Request $request): RedirectResponse
    {
        return $this->moveTo($request, $this->officeOrMyMoney($request, $this->treasuryHome($request->user())));
    }

    /** The old spending page, which also held approvals and expense heads. */
    public function companyFunds(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($this->isPartnerDesk($user) || ! TreasuryAccess::mayOpenFunds($user)) {
            return $this->moveTo($request, $this->officeOrMyMoney($request, $this->treasuryHome($user)));
        }
        $tab = $request->query('tab');
        if ($tab === 'approvals' || $tab === 'heads') {
            return $this->moveTo($request, '/treasury/work', drop: ['fund']);
        }
        $fund = $request->query('fund');
        if (is_string($fund) && in_array($fund, FundCatalog::visibleKeys(TreasuryAccess::showsOwnerFunds($user, $this->portal($request))), true)) {
            return $this->moveTo($request, '/treasury/funds/'.$fund, drop: ['fund', 'tab']);
        }

        return $this->moveTo($request, '/treasury/funds', drop: ['fund', 'tab']);
    }

    public function benefitsFund(Request $request): RedirectResponse
    {
        $target = TreasuryAccess::showsOwnerFunds($request->user(), $this->portal($request))
            ? '/treasury/funds/benefits'
            : $this->treasuryHome($request->user());

        return $this->moveTo($request, $this->officeOrMyMoney($request, $target));
    }

    public function ledger(Request $request): RedirectResponse
    {
        $target = TreasuryAccess::mayOpenEvents($request->user()) ? '/treasury/events' : $this->treasuryHome($request->user());

        return $this->moveTo($request, $this->officeOrMyMoney($request, $target));
    }

    public function rewards(Request $request): RedirectResponse
    {
        $target = TreasuryAccess::mayOpenFunds($request->user()) ? '/treasury/funds/car_bike' : $this->treasuryHome($request->user());

        return $this->moveTo($request, $this->officeOrMyMoney($request, $target));
    }

    /** Office staff see the salary fund; a partner sees their own salary lines. */
    public function salary(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($this->isPartnerDesk($user)) {
            return $this->moveTo($request, '/my-money?type=salary');
        }

        return $this->moveTo($request, TreasuryAccess::mayOpenFunds($user) ? '/treasury/funds/salary_pool' : $this->treasuryHome($user));
    }

    /** The partner money pages: earnings, full report, statement and receipts. */
    public function myMoney(Request $request): RedirectResponse
    {
        return $this->moveTo($request, $this->isPartner($request->user()) ? '/my-money' : $this->treasuryHome($request->user()));
    }

    /** One address, two desks: a partner's own withdrawals, or the office's withdrawal requests. */
    public function withdrawals(Request $request): RedirectResponse
    {
        $user = $request->user();

        return $this->moveTo($request, $this->officeOrMyMoney($request, $user->allows('finance') ? '/payouts-hub' : $this->treasuryHome($user)));
    }

    public function partnerStatement(Request $request, User $partner): RedirectResponse
    {
        $user = $request->user();

        return $this->moveTo($request, $this->officeOrMyMoney($request, TreasuryAccess::mayOpenPartner($user) ? '/treasury/partners/'.$partner->id : $this->treasuryHome($user)));
    }

    /** The first Treasury place this person may open, or their home page when there is none. */
    private function treasuryHome(User $user): string
    {
        return match (true) {
            TreasuryAccess::mayOpenFunds($user) => '/treasury/funds',
            TreasuryAccess::mayOpenOrders($user) => '/treasury/orders',
            default => '/',
        };
    }

    /** Partners have no office pages, so an office address sends them to their own money page. */
    private function officeOrMyMoney(Request $request, string $officeTarget): string
    {
        return $this->isPartnerDesk($request->user()) ? '/my-money' : $officeTarget;
    }

    private function isPartner(User $user): bool
    {
        return in_array($user->role, ['dealer', 'sub_dealer'], true);
    }

    /** A dealer working in a linked office role is at the office desk, not their own. */
    private function isPartnerDesk(User $user): bool
    {
        return $this->isPartner($user) && ! $user->usingStaffWorkspace();
    }

    private function portal(Request $request): string
    {
        return (string) PortalHost::current($request);
    }

    /**
     * Sends the person on with the filters from the old address, so a saved view still works.
     *
     * @param  list<string>  $drop  Query keys that belonged to the old page only.
     */
    private function moveTo(Request $request, string $target, array $drop = []): RedirectResponse
    {
        [$path, $fixed] = array_pad(explode('?', $target, 2), 2, '');
        parse_str($fixed, $query);
        $all = [...array_diff_key($request->query(), array_flip($drop)), ...$query];

        return redirect($path.($all === [] ? '' : '?'.http_build_query($all)), 301);
    }
}
