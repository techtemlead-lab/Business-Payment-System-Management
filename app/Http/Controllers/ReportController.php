<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function export(Request $r)
    {
        $u = $r->user();
        abort_unless(! $u->staff() || $u->allows('reports') || $u->allows('orders') || $u->allows('finance'), 403);
        $d = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $q = Order::when(! $u->staff(), fn ($q) => $q->where('user_id', $u->id))->when(isset($d['from']), fn ($q) => $q->where('created_at', '>=', Carbon::parse($d['from'], 'Asia/Dhaka')->utc()))->when(isset($d['to']), fn ($q) => $q->where('created_at', '<', Carbon::parse($d['to'], 'Asia/Dhaka')->addDay()->utc()))->orderBy('created_at');
        Audit::record($u->id, 'report.exported', 'orders', $d);

        return response()->streamDownload(function () use ($q) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, ['Order', 'Date (Dhaka)', 'Status', 'Total BDT', 'Verified receipts BDT', 'Unpaid amount BDT'], ',', '"', '');
            foreach ($q->cursor() as $o) {
                fputcsv($f, [$o->number, $o->created_at->timezone('Asia/Dhaka')->format('Y-m-d H:i'), $o->status, $o->total, $o->paid, $o->outstanding()], ',', '"', '');
            } fclose($f);
        }, 'madina-orders-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'private, no-store']);
    }
}
