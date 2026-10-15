<?php

namespace App\Http\Controllers;

use App\Domain\Pricing\ProductPricing;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use App\Support\SiteCustomization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PortalController extends Controller
{
    private const STAFF = ['accounts' => 'accounts', 'inventory' => 'inventory', 'payments' => 'finance', 'policies' => 'finance', 'salary' => 'finance', 'withdrawals' => 'finance', 'ledger' => 'finance', 'reports' => 'reports', 'content' => 'content', 'customization' => 'content', 'enquiries' => 'content', 'audit' => 'audit'];

    public function index(Request $r, ProductPricing $pricing, string $section = 'overview')
    {
        $u = $r->user();
        $staff = $u->staff();
        $allowed = ['overview', 'orders', 'catalog', 'deliveries', 'dues', 'profile', 'security', 'notifications'];
        if (! $staff) {
            $allowed[] = 'earnings';
        }
        if ($u->role === 'dealer') {
            $allowed = [...$allowed, 'network', 'ranks'];
        }
        if ($staff) {
            $allowed = [...$allowed, ...array_keys(self::STAFF)];
        }
        if ($u->role === 'sub_dealer') {
            $allowed[] = 'salary';
        }
        if (! $staff) {
            $allowed[] = 'withdrawals';
        }
        abort_unless(in_array($section, $allowed, true), 404);
        if ($staff && isset(self::STAFF[$section])) {
            abort_unless($u->allows(self::STAFF[$section]), 403);
        }
        if ($staff && in_array($section, ['orders', 'deliveries', 'dues'], true)) {
            abort_unless($u->allows('orders') || $u->allows('finance'), 403);
        }
        $r->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|string|max:40', 'page' => 'nullable|integer|min:1']);
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
                $q->whereIn('status', ['approved', 'partially_dispatched', 'dispatched', 'delivered'])->whereColumn('paid', '<', 'total');
            }
            $data['orders'] = $q->with(['user:id,name,dealer_code', 'lines'])->latest()->paginate(12)->withQueryString();
        }
        if ($section === 'overview') {
            $approved = (clone $orders)->whereIn('status', ['approved', 'partially_dispatched', 'dispatched', 'delivered']);
            $total = (string) (clone $approved)->sum('total');
            $paid = (string) (clone $approved)->sum('paid');
            $data['stats'] = ['order_count' => (clone $orders)->count(), 'purchases' => Money::decimal(Money::cents($total)),
                'dues' => Money::decimal(Money::cents($total) - Money::cents($paid)),
                'pending' => (clone $orders)->whereIn('status', ['submitted', 'held'])->count(),
                'supply' => DB::table('order_lines')->whereIn('order_id', (clone $approved)->select('id'))->sum(DB::raw('quantity-dispatched')),
                'accounts' => $u->allows('accounts') ? User::whereIn('role', ['dealer', 'sub_dealer'])->count() : null];
            $data['lowStock'] = $u->allows('inventory') ? Product::where('active', true)->whereColumn('stock', '<=', 'low_stock_at')->limit(5)->get(['id', 'name', 'stock', 'sku']) : [];
        }
        if (in_array($section, ['overview', 'catalog', 'inventory'])) {
            $products = Product::query()->when(! $staff, fn ($q) => $q->where('active', true))->when($r->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$r->string('q').'%'))->orderBy('name')->get();
            $data['products'] = $products->map(function ($p) use ($u, $pricing) {
                $price = $p->dealer_price;
                if (! $u->staff()) {
                    try {
                        $price = Money::decimal($pricing->unitPrice($p, $u));
                    } catch (ValidationException) {
                        $price = null;
                    }
                }

                return [...$p->only(['id', 'sku', 'name', 'category', 'description', 'unit', 'mrp', 'stock', 'low_stock_at', 'active', 'tone']), 'price' => $price, ...$p->media(), ...($u->allows('cost_read') ? ['cost_price' => $p->cost_price] : [])];
            });
        }
        if ($section === 'inventory') {
            $data['movements'] = DB::table('stock_movements')->join('products', 'products.id', '=', 'product_id')->select('stock_movements.*', 'products.name')->orderByDesc('stock_movements.id')->paginate(20);
        }
        if ($section === 'accounts') {
            $data['accounts'] = User::when($r->filled('q'), function ($q) use ($r) {
                $term = '%'.$r->string('q').'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)
                        ->orWhere('dealer_code', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })->when($u->role !== 'super_admin', fn ($q) => $q->whereIn('role', ['dealer', 'sub_dealer']))->orderByDesc('id')->paginate(20, ['id', 'name', 'email', 'phone', 'role', 'status', 'dealer_code', 'division', 'district', 'directory_visible', 'profile', 'sponsor_id', 'created_at']);
            $data['sponsors'] = User::where('role', 'dealer')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'dealer_code', 'district']);
        }
        if ($section === 'payments') {
            $data['claims'] = DB::table('payment_claims')->join('orders', 'orders.id', '=', 'order_id')->join('users', 'users.id', '=', 'orders.user_id')->select('payment_claims.*', 'orders.number', 'users.name')->orderByDesc('payment_claims.id')->paginate(20);
        }
        if ($section === 'policies') {
            $data['policies'] = DB::table('policy_drafts')->orderByDesc('id')->get();
        }
        if ($section === 'profile') {
            $data['profile'] = [...$u->safe(), 'phone' => $u->phone, 'fields' => $u->profile ?? [], 'nid_status' => $u->nid ? 'On file — access restricted' : 'Not provided'];
        }
        if ($section === 'network') {
            $data['members'] = $u->role === 'sub_dealer' ? [] : User::where('sponsor_id', $u->id)->whereIn('role', ['dealer', 'sub_dealer'])->get(['name', 'dealer_code', 'created_at']);
        }
        if (in_array($section, ['withdrawals', 'earnings'])) {
            $data['wallet'] = DB::table('wallets')->where('user_id', $u->id)->first(['available', 'held']);
            $data['withdrawals'] = DB::table('withdrawals')->when(! $staff, fn ($q) => $q->where('user_id', $u->id))->latest()->paginate(20, ['id', 'amount', 'channel', 'status', 'reason', 'created_at']);
        }
        if ($section === 'ledger') {
            $data['entries'] = DB::table('journal_entries')->latest()->paginate(20);
        }
        if ($section === 'audit') {
            $data['events'] = DB::table('audit_events')->leftJoin('users', 'users.id', '=', 'actor_id')->select('audit_events.id', 'action', 'subject', 'audit_events.created_at', 'users.name')->orderByDesc('audit_events.id')->paginate(25);
        }
        if ($section === 'content') {
            $data['pages'] = DB::table('content_pages')->orderBy('slug')->get();
        }
        if ($section === 'customization') {
            $data['customization'] = SiteCustomization::all();
            $data['defaults'] = SiteCustomization::defaults();
        }
        if ($section === 'enquiries') {
            $data['enquiries'] = DB::table('enquiries')->latest()->paginate(20);
        }
        if ($section === 'notifications') {
            $data['notices'] = DB::table('account_notices')->where('user_id', $u->id)->latest()->paginate(20);
        }

        return Inertia::render('Portal', ['section' => $section, 'data' => $data, 'filters' => $r->only(['q', 'status'])]);
    }

    public function createAccount(Request $r)
    {
        $u = $r->user();
        abort_unless($u->allows('accounts'), 403);
        $roles = ['dealer', 'sub_dealer'];
        if ($u->role === 'super_admin') {
            $roles = [...$roles, ...array_keys(config('madina.roles'))];
        }
        $sponsors = User::where('role', 'dealer')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'dealer_code', 'district']);

        return Inertia::render('AccountCreate', [
            'roles' => $roles,
            'sponsors' => $sponsors,
        ]);
    }

    public function show(Request $r, Order $order)
    {
        $u = $r->user();
        abort_unless($order->user_id === $u->id || $u->allows('orders') || $u->allows('finance'), 404);

        return Inertia::render('OrderDetail', ['order' => $order->load(['lines', 'user:id,name,dealer_code']),
            'outstanding' => Money::decimal(Money::cents($order->total) - Money::cents($order->paid)),
            'claims' => DB::table('payment_claims')->where('order_id', $order->id)->get(['id', 'channel', 'reference', 'amount', 'status', 'reason', 'created_at']),
            'events' => DB::table('order_events')->where('order_id',$order->id)->orderBy('id')->get(['id', 'action', 'metadata', 'created_at']),
            'shipments' => DB::table('shipments')->where('order_id',$order->id)->orderBy('id')->get(['id', 'courier', 'tracking', 'eta', 'status', 'lines', 'delivered_at', 'created_at'])]);
    }
}
