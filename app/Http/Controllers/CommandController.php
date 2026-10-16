<?php
namespace App\Http\Controllers;
use App\Models\{User,Product,Order,CommissionRuleParameter,AreaDealerAssignment};
use App\Domain\Orders\OrderService;
use App\Domain\Commissions\CommissionService;
use App\Support\{Audit,Money};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Password};
use Illuminate\Support\Str;
use Illuminate\Validation\{Rule,ValidationException};

class CommandController extends Controller {
    private function permit(Request $r,string $cap): void { abort_unless($r->user()->allows($cap),403); }
    public function order(Request $r,OrderService $service) {
        abort_unless(in_array($r->user()->role,['dealer','sub_dealer'],true),403);
        $data=$r->validate(['items'=>'required|array|min:1|max:100','items.*'=>'array:product_id,quantity','items.*.product_id'=>'required|integer|distinct|exists:products,id','items.*.quantity'=>'required|integer|min:1|max:10000',
            'idempotency_key'=>'required|uuid','note'=>'nullable|string|max:1000','reference'=>'nullable|string|max:100','channel'=>'nullable|required_with:reference|in:bKash,Nagad,Rocket,Bank','amount'=>'nullable|required_with:reference|regex:/^\d{1,9}(\.\d{1,2})?$/']);
        $o=$service->create($r->user(),$data); return redirect('/workspace/orders/'.$o->id)->with('success','Order submitted for review.');
    }
    public function decision(Request $r,Order $order,OrderService $s) {
        $this->permit($r,'order_review');
        $d=$r->validate(['status'=>'required|in:approved,held,rejected','reason'=>'required|string|max:1000','allow_credit'=>'sometimes|boolean']);
        $s->decision($r->user(),$order,$d['status'],$d['reason'],$r->boolean('allow_credit')); return back()->with('success','Order decision recorded.');
    }
    public function verify(Request $r,int $claim,OrderService $s) {
        $this->permit($r,'finance'); $d=$r->validate(['approve'=>'required|boolean','reason'=>'required|string|max:1000']);
        $s->verify($r->user(),$claim,$d['approve'],$d['reason']); return back()->with('success','Payment review recorded.');
    }
    public function dispatch(Request $r,Order $order,OrderService $s) {
        $this->permit($r,'inventory'); $d=$r->validate(['courier'=>'required|string|max:100','tracking'=>'required|string|max:100','eta'=>'nullable|date','idempotency_key'=>'required|uuid',
            'items'=>'required|array|min:1|max:100','items.*'=>'array:line_id,quantity','items.*.line_id'=>'required|integer|distinct','items.*.quantity'=>'required|integer|min:1|max:10000']);
        $s->dispatch($r->user(),$order,$d); return back()->with('success','Shipment recorded and stock updated.');
    }
    public function account(Request $r) {
        $this->permit($r,'accounts'); $roles=['dealer','sub_dealer']; if($r->user()->role==='super_admin') $roles=[...$roles,...array_keys(config('madina.roles'))];
        $isPartner = in_array($r->input('role'), ['dealer', 'sub_dealer'], true);
        $d=$r->validate([
            'role'=>['required',Rule::in($roles)],
            'dealer_code'=>$isPartner ? ['required','string','max:40','unique:users,dealer_code'] : ['nullable','string','max:40','unique:users,dealer_code'],
            'name'=>'required|string|max:150',
            'email'=>'required|email|max:254|unique:users,email',
            'phone'=>'nullable|string|max:30',
            'division'=>'nullable|string|max:80',
            'district'=>'nullable|string|max:80',
            'latitude'=>'nullable|numeric|between:-90,90',
            'longitude'=>'nullable|numeric|between:-180,180',
            'directory_visible'=>'sometimes|boolean',
            'status'=>'sometimes|in:active,suspended',
            'sponsor_id'=>'nullable|integer|exists:users,id',
            'nid'=>'nullable|string|max:30',
            'reason'=>'nullable|string|max:1000',
            'profile'=>'sometimes|array',
            'profile.name_bn'=>'nullable|string|max:150',
            'profile.father_name'=>'nullable|string|max:150',
            'profile.mother_name'=>'nullable|string|max:150',
            'profile.village'=>'nullable|string|max:150',
            'profile.post_office'=>'nullable|string|max:150',
            'profile.upazila'=>'nullable|string|max:150',
            'profile.union'=>'nullable|string|max:150',
            'profile.pharmacy'=>'nullable|string|max:150',
            'profile.area_code'=>'nullable|string|max:80',
        ]);
        $profileKeys=['name_bn','father_name','mother_name','village','post_office','upazila','union','pharmacy','area_code'];
        $profileData=$d['profile']??[];
        foreach($profileKeys as $key) {
            if($r->filled($key)) {
                $profileData[$key]=trim((string)$r->string($key));
            }
        }
        $d['profile']=$profileData;
        if (!empty($d['sponsor_id'])) {
            abort_unless(User::whereKey($d['sponsor_id'])->where('role','dealer')->where('status','active')->exists(),422,'Sponsor must be an active dealer.');
        }
        if (($d['directory_visible']??false) && $d['role']!=='dealer') {
            throw ValidationException::withMessages(['directory_visible'=>'Only approved dealer business contacts may be published. Sub-dealer publication is not approved.']);
        }
        $u=DB::transaction(function()use($r,$d,$isPartner){
            $role=$d['role'];
            $dealerCode=$isPartner ? trim((string)$d['dealer_code']) : ($d['dealer_code']??null);
            $reason=$d['reason']??'Account created by company staff';
            unset($d['reason']);
            $u=User::create([
                ...$d,
                'dealer_code'=>$dealerCode,
                'password'=>Str::random(48),
            ]);
            DB::table('wallets')->insert(['user_id'=>$u->id,'created_at'=>now(),'updated_at'=>now()]);
            \App\Models\ReferralTreePath::attachUser($u->id, $u->sponsor_id);
            \App\Models\DealerRank::firstOrCreate(['user_id' => $u->id], ['current_rank' => 'dealer']);
            Audit::record($r->user()->id,'account.created',(string)$u->id, [
                'role'=>$role,
                'dealer_code'=>$dealerCode,
                'reason'=>$reason,
            ]);
            return $u;
        });
        Password::sendResetLink(['email'=>$u->email]);
        return redirect('/company/accounts')->with('success','Partner account created. Unique code: '.($u->dealer_code??$u->name).'. A private password-setting invitation has been emailed.');
    }
    public function accountUpdate(Request $r,User $user) {
        $this->permit($r,'accounts'); abort_if($user->staff() && $r->user()->role!=='super_admin',403); abort_if($r->user()->id===$user->id,422,'Use your security page; self-suspension is not allowed.');
        if ($r->exists('nid')) $this->permit($r,'identity_write');
        $d=$r->validate(['status'=>'sometimes|in:active,suspended','name'=>'sometimes|required|string|max:150','dealer_code'=>'sometimes|nullable|string|max:40|unique:users,dealer_code,'.$user->id,'phone'=>'nullable|string|max:30','division'=>'nullable|string|max:80','district'=>'nullable|string|max:80','latitude'=>'sometimes|nullable|numeric|between:-90,90','longitude'=>'sometimes|nullable|numeric|between:-180,180','sponsor_id'=>'sometimes|nullable|integer|exists:users,id','directory_visible'=>'sometimes|boolean','reason'=>'required|string|max:1000',
            'profile'=>'sometimes|array','profile.name_bn'=>'nullable|string|max:150','profile.father_name'=>'nullable|string|max:150','profile.mother_name'=>'nullable|string|max:150','profile.village'=>'nullable|string|max:150','profile.post_office'=>'nullable|string|max:150','profile.upazila'=>'nullable|string|max:150','profile.union'=>'nullable|string|max:150','profile.pharmacy'=>'nullable|string|max:150','profile.area_code'=>'nullable|string|max:80','nid'=>'sometimes|nullable|string|max:30']);
        if ($r->has('sponsor_id')) {
            $newSponsorId = !empty($d['sponsor_id']) ? (int)$d['sponsor_id'] : null;
            if ($newSponsorId) {
                if ($newSponsorId === $user->id) {
                    throw ValidationException::withMessages(['sponsor_id' => 'Self-sponsorship is disallowed. A partner cannot be their own sponsor.']);
                }
                abort_unless(User::whereKey($newSponsorId)->where('role','dealer')->where('status','active')->exists(),422,'Sponsor must be an active dealer.');
                if (\App\Models\ReferralTreePath::isDescendant($user->id, $newSponsorId)) {
                    throw ValidationException::withMessages(['sponsor_id' => 'Circular referral cycle detected: The selected sponsor is already a downline descendant of this user.']);
                }
            }
        }
        $profileKeys=['name_bn','father_name','mother_name','village','post_office','upazila','union','pharmacy','area_code'];
        if ($r->has('profile') || $r->hasAny($profileKeys)) {
            $existing = $user->profile ?? [];
            $incoming = $d['profile'] ?? [];
            foreach ($profileKeys as $key) {
                if ($r->filled($key)) {
                    $incoming[$key] = trim((string)$r->string($key));
                }
            }
            $d['profile'] = array_merge($existing, $incoming);
        }
        DB::transaction(function()use($r,$user,$d){
            $reason=$d['reason']; unset($d['reason']);
            $locked=User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('sponsor_id', $d) && (int)$locked->sponsor_id !== (int)($d['sponsor_id'] ?? null)) {
                \App\Models\ReferralTreePath::reparentUser($locked->id, !empty($d['sponsor_id']) ? (int)$d['sponsor_id'] : null);
            }

            $locked->update($d);
            if(($d['status']??'')==='suspended') {
                $locked->update(['remember_token'=>Str::random(60)]);
                DB::table('sessions')->where('user_id',$user->id)->delete();
            }
            Audit::record($r->user()->id,'account.updated',(string)$user->id,['fields'=>array_keys($d),'reason'=>$reason]);
        });
        return back()->with('success','Account updated.');
    }
    public function product(Request $r) {
        $this->permit($r,'catalog'); abort_unless($r->user()->role==='super_admin',403);
        $d=$r->validate(['name'=>'required|string|max:150','sku'=>'required|string|max:60|unique:products','category'=>'required|string|max:100','description'=>'nullable|string|max:3000',
            'mrp'=>'required|regex:/^\d{1,8}(\.\d{1,2})?$/','dealer_price'=>'nullable|regex:/^\d{1,8}(\.\d{1,2})?$/','unit'=>'required|string|max:30']);
        if(Money::cents($d['mrp'])<=0 || (isset($d['dealer_price']) && (Money::cents($d['dealer_price'])<=0||Money::cents($d['dealer_price'])>Money::cents($d['mrp'])))) throw ValidationException::withMessages(['mrp'=>'Enter positive prices; dealer price cannot exceed MRP.']);
        DB::transaction(function()use($r,$d){ $p=Product::create($d); Audit::record($r->user()->id,'product.created',(string)$p->id); }); return back()->with('success','Product created. Receive stock before dispatch.');
    }
    public function stock(Request $r) {
        $this->permit($r,'inventory'); $d=$r->validate(['product_id'=>'required|integer|exists:products,id','quantity'=>'required|integer|not_in:0|min:-100000|max:100000','reason'=>'required|string|max:1000','reference'=>'required|uuid']);
        DB::transaction(function()use($r,$d){ $p=Product::whereKey($d['product_id'])->lockForUpdate()->firstOrFail();
            if(DB::table('stock_movements')->where('reference',$d['reference'])->exists()) throw ValidationException::withMessages(['reference'=>'This stock operation is already recorded.']);
            if($p->stock+$d['quantity']<0) throw ValidationException::withMessages(['quantity'=>'Stock cannot become negative.']);
            $p->increment('stock',$d['quantity']); DB::table('stock_movements')->insert([...$d,'kind'=>$d['quantity']>0?'receipt':'adjustment','actor_id'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now()]); Audit::record($r->user()->id,'stock.adjusted',(string)$p->id,['quantity'=>$d['quantity'],'reason'=>$d['reason']]); });
        return back()->with('success','Stock movement recorded.');
    }
    public function policy(Request $r) {
        $this->permit($r,'finance'); $d=$r->validate(['name'=>'required|string|max:150','account_type'=>'required|in:dealer,sub_dealer','components'=>'required|array|min:1|max:20','components.*.name'=>'required|string|max:80','components.*.rate'=>'required|numeric|min:0|max:100','notes'=>'required|string|max:4000']);
        DB::transaction(function()use($r,$d){ $id=DB::table('policy_drafts')->insertGetId([...$d,'components'=>json_encode($d['components']),'basis'=>'mrp','created_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now()]); Audit::record($r->user()->id,'policy.drafted',(string)$id); });
        return back()->with('success','Draft saved. No earnings will be posted.');
    }
    public function content(Request $r) {
        $this->permit($r,'content'); $d=$r->validate(['slug'=>'required|in:about,contact,home,notice','title'=>'required|string|max:150','body'=>'required|string|max:10000','published'=>'required|boolean']);
        DB::transaction(function()use($r,$d){ DB::table('content_pages')->updateOrInsert(['slug'=>$d['slug']],[...$d,'updated_at'=>now()]); Audit::record($r->user()->id,'content.updated',$d['slug']); }); return back()->with('success','Content saved.');
    }
    public function customization(Request $r) {
        abort_unless($r->user()->allows('content') || $r->user()->allows('catalog') || $r->user()->role === 'super_admin', 403);
        $payload=$r->input('customization',[]);
        if (!is_array($payload)) $payload=[];
        \App\Support\SiteCustomization::save($payload);
        Audit::record($r->user()->id,'customization.updated','frontend');
        return back()->with('success','Site customization saved successfully.');
    }
    public function resetCustomization(Request $r) {
        $this->permit($r,'content');
        \App\Support\SiteCustomization::reset();
        Audit::record($r->user()->id,'customization.reset','frontend');
        return back()->with('success','Customization reset to default placeholders.');
    }
    public function enquiry(Request $r,int $id) { $this->permit($r,'content'); $d=$r->validate(['status'=>'required|in:new,contacted,closed']); abort_unless(DB::table('enquiries')->where('id',$id)->exists(),404); DB::table('enquiries')->where('id',$id)->update([...$d,'updated_at'=>now()]); Audit::record($r->user()->id,'enquiry.updated',(string)$id); return back()->with('success','Enquiry updated.'); }
    public function readNotice(Request $r,int $id) { abort_unless(DB::table('account_notices')->where('id',$id)->where('user_id',$r->user()->id)->update(['read_at'=>now()]),404); return back(); }
    public function financialGate() { throw ValidationException::withMessages(['policy'=>'This action is unavailable until the financial policy is approved and verified.']); }

    public function claim(Request $r,Order $order,OrderService $service) {
        abort_unless($order->user_id===$r->user()->id && !$r->user()->staff(),404);
        $data=$r->validate(['channel'=>'required|in:bKash,Nagad,Rocket,Bank','reference'=>'required|string|max:100','amount'=>'required|regex:/^\d{1,9}(\.\d{1,2})?$/']);
        $service->claim($r->user(),$order,$data); return back()->with('success','Payment reference submitted for verification.');
    }

    public function delivered(Request $r,Order $order,int $shipment,OrderService $service) {
        $this->permit($r,'inventory'); $data=$r->validate(['reason'=>'required|string|max:1000']);
        $service->deliver($r->user(),$order,$shipment,$data['reason']); return back()->with('success','Delivery confirmation recorded.');
    }

    public function invite(Request $r,User $user) {
        $this->permit($r,'accounts'); abort_if($user->staff() && $r->user()->role!=='super_admin',403); abort_unless($user->status==='active',422);
        Password::sendResetLink(['email'=>$user->email]); Audit::record($r->user()->id,'account.invitation_sent',(string)$user->id);
        return back()->with('success','A private password-setting link has been emailed if the account is eligible.');
    }

    public function updateCommissionRules(Request $r) {
        $this->permit($r, 'finance');
        $rules = $r->input('rules', []);
        abort_unless(is_array($rules) && count($rules) > 0, 422, 'Invalid rules payload.');

        // Validate 100% zero-deficit invariant: sum of pool percentages cannot exceed 40.00%
        $percentageSum = 0.0;
        foreach ($rules as $key => $ruleData) {
            $existing = CommissionRuleParameter::where('rule_key', $key)->first();
            if ($existing && $existing->category === 'operations') {
                continue;
            }
            $percentageSum += (float) ($ruleData['percentage'] ?? 0.0);
        }
        if ($percentageSum > 40.0001) {
            throw ValidationException::withMessages([
                'rules' => "Zero-deficit constraint violated: Total commission pool sum is {$percentageSum}%, which exceeds the 40.00% ceiling by " . number_format($percentageSum - 40.00, 2) . "%."
            ]);
        }

        DB::transaction(function () use ($r, $rules) {
            foreach ($rules as $key => $ruleData) {
                $existing = CommissionRuleParameter::where('rule_key', $key)->first();
                if ($existing) {
                    $existing->update([
                        'percentage' => $ruleData['percentage'] ?? $existing->percentage,
                        'amount_threshold' => $ruleData['amount_threshold'] ?? $existing->amount_threshold,
                        'cap_limit' => $ruleData['cap_limit'] ?? $existing->cap_limit,
                        'parameters' => isset($ruleData['parameters']) ? $ruleData['parameters'] : $existing->parameters,
                        'is_active' => isset($ruleData['is_active']) ? (bool)$ruleData['is_active'] : $existing->is_active,
                        'version' => $existing->version + 1,
                        'updated_by' => $r->user()->id,
                    ]);
                }
            }
            Audit::record($r->user()->id, 'commissions.rules_updated', 'system', ['updated_count' => count($rules)]);
        });

        return back()->with('success', 'Commission rule parameters updated successfully.');
    }

    public function settleSalaryPool(Request $r, CommissionService $commissionService) {
        $this->permit($r, 'finance');
        $d = $r->validate(['period_month' => 'required|regex:/^\d{4}-\d{2}$/']);
        $result = $commissionService->settleMonthlySalaryPool($d['period_month']);

        if ($result['status'] === 'empty_pool') {
            return back()->with('success', "Salary pool for {$d['period_month']} is empty.");
        }
        if ($result['status'] === 'rolled_over') {
            return back()->with('success', "No qualified dealers met minimum volume for {$d['period_month']}. Pool rolled to company reserve.");
        }

        return back()->with('success', "Salary pool for {$d['period_month']} distributed ৳{$result['total_pool']} to {$result['qualifiers_count']} qualified dealers.");
    }

    public function settleRankRoyaltyPool(Request $r, CommissionService $commissionService) {
        $this->permit($r, 'finance');
        $d = $r->validate(['period_month' => 'required|regex:/^\d{4}-\d{2}$/']);
        $result = $commissionService->settleRankRoyaltyPool($d['period_month']);

        if ($result['status'] === 'empty_pool') {
            return back()->with('success', "Rank royalty pool for {$d['period_month']} is empty.");
        }

        return back()->with('success', "Rank royalty pool for {$d['period_month']} distributed ৳{$result['total_distributed']} across leadership tiers.");
    }

    public function claimRankIncentive(Request $r, User $user, CommissionService $commissionService) {
        $this->permit($r, 'finance');
        $result = $commissionService->claimOneTimeRankIncentive($user->id);

        if (!$result['success']) {
            throw ValidationException::withMessages(['incentive' => $result['message']]);
        }

        return back()->with('success', "Paid one-time incentive of ৳{$result['amount']} to {$user->name} for rank " . strtoupper($result['rank']) . ".");
    }

    public function releaseMaturedCommissions(Request $r, CommissionService $commissionService) {
        $this->permit($r, 'finance');
        $result = $commissionService->releaseMaturedCommissions();

        if ($result['status'] === 'no_matured_commissions') {
            return back()->with('success', 'No matured commissions pending release.');
        }

        return back()->with('success', "Released {$result['released_count']} matured allocations (৳{$result['total_amount']}) to dealer available balances.");
    }

    public function simulateCommissions(Request $r, CommissionService $commissionService) {
        $this->permit($r, 'finance');
        $overrides = $r->input('overrides', []);
        if (!is_array($overrides)) $overrides = [];
        $result = $commissionService->simulatePlan($overrides);

        return response()->json($result);
    }

    public function assignAreaDealer(Request $r) {
        $this->permit($r, 'accounts');
        $d = $r->validate([
            'user_id' => 'required|exists:users,id',
            'level' => 'required|in:division,district,upazila,union',
            'zone_name' => 'required|string|max:128',
            'division' => 'nullable|string|max:64',
            'district' => 'nullable|string|max:64',
            'upazila' => 'nullable|string|max:64',
            'union' => 'nullable|string|max:64',
            'monthly_quota' => 'required|numeric|min:0',
        ]);

        AreaDealerAssignment::create([
            ...$d,
            'is_active' => true,
        ]);
        Audit::record($r->user()->id, 'area_dealer.assigned', (string)$d['user_id'], ['level' => $d['level'], 'zone' => $d['zone_name']]);

        return back()->with('success', "Assigned area dealer for {$d['zone_name']}.");
    }

    public function removeAreaDealer(Request $r, int $id) {
        $this->permit($r, 'accounts');
        $assignment = AreaDealerAssignment::findOrFail($id);
        $assignment->delete();
        Audit::record($r->user()->id, 'area_dealer.removed', (string)$assignment->user_id, ['level' => $assignment->level, 'zone' => $assignment->zone_name]);

        return back()->with('success', 'Area dealer assignment removed.');
    }
}
