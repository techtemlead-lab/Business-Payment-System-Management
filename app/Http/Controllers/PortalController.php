<?php
namespace App\Http\Controllers;
use App\Models\{User,Product,Order,CommissionRuleParameter,CommissionAllocation,AreaDealerAssignment,DealerRank,RewardMilestoneProgress,ReferralTreePath};
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PortalController extends Controller {
    private const STAFF=[
        'accounts'=>'accounts',
        'sub-dealers'=>'accounts',
        'sub-dealer-orders'=>'orders',
        'treasury'=>'finance',
        'inventory'=>'inventory',
        'payments'=>'finance',
        'policies'=>'finance',
        'salary'=>'finance',
        'withdrawals'=>'finance',
        'ledger'=>'finance',
        'reports'=>'reports',
        'content'=>'content',
        'customization'=>'content',
        'enquiries'=>'content',
        'audit'=>'audit',
        'commissions'=>'finance',
        'network-tree'=>'accounts',
        'area-dealers'=>'accounts',
        'ranks'=>'accounts',
        'rewards'=>'finance',
        'capital-flow'=>'finance',
        'settlements'=>'finance',
        'simulator'=>'finance',
        'payouts-hub'=>'finance',
    ];
    public function index(Request $r,string $section='overview') {
        $u=$r->user(); $staff=$u->staff();
        $allowed=['overview','orders','catalog','deliveries','dues','profile','security','notifications'];
        if (!$staff) $allowed[]='earnings';
        if ($u->role==='dealer') $allowed=[...$allowed,'network','ranks'];
        if ($staff) $allowed=[...$allowed,...array_keys(self::STAFF)];
        if ($u->role==='sub_dealer') $allowed[]='salary';
        if (!$staff) $allowed[]='withdrawals';
        abort_unless(in_array($section,$allowed,true),404);
        if ($staff && isset(self::STAFF[$section])) abort_unless($u->allows(self::STAFF[$section]),403);
        if ($staff && in_array($section,['orders','deliveries','dues'],true)) abort_unless($u->allows('orders')||$u->allows('finance'),403);
        $r->validate(['q'=>'nullable|string|max:100','status'=>'nullable|string|max:40','page'=>'nullable|integer|min:1']);
        $orders=Order::query()->when(!$staff,fn($q)=>$q->where('user_id',$u->id))->when($staff&&!$u->allows('orders')&&!$u->allows('finance'),fn($q)=>$q->whereRaw('1=0'));
        $data=[];
        if (in_array($section,['overview','orders','deliveries','dues','reports'])) {
            $q=clone $orders;
            if ($r->filled('q')) $q->where('number','like','%'.$r->string('q').'%');
            if ($r->filled('status')) $q->where('status',$r->string('status'));
            if ($section==='deliveries') $q->whereIn('status',['approved','partially_dispatched','dispatched'])->whereHas('lines',fn($lines)=>$lines->whereColumn('dispatched','<','quantity'));
            if ($section==='dues') $q->whereIn('status',['approved','partially_dispatched','dispatched','delivered'])->whereColumn('paid','<','total');
            $data['orders']=$q->with(['user:id,name,dealer_code','lines'])->latest()->paginate(12)->withQueryString();
        }
        if ($section==='overview') {
            $approved=(clone $orders)->whereIn('status',['approved','partially_dispatched','dispatched','delivered']);
            $total=(string)(clone $approved)->sum('total'); $paid=(string)(clone $approved)->sum('paid');
            $data['stats']=['order_count'=>(clone $orders)->count(),'purchases'=>Money::decimal(Money::cents($total)),
                'dues'=>Money::decimal(Money::cents($total)-Money::cents($paid)),
                'pending'=>(clone $orders)->whereIn('status',['submitted','held'])->count(),
                'supply'=>DB::table('order_lines')->whereIn('order_id',(clone $approved)->select('id'))->sum(DB::raw('quantity-dispatched')),
                'accounts'=>$u->allows('accounts')?User::whereIn('role',['dealer','sub_dealer'])->count():null];
            $data['lowStock']=$u->allows('inventory')?Product::where('active',true)->whereColumn('stock','<=','low_stock_at')->limit(5)->get(['id','name','stock','sku']):[];
        }
        if (in_array($section,['overview','catalog','inventory'])) {
            $products=Product::query()->when(!$staff,fn($q)=>$q->where('active',true))->when($r->filled('q'),fn($q)=>$q->where('name','like','%'.$r->string('q').'%'))->orderBy('name')->get();
            $data['products']=$products->map(function($p)use($u){
                $price=$p->dealer_price;
                if($u->role==='sub_dealer') { $minor=Money::cents($p->mrp)*60; $price=$minor%100===0?Money::decimal(intdiv($minor,100)):null; }
                return [...$p->only(['id','sku','name','category','description','unit','mrp','stock','low_stock_at','active','featured','tone']),'price'=>$price,...$p->media(),...($u->allows('cost_read')?['cost_price'=>$p->cost_price]:[])];
            });
            if ($section === 'catalog') {
                $data['categories'] = \App\Support\SiteCustomization::getConfiguredCategoryNames();
                if ($staff) {
                    $data['customization'] = \App\Support\SiteCustomization::raw();
                    $data['defaults'] = \App\Support\SiteCustomization::defaults();
                    $data['all_catalog_products'] = Product::where('active', true)->select('id','name','sku','mrp','dealer_price','category','featured','stock')->orderBy('name')->get()->map(fn($p) => [
                        'id' => $p->id,
                        'name' => $p->name,
                        'sku' => $p->sku,
                        'mrp' => $p->mrp,
                        'dealer_price' => $p->dealer_price,
                        'category' => $p->category,
                        'featured' => (bool)$p->featured,
                        'stock' => (int)$p->stock,
                        'image' => $p->media()['image_url'] ?? '',
                    ]);
                }
            }
        }
        if ($section==='inventory') $data['movements']=DB::table('stock_movements')->join('products','products.id','=','product_id')->select('stock_movements.*','products.name')->orderByDesc('stock_movements.id')->paginate(20);
        if ($section==='accounts') {
            $data['accounts']=User::when($r->filled('q'),function($q)use($r){
                $term = '%'.$r->string('q').'%';
                $q->where(function($sub)use($term){
                    $sub->where('name','like',$term)
                        ->orWhere('dealer_code','like',$term)
                        ->orWhere('email','like',$term)
                        ->orWhere('phone','like',$term);
                });
            })->when($u->role!=='super_admin',fn($q)=>$q->whereIn('role',['dealer','sub_dealer']))->orderByDesc('id')->paginate(20,['id','name','email','phone','role','status','dealer_code','division','district','directory_visible','profile','sponsor_id','created_at']);
            $data['sponsors']=User::where('role','dealer')->where('status','active')->orderBy('name')->get(['id','name','dealer_code','district']);
        }
        if ($section==='sub-dealers') {
            $data['accounts']=User::where('role','sub_dealer')->when($r->filled('q'),function($q)use($r){
                $term = '%'.$r->string('q').'%';
                $q->where(function($sub)use($term){
                    $sub->where('name','like',$term)
                        ->orWhere('dealer_code','like',$term)
                        ->orWhere('email','like',$term)
                        ->orWhere('phone','like',$term);
                });
            })->orderByDesc('id')->paginate(20,['id','name','email','phone','role','status','dealer_code','division','district','directory_visible','profile','sponsor_id','created_at']);
            $data['sponsors']=User::where('role','dealer')->where('status','active')->orderBy('name')->get(['id','name','dealer_code','district']);
            $data['subdealer_stats']=[
                'total'=>User::where('role','sub_dealer')->count(),
                'active'=>User::where('role','sub_dealer')->where('status','active')->count(),
                'orders_count'=>Order::whereHas('user',fn($q)=>$q->where('role','sub_dealer'))->count(),
                'orders_volume'=>(float)Order::whereHas('user',fn($q)=>$q->where('role','sub_dealer'))->whereIn('status',['submitted','approved','dispatched','partially_dispatched','delivered'])->sum('total'),
            ];
        }
        if ($section==='sub-dealer-orders') {
            $q=Order::query()->whereHas('user',fn($qu)=>$qu->where('role','sub_dealer'));
            if ($r->filled('q')) $q->where('number','like','%'.$r->string('q').'%');
            if ($r->filled('status')) $q->where('status',$r->string('status'));
            $data['orders']=$q->with(['user:id,name,dealer_code','lines'])->latest()->paginate(12)->withQueryString();
        }
        if ($section==='payments') $data['claims']=DB::table('payment_claims')->join('orders','orders.id','=','order_id')->join('users','users.id','=','orders.user_id')->select('payment_claims.*','orders.number','users.name')->orderByDesc('payment_claims.id')->paginate(20);
        if ($section==='policies') $data['policies']=DB::table('policy_drafts')->orderByDesc('id')->get();
        if ($section==='profile') $data['profile']=[...$u->safe(),'phone'=>$u->phone,'fields'=>$u->profile??[],'nid_status'=>$u->nid?'On file — access restricted':'Not provided'];
        if ($section==='network') $data['members']=$u->role==='sub_dealer'?[]:User::where('sponsor_id',$u->id)->whereIn('role',['dealer','sub_dealer'])->get(['name','dealer_code','created_at']);
        if (in_array($section,['withdrawals','earnings'])) {
            $data['wallet']=DB::table('wallets')->where('user_id',$u->id)->first(['available','held']);
            $data['withdrawals']=DB::table('withdrawals')->when(!$staff,fn($q)=>$q->where('user_id',$u->id))->latest()->paginate(20,['id','amount','channel','status','reason','created_at']);
        }
        if ($section==='ledger') $data['entries']=DB::table('journal_entries')->latest()->paginate(20);
        if ($section==='audit') $data['events']=DB::table('audit_events')->leftJoin('users','users.id','=','actor_id')->select('audit_events.id','action','subject','audit_events.created_at','users.name')->orderByDesc('audit_events.id')->paginate(25);
        if ($section==='content') $data['pages']=DB::table('content_pages')->orderBy('slug')->get();
        if ($section==='customization') {
            $data['customization']=\App\Support\SiteCustomization::raw();
            $data['defaults']=\App\Support\SiteCustomization::defaults();
            $data['products']=\App\Models\Product::where('active', true)->select('id','name','sku','mrp','dealer_price','category','featured','stock')->orderBy('name')->get()->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'mrp' => $p->mrp,
                'dealer_price' => $p->dealer_price,
                'category' => $p->category,
                'featured' => (bool)$p->featured,
                'stock' => (int)$p->stock,
                'image' => $p->media()['image_url'] ?? '',
            ]);
        }
        if ($section==='enquiries') $data['enquiries']=DB::table('enquiries')->latest()->paginate(20);
        if ($section==='notifications') $data['notices']=DB::table('account_notices')->where('user_id',$u->id)->latest()->paginate(20);
        if ($section === 'commissions') {
            $data['rules'] = CommissionRuleParameter::orderBy('id')->get();
            $data['allocations'] = CommissionAllocation::with(['order:id,number,total', 'user:id,name,dealer_code'])->latest()->paginate(20);
            $data['pools_summary'] = CommissionAllocation::selectRaw('category, sum(amount) as total_amount, count(*) as count')->groupBy('category')->get();
        }
        if ($section === 'network-tree' || ($staff && $section === 'network')) {
            $data['dealers'] = User::whereIn('role', ['dealer', 'sub_dealer'])
                ->with(['rank', 'sponsor:id,name,dealer_code'])
                ->get(['id', 'name', 'dealer_code', 'phone', 'district', 'division', 'sponsor_id', 'role', 'status', 'created_at'])
                ->map(fn($d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'dealer_code' => $d->dealer_code,
                    'phone' => $d->phone,
                    'district' => $d->district,
                    'division' => $d->division,
                    'sponsor_id' => $d->sponsor_id,
                    'sponsor_name' => $d->sponsor?->name,
                    'role' => $d->role,
                    'status' => $d->status,
                    'rank' => $d->rank?->current_rank ?? 'dealer',
                    'lifetime_purchases' => (float)($d->rank?->lifetime_purchase_total ?? 0),
                    'created_at' => $d->created_at?->toIso8601String(),
                ]);
        }
        if ($section === 'area-dealers') {
            $data['assignments'] = AreaDealerAssignment::with('user:id,name,dealer_code,phone,district,division')->orderBy('id')->get();
            $data['divisions'] = ['Dhaka', 'Chattogram', 'Rajshahi', 'Khulna', 'Barishal', 'Sylhet', 'Rangpur', 'Mymensingh'];
            $data['dealers_list'] = User::where('role', 'dealer')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'dealer_code', 'district', 'division']);
        }
        if ($section === 'ranks') {
            $data['ranks'] = DealerRank::with('user:id,name,dealer_code,phone,district,division,sponsor_id')
                ->orderByDesc('lifetime_purchase_total')
                ->get()
                ->map(fn($r) => [
                    'id' => $r->id,
                    'user_id' => $r->user_id,
                    'name' => $r->user?->name,
                    'dealer_code' => $r->user?->dealer_code,
                    'phone' => $r->user?->phone,
                    'district' => $r->user?->district,
                    'division' => $r->user?->division,
                    'current_rank' => $r->current_rank,
                    'achieved_at' => $r->achieved_at?->toIso8601String(),
                    'lifetime_purchase_total' => (float)$r->lifetime_purchase_total,
                    'monthly_purchase_total' => (float)$r->monthly_purchase_total,
                    'one_time_incentive_paid' => (bool)$r->one_time_incentive_paid,
                ]);
            $data['stats'] = [
                'mo_count' => DealerRank::where('current_rank', 'mo')->count(),
                'am_count' => DealerRank::where('current_rank', 'am')->count(),
                'sm_count' => DealerRank::where('current_rank', 'sm')->count(),
                'me_count' => DealerRank::where('current_rank', 'me')->count(),
                'dealer_count' => DealerRank::where('current_rank', 'dealer')->count(),
            ];
        }
        if ($section === 'rewards') {
            $data['bike_milestones'] = RewardMilestoneProgress::where('milestone_type', 'bike')->with('user:id,name,dealer_code,phone,district')->orderByDesc('qualifying_volume')->get();
            $data['car_milestones'] = RewardMilestoneProgress::where('milestone_type', 'car')->with('user:id,name,dealer_code,phone,district')->orderByDesc('qualifying_volume')->get();
            $data['rule_params'] = CommissionRuleParameter::getRule('car_bike_incentive')?->parameters ?? [];
        }
        if ($section === 'capital-flow' || $section === 'treasury') {
            $totalOrdersCash = (float) Order::whereIn('status', ['submitted', 'approved', 'dispatched', 'partially_dispatched', 'delivered'])->sum('total');
            $dealerOrdersCash = (float) Order::whereHas('user', fn($q)=>$q->where('role', 'dealer'))->whereIn('status', ['submitted', 'approved', 'dispatched', 'partially_dispatched', 'delivered'])->sum('total');
            $subDealerOrdersCash = (float) Order::whereHas('user', fn($q)=>$q->where('role', 'sub_dealer'))->whereIn('status', ['submitted', 'approved', 'dispatched', 'partially_dispatched', 'delivered'])->sum('total');

            $channelBreakdown = DB::table('payment_claims')
                ->where('status', 'verified')
                ->selectRaw('channel, sum(amount) as total_amount, count(*) as count')
                ->groupBy('channel')
                ->get();

            $companyShare60 = round($totalOrdersCash * 0.60, 2);
            $commissionPool40 = round($totalOrdersCash * 0.40, 2);
            $allocatedSoFar = (float) CommissionAllocation::sum('amount');
            $mgmtReserve = (float) CommissionAllocation::where('category', 'area_management')->sum('amount');
            $unassignedReserve = (float) CommissionAllocation::where('status', 'unassigned_reserve')->sum('amount');
            $settledDisbursements = (float) DB::table('withdrawals')->where('status', 'settled')->sum('amount');
            $maturingCommissions = (float) CommissionAllocation::where('status', 'maturing')->sum('amount');
            $marketingFund = (float) CommissionAllocation::where('category', 'company_marketing_fund')->sum('amount');
            $carBikeFund = (float) CommissionAllocation::where('category', 'car_bike_incentive_fund')->sum('amount');
            $tdsTaxWithheld = round($settledDisbursements * 0.05, 2);

            $data['flow'] = [
                'gross_cash_inflow' => $totalOrdersCash,
                'dealer_cash_inflow' => $dealerOrdersCash,
                'sub_dealer_cash_inflow' => $subDealerOrdersCash,
                'channel_breakdown' => $channelBreakdown,
                'company_share_60' => $companyShare60,
                'management_reserve_2' => $mgmtReserve,
                'unassigned_territory_reserve' => $unassignedReserve,
                'total_company_deposits' => round($companyShare60 + $mgmtReserve + $unassignedReserve, 2),
                'commission_pool_40' => $commissionPool40,
                'allocated_so_far' => $allocatedSoFar,
                'settled_disbursements' => $settledDisbursements,
                'maturing_commissions' => $maturingCommissions,
                'marketing_fund' => $marketingFund,
                'car_bike_fund' => $carBikeFund,
                'tds_tax_withheld' => $tdsTaxWithheld,
                'unallocated_pool_buffer' => max(0, round($commissionPool40 - $allocatedSoFar, 2)),
            ];
            $data['allocations_by_category'] = CommissionAllocation::selectRaw('category, sum(amount) as total_amount, count(*) as count')->groupBy('category')->get();
            $data['recent_allocations'] = CommissionAllocation::with('user:id,name,dealer_code')->latest()->limit(20)->get();
        }
        if ($section === 'settlements') {
            $currentMonth = now()->format('Y-m');
            $salaryPoolAllocations = CommissionAllocation::where('category', 'dealer_monthly_salary_pool')
                ->where('status', 'pending')
                ->get();
            $rankPoolAllocations = CommissionAllocation::where('category', 'rank_royalty_pool')
                ->where('status', 'pending')
                ->get();
            $maturingAllocations = CommissionAllocation::where('status', 'maturing')
                ->whereNotNull('user_id')
                ->get();

            $salaryRule = CommissionRuleParameter::getRule('dealer_monthly_salary');
            $minSalaryVolume = $salaryRule && isset($salaryRule->parameters['min_monthly_volume']) ? (float)$salaryRule->parameters['min_monthly_volume'] : 10000.00;

            $qualifiedSalaryDealers = DealerRank::where('monthly_purchase_total', '>=', $minSalaryVolume)
                ->whereHas('user', fn($q) => $q->where('status', 'active'))
                ->with('user:id,name,dealer_code,phone,district')
                ->get();

            $commissionService = app(\App\Domain\Commissions\CommissionService::class);
            $qualifiedRankLeaders = DealerRank::whereIn('current_rank', ['mo', 'am', 'sm', 'me'])
                ->whereHas('user', fn($q) => $q->where('status', 'active'))
                ->with('user:id,name,dealer_code,phone,district')
                ->get()
                ->map(function($r) use ($commissionService) {
                    $eval = $commissionService->evaluatePaidAsRank($r->user_id);
                    return [
                        'id' => $r->id,
                        'user_id' => $r->user_id,
                        'name' => $r->user?->name,
                        'dealer_code' => $r->user?->dealer_code,
                        'rank' => $r->current_rank,
                        'paid_as_rank' => $eval['paid_as_rank'],
                        'is_qualified' => $eval['is_qualified'],
                        'in_grace_period' => $eval['in_grace_period'],
                        'monthly_volume' => (float)$r->monthly_purchase_total,
                        'one_time_incentive_paid' => (bool)$r->one_time_incentive_paid,
                    ];
                });

            $settledHistory = DB::table('journal_entries')
                ->where('event_key', 'like', 'commissions.%')
                ->latest()
                ->limit(20)
                ->get();

            $data['settlement_state'] = [
                'current_month' => $currentMonth,
                'salary_pool_pending' => (float)$salaryPoolAllocations->sum('amount'),
                'salary_pool_allocations_count' => $salaryPoolAllocations->count(),
                'qualified_salary_dealers_count' => $qualifiedSalaryDealers->count(),
                'qualified_salary_dealers' => $qualifiedSalaryDealers->map(fn($d) => [
                    'id' => $d->id,
                    'user_id' => $d->user_id,
                    'name' => $d->user?->name,
                    'dealer_code' => $d->user?->dealer_code,
                    'monthly_volume' => (float)$d->monthly_purchase_total,
                ]),
                'rank_pool_pending' => (float)$rankPoolAllocations->sum('amount'),
                'rank_pool_allocations_count' => $rankPoolAllocations->count(),
                'qualified_rank_leaders_count' => $qualifiedRankLeaders->where('is_qualified', true)->count(),
                'qualified_rank_leaders' => $qualifiedRankLeaders,
                'maturing_count' => $maturingAllocations->count(),
                'maturing_amount' => (float)$maturingAllocations->sum('amount'),
                'settled_history' => $settledHistory,
            ];
        }
        if ($section === 'simulator') {
            $commissionService = app(\App\Domain\Commissions\CommissionService::class);
            $data['simulation'] = $commissionService->simulatePlan([]);
            $data['active_rules'] = CommissionRuleParameter::orderBy('id')->get();
        }
        if ($section === 'payouts-hub') {
            $data['withdrawals'] = DB::table('withdrawals')
                ->join('users', 'users.id', '=', 'withdrawals.user_id')
                ->select('withdrawals.*', 'users.name as user_name', 'users.dealer_code', 'users.phone')
                ->latest('withdrawals.created_at')
                ->paginate(20);
            $tdsRule = CommissionRuleParameter::getRule('payout_tax_withholding');
            $data['tds_rate'] = $tdsRule ? (float)$tdsRule->percentage : 5.00;
        }
        return Inertia::render('Portal',['section'=>$section,'data'=>$data,'filters'=>$r->only(['q','status'])]);
    }
    public function createAccount(Request $r) {
        $u=$r->user(); abort_unless($u->allows('accounts'),403);
        $roles=['dealer','sub_dealer'];
        if ($u->role==='super_admin') {
            $roles=[...$roles,...array_keys(config('madina.roles'))];
        }
        $sponsors=User::where('role','dealer')->where('status','active')->orderBy('name')->get(['id','name','dealer_code','district']);
        return Inertia::render('AccountCreate',[
            'roles'=>$roles,
            'sponsors'=>$sponsors,
        ]);
    }
    public function show(Request $r,Order $order) {
        $u=$r->user(); abort_unless($order->user_id===$u->id || $u->allows('orders') || $u->allows('finance'),404);
        return Inertia::render('OrderDetail',['order'=>$order->load(['lines','user:id,name,dealer_code']),
            'outstanding'=>Money::decimal(Money::cents($order->total)-Money::cents($order->paid)),
            'claims'=>DB::table('payment_claims')->where('order_id',$order->id)->get(['id','channel','reference','amount','status','reason','created_at']),
            'events'=>DB::table('order_events')->where('order_id',$order->id)->orderBy('id')->get(['id','action','metadata','created_at']),
            'shipments'=>DB::table('shipments')->where('order_id',$order->id)->orderBy('id')->get(['id','courier','tracking','eta','status','lines','delivered_at','created_at'])]);
    }
}
