<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Dealer;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\Purchase;
use App\Models\WithdrawalRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends WorkspaceController
{
    public function reports(Request $r): Response
    {
        $this->admin($r);

        return Inertia::render('Reports', ['counts' => ['dealers' => Dealer::count(), 'ledger' => LedgerEntry::count(), 'payouts' => Payout::count(), 'audit' => AuditEvent::count(), 'purchases' => Purchase::count(), 'withdrawals' => WithdrawalRequest::count()]]);
    }

    public function export(Request $r): StreamedResponse
    {
        $this->admin($r);
        $r->validate(['type' => 'required|in:dealers,ledger,payouts,audit,purchases,withdrawals']);
        AuditEvent::record('report.exported', ['type' => $r->type]);
        $type = $r->type;
        $model = match ($type) {
            'dealers' => Dealer::class,
            'ledger' => LedgerEntry::class,
            'payouts' => Payout::class,
            'audit' => AuditEvent::class,
            'purchases' => Purchase::class,
            'withdrawals' => WithdrawalRequest::class,
        };
        $fields = match ($type) {
            'dealers' => ['id', 'code', 'level', 'division', 'district', 'status', 'dealer_type'],
            'ledger' => ['id', 'dealer_id', 'amount_minor', 'currency', 'kind', 'business_reference', 'rule_version_id', 'created_at', 'earning_category'],
            'payouts' => ['id', 'dealer_id', 'amount_minor', 'status', 'provider_reference', 'created_at'],
            'audit' => ['id', 'actor_id', 'action', 'subject_id', 'created_at'],
            'purchases' => ['id', 'dealer_id', 'reference', 'product_name', 'quantity', 'unit_mrp_minor', 'total_mrp_minor', 'amount_paid_minor', 'status', 'rule_version_id', 'ledger_entry_id', 'purchased_on'],
            'withdrawals' => ['id', 'dealer_id', 'amount_minor', 'status', 'reviewed_by', 'reviewed_at', 'created_at'],
        };

        return response()->streamDownload(function () use ($model, $fields) {
            $f = fopen('php://output', 'w');
            fputcsv($f, $fields, ',', '"', '');
            foreach ($model::orderBy('id')->cursor() as $row) {
                $values = array_map(function ($field) use ($row) {
                    $v = (string) $row->{$field};

                    return preg_match('/^[=+@\-\t\r]/', $v) ? "'".$v : $v;
                }, $fields);
                fputcsv($f, $values, ',', '"', '');
            }
            fclose($f);
        }, 'nexora-'.$type.'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
