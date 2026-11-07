<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\PartnerAccountList;
use App\Support\Audit;
use App\Support\TableWorkbook;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Excel download of the dealer or sub-dealer list with the filters on screen.
 * Only staff who may manage accounts can download it, and it never holds an NID number.
 */
class AccountListExportController extends Controller
{
    public function __invoke(Request $request, PartnerAccountList $list): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($user->staff() || $user->usingStaffWorkspace(), 403);
        abort_unless($user->allows('accounts'), 403);
        $data = $request->validate([
            ...PartnerAccountList::rules(),
            'section' => 'required|in:accounts,sub-dealers',
        ]);
        $role = $data['section'] === 'sub-dealers' ? 'sub_dealer' : 'dealer';
        $filters = PartnerAccountList::filters($request);
        $label = fn (string $key): string => __('portal.'.$key);
        $columns = [
            ['key' => 'name', 'label' => $label('col_partner')],
            ['key' => 'name_bn', 'label' => $label('acct_export_col_name_bn')],
            ['key' => 'shop_name', 'label' => $label('acct_export_col_shop')],
            ['key' => 'phone', 'label' => $label('csv_col_phone')],
            ['key' => 'email', 'label' => $label('acct_export_col_email')],
            ['key' => 'dealer_code', 'label' => $label('col_dealer_code')],
            ['key' => 'sponsor', 'label' => $label('net_sponsor')],
            ['key' => 'place', 'label' => $label('col_place')],
            ['key' => 'role', 'label' => $label('col_type')],
            ['key' => 'district', 'label' => $label('csv_col_district')],
            ['key' => 'division', 'label' => $label('csv_col_division')],
            ['key' => 'joined', 'label' => $label('col_joined')],
            ['key' => 'status', 'label' => $label('col_status')],
            ...($role === 'dealer' ? [['key' => 'rank', 'label' => $label('acct_col_rank')]] : []),
            ['key' => 'last_order_at', 'label' => $label('acct_col_last_order')],
            ['key' => 'month_purchase', 'label' => $label('acct_col_month_purchase'), 'type' => 'money', 'total' => true],
        ];
        $applied = array_filter($filters, fn ($value): bool => $value !== '' && $value !== 0);
        Audit::record($user->id, 'report.exported', 'accounts', ['section' => $data['section'], ...$applied]);
        $title = $label($role === 'dealer' ? 'acct_export_title_dealers' : 'acct_export_title_sub_dealers');

        return TableWorkbook::download(
            'Madina-'.$data['section'].'-'.now('Asia/Dhaka')->format('Y-m-d').'.xlsx',
            $title,
            $columns,
            $list->exportRows($role, $filters),
            [[__('portal.acct_export_filters'), $this->describe($filters)]],
        );
    }

    /**
     * The chosen filters in words for the summary sheet.
     *
     * @param  array{q: string, status: string, division: string, district: string, rank: string, no_order_days: int}  $filters
     */
    private function describe(array $filters): string
    {
        $parts = [];
        if ($filters['q'] !== '') {
            $parts[] = __('portal.search').': '.$filters['q'];
        }
        if ($filters['status'] !== '') {
            $parts[] = __('portal.col_status').': '.__('portal.status_'.$filters['status']);
        }
        if ($filters['division'] !== '') {
            $parts[] = __('portal.csv_col_division').': '.PartnerAccountList::placeName($filters['division']);
        }
        if ($filters['district'] !== '') {
            $parts[] = __('portal.csv_col_district').': '.PartnerAccountList::placeName($filters['district']);
        }
        if ($filters['rank'] !== '') {
            $parts[] = __('portal.acct_col_rank').': '.__('portal.net_rank_'.$filters['rank']);
        }
        if ($filters['no_order_days'] > 0) {
            $parts[] = __('portal.acct_filter_no_order_days', ['days' => $filters['no_order_days']]);
        }

        return $parts === [] ? __('portal.acct_export_all') : implode(' · ', $parts);
    }
}
