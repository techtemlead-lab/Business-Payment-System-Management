<?php

namespace App\Domain\Commissions;

use App\Models\{
    User, Order, CommissionRuleParameter, CommissionAllocation,
    ReferralTreePath, DealerRank, AreaDealerAssignment, RewardMilestoneProgress
};
use App\Support\{Audit, Money};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommissionService
{
    /**
     * Calculate and atomically post all 9 commission slices for an order.
     */
    public function processOrder(Order $order): array
    {
        return DB::transaction(function () use ($order): array {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            // Guard: Idempotency check - don't process allocations twice
            if (CommissionAllocation::where('order_id', $lockedOrder->id)->exists()) {
                // If the order has just become delivered, refresh ancestor Car & Bike milestone progress
                if ($lockedOrder->status === 'delivered') {
                    $this->updateCarBikeMilestonesForAncestors($lockedOrder->user_id, (float)$lockedOrder->total);
                }
                return CommissionAllocation::where('order_id', $lockedOrder->id)->get()->toArray();
            }

            $buyer = User::whereKey($lockedOrder->user_id)->lockForUpdate()->firstOrFail();
            $cashBasis = (float) ($order->total !== null && (float)$order->total <= 0 ? $order->total : $lockedOrder->total);
            if ($cashBasis <= 0) {
                return [];
            }

            // Ensure buyer exists in closure tree
            ReferralTreePath::attachUser($buyer->id, $buyer->sponsor_id);

            // Fetch dynamic rule parameters
            $rules = CommissionRuleParameter::where('is_active', true)->get()->keyBy('rule_key');

            $coolingRule = $rules['commission_cooling_off'] ?? null;
            $coolingDays = $coolingRule && isset($coolingRule->parameters['cooling_off_days'])
                ? (int)$coolingRule->parameters['cooling_off_days']
                : 0;

            $allocations = [];

            // -------------------------------------------------------------
            // 1. Own Dealer Cashback (Default: 5%)
            // -------------------------------------------------------------
            $ownPct = isset($rules['own_dealer_cashback']) ? (float)$rules['own_dealer_cashback']->percentage : 5.00;
            if ($ownPct > 0) {
                $amount = round($cashBasis * ($ownPct / 100), 2);
                $isHeld = ($coolingDays > 0);
                $alloc = CommissionAllocation::create([
                    'order_id' => $lockedOrder->id,
                    'user_id' => $buyer->id,
                    'category' => 'own_dealer_cashback',
                    'rate_percentage' => $ownPct,
                    'cash_basis' => $cashBasis,
                    'amount' => $amount,
                    'status' => $isHeld ? 'maturing' : 'available',
                    'metadata' => [
                        'description' => 'Own purchase 5% cashback on collected cash',
                        'maturation_due_at' => $isHeld ? now()->addDays($coolingDays)->toIso8601String() : null,
                        'cooling_off_days' => $coolingDays,
                    ],
                ]);
                if ($isHeld) {
                    $this->holdWallet($buyer->id, $amount);
                } else {
                    $this->creditWallet($buyer->id, $amount);
                }
                $allocations[] = $alloc;
            }

            // -------------------------------------------------------------
            // 2 & 3. Referral Commissions (Direct 10%, Gen 2 = 2%, Gen 3 = 1%)
            // Supports both Fixed Generational Depth and Dynamic Compression
            // -------------------------------------------------------------
            $refPct = isset($rules['direct_referral']) ? (float)$rules['direct_referral']->percentage : 10.00;
            $d2dRule = $rules['d2d_multitier'] ?? null;
            $gen2Pct = $d2dRule && isset($d2dRule->parameters['gen2_pct']) ? (float)$d2dRule->parameters['gen2_pct'] : 2.00;
            $gen3Pct = $d2dRule && isset($d2dRule->parameters['gen3_pct']) ? (float)$d2dRule->parameters['gen3_pct'] : 1.00;
            $useDynamicCompression = $d2dRule && !empty($d2dRule->parameters['dynamic_compression']);

            if ($useDynamicCompression) {
                // Dynamic Compression: traverse ancestor lineage ordered by depth and select active partners
                $ancestorPaths = ReferralTreePath::where('descendant_id', $buyer->id)
                    ->where('depth', '>', 0)
                    ->orderBy('depth', 'asc')
                    ->get();

                $activeAncestors = [];
                foreach ($ancestorPaths as $ap) {
                    $ancUser = User::find($ap->ancestor_id);
                    if ($ancUser && $ancUser->status === 'active') {
                        $activeAncestors[] = [
                            'user' => $ancUser,
                            'original_depth' => $ap->depth,
                        ];
                    }
                }

                // Tier 1: Direct Referral (10%)
                if ($refPct > 0) {
                    $amount = round($cashBasis * ($refPct / 100), 2);
                    $tier1 = $activeAncestors[0] ?? null;
                    $tier1User = $tier1 ? $tier1['user'] : null;
                    $isHeld = ($coolingDays > 0);
                    $status = $isHeld ? 'maturing' : 'available';

                    $alloc = CommissionAllocation::create([
                        'order_id' => $lockedOrder->id,
                        'user_id' => $tier1User ? $tier1User->id : null,
                        'category' => $tier1User ? 'direct_referral' : 'direct_referral_breakage',
                        'rate_percentage' => $refPct,
                        'cash_basis' => $cashBasis,
                        'amount' => $amount,
                        'status' => $tier1User ? $status : 'available',
                        'metadata' => [
                            'buyer_id' => $buyer->id,
                            'buyer_name' => $buyer->name,
                            'compressed' => $tier1 ? ($tier1['original_depth'] > 1) : false,
                            'original_depth' => $tier1 ? $tier1['original_depth'] : null,
                            'compressed_depth' => 1,
                            'is_breakage' => $tier1User === null,
                            'maturation_due_at' => ($isHeld && $tier1User) ? now()->addDays($coolingDays)->toIso8601String() : null,
                            'cooling_off_days' => $coolingDays,
                        ],
                    ]);
                    if ($tier1User) {
                        if ($isHeld) {
                            $this->holdWallet($tier1User->id, $amount);
                        } else {
                            $this->creditWallet($tier1User->id, $amount);
                        }
                        Audit::notice($tier1User->id, 'Direct Referral Earned', "Earned ৳{$amount} from dealer {$buyer->name}'s order.");
                    }
                    $allocations[] = $alloc;
                }

                // Tier 2: Gen 2 D2D (2%)
                if ($gen2Pct > 0) {
                    $amount = round($cashBasis * ($gen2Pct / 100), 2);
                    $tier2 = $activeAncestors[1] ?? null;
                    $tier2User = $tier2 ? $tier2['user'] : null;
                    $isHeld = ($coolingDays > 0);
                    $status = $isHeld ? 'maturing' : 'available';

                    $alloc = CommissionAllocation::create([
                        'order_id' => $lockedOrder->id,
                        'user_id' => $tier2User ? $tier2User->id : null,
                        'category' => $tier2User ? 'd2d_gen2' : 'd2d_gen2_breakage',
                        'rate_percentage' => $gen2Pct,
                        'cash_basis' => $cashBasis,
                        'amount' => $amount,
                        'status' => $tier2User ? $status : 'available',
                        'metadata' => [
                            'buyer_id' => $buyer->id,
                            'compressed' => $tier2 ? ($tier2['original_depth'] > 2) : false,
                            'original_depth' => $tier2 ? $tier2['original_depth'] : null,
                            'compressed_depth' => 2,
                            'is_breakage' => $tier2User === null,
                            'maturation_due_at' => ($isHeld && $tier2User) ? now()->addDays($coolingDays)->toIso8601String() : null,
                            'cooling_off_days' => $coolingDays,
                        ],
                    ]);
                    if ($tier2User) {
                        if ($isHeld) {
                            $this->holdWallet($tier2User->id, $amount);
                        } else {
                            $this->creditWallet($tier2User->id, $amount);
                        }
                    }
                    $allocations[] = $alloc;
                }

                // Tier 3: Gen 3 D2D (1%)
                if ($gen3Pct > 0) {
                    $amount = round($cashBasis * ($gen3Pct / 100), 2);
                    $tier3 = $activeAncestors[2] ?? null;
                    $tier3User = $tier3 ? $tier3['user'] : null;
                    $isHeld = ($coolingDays > 0);
                    $status = $isHeld ? 'maturing' : 'available';

                    $alloc = CommissionAllocation::create([
                        'order_id' => $lockedOrder->id,
                        'user_id' => $tier3User ? $tier3User->id : null,
                        'category' => $tier3User ? 'd2d_gen3' : 'd2d_gen3_breakage',
                        'rate_percentage' => $gen3Pct,
                        'cash_basis' => $cashBasis,
                        'amount' => $amount,
                        'status' => $tier3User ? $status : 'available',
                        'metadata' => [
                            'buyer_id' => $buyer->id,
                            'compressed' => $tier3 ? ($tier3['original_depth'] > 3) : false,
                            'original_depth' => $tier3 ? $tier3['original_depth'] : null,
                            'compressed_depth' => 3,
                            'is_breakage' => $tier3User === null,
                            'maturation_due_at' => ($isHeld && $tier3User) ? now()->addDays($coolingDays)->toIso8601String() : null,
                            'cooling_off_days' => $coolingDays,
                        ],
                    ]);
                    if ($tier3User) {
                        if ($isHeld) {
                            $this->holdWallet($tier3User->id, $amount);
                        } else {
                            $this->creditWallet($tier3User->id, $amount);
                        }
                    }
                    $allocations[] = $alloc;
                }
            } else {
                // Fixed Generational Depth: strict depth 1, 2, 3 with suspended upline hold
                if ($refPct > 0) {
                    $amount = round($cashBasis * ($refPct / 100), 2);
                    $gen1 = ReferralTreePath::where('descendant_id', $buyer->id)->where('depth', 1)->first();
                    $sponsor = $gen1 ? User::find($gen1->ancestor_id) : null;
                    $sponsorActive = $sponsor && $sponsor->status === 'active';
                    $isHeld = ($coolingDays > 0);
                    $status = $sponsorActive ? ($isHeld ? 'maturing' : 'available') : 'held';

                    $alloc = CommissionAllocation::create([
                        'order_id' => $lockedOrder->id,
                        'user_id' => $sponsor ? $sponsor->id : null,
                        'category' => 'direct_referral',
                        'rate_percentage' => $refPct,
                        'cash_basis' => $cashBasis,
                        'amount' => $amount,
                        'status' => $status,
                        'metadata' => [
                            'buyer_id' => $buyer->id,
                            'buyer_name' => $buyer->name,
                            'is_orphan' => $sponsor === null,
                            'upline_status' => $sponsor ? $sponsor->status : 'none',
                            'maturation_due_at' => ($isHeld && $sponsorActive) ? now()->addDays($coolingDays)->toIso8601String() : null,
                            'cooling_off_days' => $coolingDays,
                        ],
                    ]);
                    if ($sponsorActive) {
                        if ($isHeld) {
                            $this->holdWallet($sponsor->id, $amount);
                        } else {
                            $this->creditWallet($sponsor->id, $amount);
                        }
                        Audit::notice($sponsor->id, 'Direct Referral Earned', "Earned ৳{$amount} from dealer {$buyer->name}'s order.");
                    }
                    $allocations[] = $alloc;
                }

                // Gen 2
                if ($gen2Pct > 0) {
                    $amount = round($cashBasis * ($gen2Pct / 100), 2);
                    $gen2 = ReferralTreePath::where('descendant_id', $buyer->id)->where('depth', 2)->first();
                    $upline2 = $gen2 ? User::find($gen2->ancestor_id) : null;
                    $upline2Active = $upline2 && $upline2->status === 'active';
                    $isHeld = ($coolingDays > 0);
                    $status = $upline2Active ? ($isHeld ? 'maturing' : 'available') : 'held';

                    $alloc = CommissionAllocation::create([
                        'order_id' => $lockedOrder->id,
                        'user_id' => $upline2 ? $upline2->id : null,
                        'category' => 'd2d_gen2',
                        'rate_percentage' => $gen2Pct,
                        'cash_basis' => $cashBasis,
                        'amount' => $amount,
                        'status' => $status,
                        'metadata' => [
                            'depth' => 2,
                            'buyer_id' => $buyer->id,
                            'upline_status' => $upline2 ? $upline2->status : 'none',
                            'maturation_due_at' => ($isHeld && $upline2Active) ? now()->addDays($coolingDays)->toIso8601String() : null,
                            'cooling_off_days' => $coolingDays,
                        ],
                    ]);
                    if ($upline2Active) {
                        if ($isHeld) {
                            $this->holdWallet($upline2->id, $amount);
                        } else {
                            $this->creditWallet($upline2->id, $amount);
                        }
                    }
                    $allocations[] = $alloc;
                }

                // Gen 3
                if ($gen3Pct > 0) {
                    $amount = round($cashBasis * ($gen3Pct / 100), 2);
                    $gen3 = ReferralTreePath::where('descendant_id', $buyer->id)->where('depth', 3)->first();
                    $upline3 = $gen3 ? User::find($gen3->ancestor_id) : null;
                    $upline3Active = $upline3 && $upline3->status === 'active';
                    $isHeld = ($coolingDays > 0);
                    $status = $upline3Active ? ($isHeld ? 'maturing' : 'available') : 'held';

                    $alloc = CommissionAllocation::create([
                        'order_id' => $lockedOrder->id,
                        'user_id' => $upline3 ? $upline3->id : null,
                        'category' => 'd2d_gen3',
                        'rate_percentage' => $gen3Pct,
                        'cash_basis' => $cashBasis,
                        'amount' => $amount,
                        'status' => $status,
                        'metadata' => [
                            'depth' => 3,
                            'buyer_id' => $buyer->id,
                            'upline_status' => $upline3 ? $upline3->status : 'none',
                            'maturation_due_at' => ($isHeld && $upline3Active) ? now()->addDays($coolingDays)->toIso8601String() : null,
                            'cooling_off_days' => $coolingDays,
                        ],
                    ]);
                    if ($upline3Active) {
                        if ($isHeld) {
                            $this->holdWallet($upline3->id, $amount);
                        } else {
                            $this->creditWallet($upline3->id, $amount);
                        }
                    }
                    $allocations[] = $alloc;
                }
            }

            // -------------------------------------------------------------
            // 4. Area Dealer Pool (10% total: 2% Management + 2% Div + 2% Dist + 2% Upazila + 2% Union)
            // Hardened with Exact Penny Remainder Reconciliation & Unassigned Territory Treasury Fallback
            // -------------------------------------------------------------
            $areaRule = $rules['area_dealer_pool'] ?? null;
            $totalAreaPct = isset($areaRule->percentage) ? (float)$areaRule->percentage : 10.00;
            $totalAreaPool = round($cashBasis * ($totalAreaPct / 100), 2);

            $mgmtPct = $areaRule && isset($areaRule->parameters['management_share_pct']) ? (float)$areaRule->parameters['management_share_pct'] : 2.00;
            $divPct = $areaRule && isset($areaRule->parameters['division_share_pct']) ? (float)$areaRule->parameters['division_share_pct'] : 2.00;
            $distPct = $areaRule && isset($areaRule->parameters['district_share_pct']) ? (float)$areaRule->parameters['district_share_pct'] : 2.00;
            $upaPct = $areaRule && isset($areaRule->parameters['upazila_share_pct']) ? (float)$areaRule->parameters['upazila_share_pct'] : 2.00;
            $uniPct = $areaRule && isset($areaRule->parameters['union_share_pct']) ? (float)$areaRule->parameters['union_share_pct'] : 2.00;

            // 4.2 Division Area Dealer
            $isHeld = ($coolingDays > 0);
            $divDealer = $buyer->division ? AreaDealerAssignment::where('level', 'division')->where('division', $buyer->division)->where('is_active', true)->first() : null;
            $divAmt = round($cashBasis * ($divPct / 100), 2);
            $allocations[] = CommissionAllocation::create([
                'order_id' => $lockedOrder->id,
                'user_id' => $divDealer ? $divDealer->user_id : null,
                'category' => 'area_division',
                'rate_percentage' => $divPct,
                'cash_basis' => $cashBasis,
                'amount' => $divAmt,
                'status' => $divDealer ? ($isHeld ? 'maturing' : 'available') : 'unassigned_reserve',
                'metadata' => [
                    'tier' => 'division',
                    'zone' => $buyer->division,
                    'unassigned' => $divDealer === null,
                    'retained_by' => 'company_geographic_reserve',
                    'maturation_due_at' => ($isHeld && $divDealer) ? now()->addDays($coolingDays)->toIso8601String() : null,
                    'cooling_off_days' => $coolingDays,
                ],
            ]);
            if ($divDealer) {
                if ($isHeld) {
                    $this->holdWallet($divDealer->user_id, $divAmt);
                } else {
                    $this->creditWallet($divDealer->user_id, $divAmt);
                }
            }

            // 4.3 District Area Dealer
            $distDealer = $buyer->district ? AreaDealerAssignment::where('level', 'district')->where('district', $buyer->district)->where('is_active', true)->first() : null;
            $distAmt = round($cashBasis * ($distPct / 100), 2);
            $allocations[] = CommissionAllocation::create([
                'order_id' => $lockedOrder->id,
                'user_id' => $distDealer ? $distDealer->user_id : null,
                'category' => 'area_district',
                'rate_percentage' => $distPct,
                'cash_basis' => $cashBasis,
                'amount' => $distAmt,
                'status' => $distDealer ? ($isHeld ? 'maturing' : 'available') : 'unassigned_reserve',
                'metadata' => [
                    'tier' => 'district',
                    'zone' => $buyer->district,
                    'unassigned' => $distDealer === null,
                    'retained_by' => 'company_geographic_reserve',
                    'maturation_due_at' => ($isHeld && $distDealer) ? now()->addDays($coolingDays)->toIso8601String() : null,
                    'cooling_off_days' => $coolingDays,
                ],
            ]);
            if ($distDealer) {
                if ($isHeld) {
                    $this->holdWallet($distDealer->user_id, $distAmt);
                } else {
                    $this->creditWallet($distDealer->user_id, $distAmt);
                }
            }

            // 4.4 Upazila Area Dealer
            $upazila = $buyer->profile['upazila'] ?? null;
            $upaDealer = $upazila ? AreaDealerAssignment::where('level', 'upazila')->where('upazila', $upazila)->where('is_active', true)->first() : null;
            $upaAmt = round($cashBasis * ($upaPct / 100), 2);
            $allocations[] = CommissionAllocation::create([
                'order_id' => $lockedOrder->id,
                'user_id' => $upaDealer ? $upaDealer->user_id : null,
                'category' => 'area_upazila',
                'rate_percentage' => $upaPct,
                'cash_basis' => $cashBasis,
                'amount' => $upaAmt,
                'status' => $upaDealer ? ($isHeld ? 'maturing' : 'available') : 'unassigned_reserve',
                'metadata' => [
                    'tier' => 'upazila',
                    'zone' => $upazila,
                    'unassigned' => $upaDealer === null,
                    'retained_by' => 'company_geographic_reserve',
                    'maturation_due_at' => ($isHeld && $upaDealer) ? now()->addDays($coolingDays)->toIso8601String() : null,
                    'cooling_off_days' => $coolingDays,
                ],
            ]);
            if ($upaDealer) {
                if ($isHeld) {
                    $this->holdWallet($upaDealer->user_id, $upaAmt);
                } else {
                    $this->creditWallet($upaDealer->user_id, $upaAmt);
                }
            }

            // 4.5 Union Area Dealer
            $union = $buyer->profile['union'] ?? null;
            $uniDealer = $union ? AreaDealerAssignment::where('level', 'union')->where('union', $union)->where('is_active', true)->first() : null;
            $uniAmt = round($cashBasis * ($uniPct / 100), 2);
            $allocations[] = CommissionAllocation::create([
                'order_id' => $lockedOrder->id,
                'user_id' => $uniDealer ? $uniDealer->user_id : null,
                'category' => 'area_union',
                'rate_percentage' => $uniPct,
                'cash_basis' => $cashBasis,
                'amount' => $uniAmt,
                'status' => $uniDealer ? ($isHeld ? 'maturing' : 'available') : 'unassigned_reserve',
                'metadata' => [
                    'tier' => 'union',
                    'zone' => $union,
                    'unassigned' => $uniDealer === null,
                    'retained_by' => 'company_geographic_reserve',
                    'maturation_due_at' => ($isHeld && $uniDealer) ? now()->addDays($coolingDays)->toIso8601String() : null,
                    'cooling_off_days' => $coolingDays,
                ],
            ]);
            if ($uniDealer) {
                if ($isHeld) {
                    $this->holdWallet($uniDealer->user_id, $uniAmt);
                } else {
                    $this->creditWallet($uniDealer->user_id, $uniAmt);
                }
            }

            // 4.1 Management Share (Company reserve with Exact Penny Remainder Reconciliation)
            $territorySum = $divAmt + $distAmt + $upaAmt + $uniAmt;
            $mgmtAmt = max(0.00, round($totalAreaPool - $territorySum, 2));
            $allocations[] = CommissionAllocation::create([
                'order_id' => $lockedOrder->id,
                'user_id' => null, // Company Management Fund
                'category' => 'area_management',
                'rate_percentage' => $mgmtPct,
                'cash_basis' => $cashBasis,
                'amount' => $mgmtAmt,
                'status' => 'available',
                'metadata' => [
                    'tier' => 'management',
                    'reconciled_cents' => round($mgmtAmt - round($cashBasis * ($mgmtPct / 100), 2), 2),
                ],
            ]);

            // -------------------------------------------------------------
            // 5. Dealer Monthly Salary Pool (2%)
            // -------------------------------------------------------------
            $salPct = isset($rules['dealer_monthly_salary']) ? (float)$rules['dealer_monthly_salary']->percentage : 2.00;
            $allocations[] = CommissionAllocation::create([
                'order_id' => $lockedOrder->id,
                'user_id' => null, // Pooled for monthly qualification distribution
                'category' => 'dealer_monthly_salary_pool',
                'rate_percentage' => $salPct,
                'cash_basis' => $cashBasis,
                'amount' => round($cashBasis * ($salPct / 100), 2),
                'status' => 'pending',
                'metadata' => ['distribution' => 'monthly'],
            ]);

            // -------------------------------------------------------------
            // 6. Leadership Rank Royalty Pool (4%)
            // -------------------------------------------------------------
            $rankPct = isset($rules['rank_royalty']) ? (float)$rules['rank_royalty']->percentage : 4.00;
            $allocations[] = CommissionAllocation::create([
                'order_id' => $lockedOrder->id,
                'user_id' => null, // Pooled for monthly rank distribution (MO, AM, SM, ME)
                'category' => 'rank_royalty_pool',
                'rate_percentage' => $rankPct,
                'cash_basis' => $cashBasis,
                'amount' => round($cashBasis * ($rankPct / 100), 2),
                'status' => 'pending',
                'metadata' => ['distribution' => 'monthly_rank'],
            ]);

            // -------------------------------------------------------------
            // 7. One-Time Rank Incentive Pool (2%)
            // -------------------------------------------------------------
            $incPct = isset($rules['rank_incentive']) ? (float)$rules['rank_incentive']->percentage : 2.00;
            $allocations[] = CommissionAllocation::create([
                'order_id' => $lockedOrder->id,
                'user_id' => null,
                'category' => 'rank_incentive_pool',
                'rate_percentage' => $incPct,
                'cash_basis' => $cashBasis,
                'amount' => round($cashBasis * ($incPct / 100), 2),
                'status' => 'pending',
                'metadata' => ['distribution' => 'one_time_achievement'],
            ]);

            // -------------------------------------------------------------
            // 8. Company Marketing Fund (2%)
            // -------------------------------------------------------------
            $mktPct = isset($rules['company_marketing']) ? (float)$rules['company_marketing']->percentage : 2.00;
            $allocations[] = CommissionAllocation::create([
                'order_id' => $lockedOrder->id,
                'user_id' => null,
                'category' => 'company_marketing_fund',
                'rate_percentage' => $mktPct,
                'cash_basis' => $cashBasis,
                'amount' => round($cashBasis * ($mktPct / 100), 2),
                'status' => 'available',
                'metadata' => ['purpose' => 'advertising_and_branding'],
            ]);

            // -------------------------------------------------------------
            // 9. Car & Bike Asset Incentive Fund (2%)
            // -------------------------------------------------------------
            $rewardPct = isset($rules['car_bike_incentive']) ? (float)$rules['car_bike_incentive']->percentage : 2.00;
            $allocations[] = CommissionAllocation::create([
                'order_id' => $lockedOrder->id,
                'user_id' => null,
                'category' => 'car_bike_incentive_fund',
                'rate_percentage' => $rewardPct,
                'cash_basis' => $cashBasis,
                'amount' => round($cashBasis * ($rewardPct / 100), 2),
                'status' => 'pending',
                'metadata' => ['purpose' => 'vehicle_asset_reserve'],
            ]);

            // -------------------------------------------------------------
            // Post-Order Upgrades: Rank Progression & Car/Bike Volume
            // -------------------------------------------------------------
            $this->updateRankVolume($buyer->id, $cashBasis);
            $this->updateCarBikeMilestonesForAncestors($buyer->id, $cashBasis);

            // -------------------------------------------------------------
            // Zero-Deficit Runtime Budget Guard (Ceiling: 40.00% of collected cash)
            // -------------------------------------------------------------
            $maxCommissionBudget = round($cashBasis * 0.40, 2);
            $totalAllocatedAmount = array_reduce($allocations, fn($sum, $al) => $sum + (float)$al->amount, 0.0);
            if ($totalAllocatedAmount > $maxCommissionBudget + 0.01) {
                throw new \RuntimeException("Zero-deficit invariant violated: Total allocated commissions (৳{$totalAllocatedAmount}) exceed 40% pool ceiling (৳{$maxCommissionBudget}).");
            }

            // -------------------------------------------------------------
            // Double-Entry Accounting Journal Entry Posting
            // -------------------------------------------------------------
            $totalUserCredits = array_reduce($allocations, function($acc, $item) {
                return in_array($item->status, ['available', 'maturing']) && $item->user_id ? $acc + (float)$item->amount : $acc;
            }, 0.0);

            if ($totalUserCredits > 0) {
                $entryKey = "commissions.order.{$lockedOrder->id}";
                if (!DB::table('journal_entries')->where('event_key', $entryKey)->exists()) {
                    $entryId = (string) Str::uuid();
                    DB::table('journal_entries')->insert([
                        'id' => $entryId,
                        'event_key' => $entryKey,
                        'description' => "Commission distribution for delivered order {$lockedOrder->number}",
                        'actor_id' => $buyer->id,
                        'created_at' => now(),
                    ]);

                    // Debit: Company Commission Expense
                    DB::table('journal_lines')->insert([
                        'entry_id' => $entryId,
                        'user_id' => null,
                        'account' => 'commission_expense',
                        'debit' => $totalUserCredits,
                        'credit' => 0.00,
                    ]);

                    // Credit: Each dealer wallet payable or held liability
                    foreach ($allocations as $al) {
                        if ($al->user_id && (float)$al->amount > 0 && in_array($al->status, ['available', 'maturing'])) {
                            $account = ($al->status === 'maturing') ? 'dealer_wallet_held' : 'dealer_wallet_payable';
                            DB::table('journal_lines')->insert([
                                'entry_id' => $entryId,
                                'user_id' => $al->user_id,
                                'account' => $account,
                                'debit' => 0.00,
                                'credit' => (float)$al->amount,
                            ]);
                        }
                    }
                }
            }

            Audit::record($buyer->id, 'commissions.calculated', (string)$lockedOrder->id, [
                'cash_basis' => $cashBasis,
                'allocations_count' => count($allocations),
                'total_allocated' => $totalAllocatedAmount,
            ]);

            return $allocations;
        }, 3);
    }

    /**
     * Reverse commissions for a cancelled or refunded order with full double-entry ledger offset.
     */
    public function reverseOrder(Order $order, string $reason = 'Order cancelled/refunded'): array
    {
        return DB::transaction(function () use ($order, $reason): array {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $allocations = CommissionAllocation::where('order_id', $lockedOrder->id)
                ->whereIn('status', ['available', 'pending', 'held', 'maturing'])
                ->get();

            if ($allocations->isEmpty()) {
                return [];
            }

            $reversals = [];
            $totalClawedBack = 0.00;
            $totalClawedBackHeld = 0.00;

            foreach ($allocations as $alloc) {
                $amount = (float) $alloc->amount;
                $userId = $alloc->user_id;

                $unrecovered = 0.00;
                if ($alloc->status === 'available' && $userId && $amount > 0) {
                    $wallet = DB::table('wallets')->where('user_id', $userId)->lockForUpdate()->first();
                    if ($wallet) {
                        $avail = (float) $wallet->available;
                        $deduct = min($avail, $amount);
                        $unrecovered = round($amount - $deduct, 2);
                        $newAvailable = max(0.00, round($avail - $deduct, 2));

                        DB::table('wallets')->where('user_id', $userId)->update([
                            'available' => $newAvailable,
                            'updated_at' => now(),
                        ]);

                        $totalClawedBack += $deduct;

                        Audit::notice(
                            $userId,
                            'Commission Reversed',
                            "Commission of ৳{$amount} for order {$lockedOrder->number} was reversed ({$reason}). Deducted: ৳{$deduct}." .
                            ($unrecovered > 0 ? " Pending clawback debt: ৳{$unrecovered}." : "")
                        );
                    }
                } elseif ($alloc->status === 'maturing' && $userId && $amount > 0) {
                    $wallet = DB::table('wallets')->where('user_id', $userId)->lockForUpdate()->first();
                    if ($wallet) {
                        $held = (float) $wallet->held;
                        $deduct = min($held, $amount);
                        $newHeld = max(0.00, round($held - $deduct, 2));

                        DB::table('wallets')->where('user_id', $userId)->update([
                            'held' => $newHeld,
                            'updated_at' => now(),
                        ]);

                        $totalClawedBackHeld += $deduct;

                        Audit::notice(
                            $userId,
                            'Maturing Commission Cancelled',
                            "Maturing commission of ৳{$amount} for order {$lockedOrder->number} was cancelled ({$reason}) during cooling-off window."
                        );
                    }
                }

                $alloc->update([
                    'status' => 'reversed',
                    'metadata' => array_merge($alloc->metadata ?? [], [
                        'reversed_at' => now()->toIso8601String(),
                        'reversal_reason' => $reason,
                        'unrecovered_deficit' => $unrecovered,
                    ]),
                ]);

                $reversals[] = $alloc;
            }

            // Double-entry ledger reversal posting
            $combinedClawback = $totalClawedBack + $totalClawedBackHeld;
            if ($combinedClawback > 0) {
                $entryKey = "commissions.reversal.{$lockedOrder->id}";
                if (!DB::table('journal_entries')->where('event_key', $entryKey)->exists()) {
                    $entryId = (string) Str::uuid();
                    DB::table('journal_entries')->insert([
                        'id' => $entryId,
                        'event_key' => $entryKey,
                        'description' => "Commission clawback reversal for order {$lockedOrder->number}: {$reason}",
                        'actor_id' => $lockedOrder->user_id,
                        'created_at' => now(),
                    ]);

                    if ($totalClawedBack > 0) {
                        DB::table('journal_lines')->insert([
                            'entry_id' => $entryId,
                            'user_id' => null,
                            'account' => 'dealer_wallet_payable',
                            'debit' => $totalClawedBack,
                            'credit' => 0.00,
                        ]);
                    }

                    if ($totalClawedBackHeld > 0) {
                        DB::table('journal_lines')->insert([
                            'entry_id' => $entryId,
                            'user_id' => null,
                            'account' => 'dealer_wallet_held',
                            'debit' => $totalClawedBackHeld,
                            'credit' => 0.00,
                        ]);
                    }

                    // Credit: Commission Clawback Recovery (contra-expense)
                    DB::table('journal_lines')->insert([
                        'entry_id' => $entryId,
                        'user_id' => null,
                        'account' => 'commission_clawback_recovery',
                        'debit' => 0.00,
                        'credit' => $combinedClawback,
                    ]);
                }
            }

            // Deduct order volume from buyer's rank purchase totals
            $rank = DealerRank::where('user_id', $lockedOrder->user_id)->first();
            if ($rank) {
                $cashBasis = (float) $lockedOrder->total;
                $rank->decrement('lifetime_purchase_total', min((float)$rank->lifetime_purchase_total, $cashBasis));
                $rank->decrement('monthly_purchase_total', min((float)$rank->monthly_purchase_total, $cashBasis));
            }

            // Recalculate Car/Bike milestones for all ancestors to eliminate reversed volume
            $this->updateCarBikeMilestonesForAncestors($lockedOrder->user_id, 0.0);

            Audit::record($lockedOrder->user_id, 'commissions.reversed', (string)$lockedOrder->id, [
                'reason' => $reason,
                'total_clawed_back' => $totalClawedBack,
                'allocations_reversed' => count($reversals),
            ]);

            return $reversals;
        }, 3);
    }

    /**
     * Reverse commissions pro-rata for a partial order refund.
     */
    public function reverseOrderPartial(Order $order, float $refundAmount, string $reason = 'Partial refund'): array
    {
        return DB::transaction(function () use ($order, $refundAmount, $reason): array {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $orderTotal = (float) $lockedOrder->total;

            if ($refundAmount <= 0 || $orderTotal <= 0) {
                return [];
            }

            if ($refundAmount >= $orderTotal) {
                return $this->reverseOrder($lockedOrder, $reason);
            }

            $ratio = min(1.0, round($refundAmount / $orderTotal, 6));

            $allocations = CommissionAllocation::where('order_id', $lockedOrder->id)
                ->whereIn('status', ['available', 'pending', 'held', 'maturing'])
                ->get();

            $partialReversals = [];
            $totalClawedBack = 0.00;
            $totalClawedBackHeld = 0.00;

            foreach ($allocations as $alloc) {
                $originalAmount = (float) $alloc->amount;
                $proRataClawback = round($originalAmount * $ratio, 2);
                if ($proRataClawback <= 0) continue;

                $userId = $alloc->user_id;
                $unrecovered = 0.00;

                if ($alloc->status === 'available' && $userId) {
                    $wallet = DB::table('wallets')->where('user_id', $userId)->lockForUpdate()->first();
                    if ($wallet) {
                        $avail = (float) $wallet->available;
                        $deduct = min($avail, $proRataClawback);
                        $unrecovered = round($proRataClawback - $deduct, 2);
                        $newAvailable = max(0.00, round($avail - $deduct, 2));

                        DB::table('wallets')->where('user_id', $userId)->update([
                            'available' => $newAvailable,
                            'updated_at' => now(),
                        ]);

                        $totalClawedBack += $deduct;

                        Audit::notice(
                            $userId,
                            'Partial Commission Reversed',
                            "Pro-rata commission of ৳{$proRataClawback} for partial refund on order {$lockedOrder->number} was reversed ({$reason}). Deducted: ৳{$deduct}."
                        );
                    }
                } elseif ($alloc->status === 'maturing' && $userId) {
                    $wallet = DB::table('wallets')->where('user_id', $userId)->lockForUpdate()->first();
                    if ($wallet) {
                        $held = (float) $wallet->held;
                        $deduct = min($held, $proRataClawback);
                        $newHeld = max(0.00, round($held - $deduct, 2));

                        DB::table('wallets')->where('user_id', $userId)->update([
                            'held' => $newHeld,
                            'updated_at' => now(),
                        ]);

                        $totalClawedBackHeld += $deduct;
                    }
                }

                // Adjust allocation amount to reflect remaining net amount
                $newAllocAmount = max(0.00, round($originalAmount - $proRataClawback, 2));
                $alloc->update([
                    'amount' => $newAllocAmount,
                    'metadata' => array_merge($alloc->metadata ?? [], [
                        'partial_refund_at' => now()->toIso8601String(),
                        'partial_refund_amount' => $refundAmount,
                        'pro_rata_ratio' => $ratio,
                        'clawback_amount' => $proRataClawback,
                        'unrecovered_deficit' => $unrecovered,
                    ]),
                ]);

                $partialReversals[] = [
                    'allocation_id' => $alloc->id,
                    'category' => $alloc->category,
                    'user_id' => $userId,
                    'pro_rata_clawback' => $proRataClawback,
                    'remaining_amount' => $newAllocAmount,
                ];
            }

            // Post double-entry reversal journal
            $combinedClawback = $totalClawedBack + $totalClawedBackHeld;
            if ($combinedClawback > 0) {
                $entryId = (string) Str::uuid();
                DB::table('journal_entries')->insert([
                    'id' => $entryId,
                    'event_key' => "commissions.partial_reversal.{$lockedOrder->id}." . Str::random(6),
                    'description' => "Pro-rata commission clawback for partial refund on order {$lockedOrder->number}: {$reason}",
                    'actor_id' => $lockedOrder->user_id,
                    'created_at' => now(),
                ]);

                if ($totalClawedBack > 0) {
                    DB::table('journal_lines')->insert([
                        'entry_id' => $entryId,
                        'user_id' => null,
                        'account' => 'dealer_wallet_payable',
                        'debit' => $totalClawedBack,
                        'credit' => 0.00,
                    ]);
                }

                if ($totalClawedBackHeld > 0) {
                    DB::table('journal_lines')->insert([
                        'entry_id' => $entryId,
                        'user_id' => null,
                        'account' => 'dealer_wallet_held',
                        'debit' => $totalClawedBackHeld,
                        'credit' => 0.00,
                    ]);
                }

                DB::table('journal_lines')->insert([
                    'entry_id' => $entryId,
                    'user_id' => null,
                    'account' => 'commission_clawback_recovery',
                    'debit' => 0.00,
                    'credit' => $combinedClawback,
                ]);
            }

            // Decrement rank volume pro-rata
            $rank = DealerRank::where('user_id', $lockedOrder->user_id)->first();
            if ($rank) {
                $rank->decrement('lifetime_purchase_total', min((float)$rank->lifetime_purchase_total, $refundAmount));
                $rank->decrement('monthly_purchase_total', min((float)$rank->monthly_purchase_total, $refundAmount));
            }

            // Refresh milestones
            $this->updateCarBikeMilestonesForAncestors($lockedOrder->user_id, 0.0);

            return $partialReversals;
        }, 3);
    }

    /**
     * Settle and distribute the 2% Dealer Monthly Salary Pool among qualified dealers.
     */
    public function settleMonthlySalaryPool(string $periodMonth): array
    {
        return DB::transaction(function () use ($periodMonth): array {
            $poolAllocations = CommissionAllocation::where('category', 'dealer_monthly_salary_pool')
                ->where('status', 'pending')
                ->where('created_at', 'like', "{$periodMonth}%")
                ->lockForUpdate()
                ->get();

            $totalPool = round($poolAllocations->sum(fn($a) => (float)$a->amount), 2);
            if ($totalPool <= 0) {
                return ['status' => 'empty_pool', 'total_pool' => 0.00, 'qualifiers_count' => 0];
            }

            $salaryRule = CommissionRuleParameter::getRule('dealer_monthly_salary');
            $minVolume = $salaryRule && isset($salaryRule->parameters['min_monthly_volume'])
                ? (float)$salaryRule->parameters['min_monthly_volume']
                : 10000.00;

            // Find all active dealers who achieved monthly minimum turnover
            $qualifiedDealers = DealerRank::where('monthly_purchase_total', '>=', $minVolume)
                ->whereHas('user', fn($q) => $q->where('status', 'active'))
                ->with('user')
                ->get();

            $qualifierCount = $qualifiedDealers->count();

            if ($qualifierCount === 0) {
                foreach ($poolAllocations as $alloc) {
                    $alloc->update([
                        'status' => 'settled',
                        'metadata' => array_merge($alloc->metadata ?? [], [
                            'settled_at' => now()->toIso8601String(),
                            'period' => $periodMonth,
                            'qualifiers' => 0,
                            'action' => 'rolled_to_company_reserve',
                        ]),
                    ]);
                }

                Audit::record(null, 'commissions.salary_pool_settled', $periodMonth, [
                    'total_pool' => $totalPool,
                    'qualifiers' => 0,
                    'action' => 'rolled_to_company_reserve',
                ]);

                return ['status' => 'rolled_over', 'total_pool' => $totalPool, 'qualifiers_count' => 0];
            }

            // Distribute equal shares with exact penny reconciliation
            $baseShare = floor(($totalPool / $qualifierCount) * 100) / 100;
            $remainder = round($totalPool - ($baseShare * $qualifierCount), 2);

            $distributed = [];
            $totalDistributed = 0.00;

            foreach ($qualifiedDealers as $index => $dealer) {
                $share = ($index === 0) ? round($baseShare + $remainder, 2) : $baseShare;
                $this->creditWallet($dealer->user_id, $share);

                Audit::notice(
                    $dealer->user_id,
                    'Monthly Salary Distributed',
                    "Congratulations! You earned ৳{$share} from the 2% Monthly Salary Pool for {$periodMonth}."
                );

                $distributed[] = [
                    'user_id' => $dealer->user_id,
                    'user_name' => $dealer->user->name,
                    'monthly_volume' => (float)$dealer->monthly_purchase_total,
                    'amount' => $share,
                ];

                $totalDistributed += $share;
            }

            // Post double-entry journal entry
            $entryKey = "commissions.salary_pool.{$periodMonth}";
            if (!DB::table('journal_entries')->where('event_key', $entryKey)->exists()) {
                $entryId = (string) Str::uuid();
                DB::table('journal_entries')->insert([
                    'id' => $entryId,
                    'event_key' => $entryKey,
                    'description' => "Monthly salary pool distribution for period {$periodMonth}",
                    'actor_id' => null,
                    'created_at' => now(),
                ]);

                DB::table('journal_lines')->insert([
                    'entry_id' => $entryId,
                    'user_id' => null,
                    'account' => 'commission_expense',
                    'debit' => $totalDistributed,
                    'credit' => 0.00,
                ]);

                foreach ($distributed as $d) {
                    DB::table('journal_lines')->insert([
                        'entry_id' => $entryId,
                        'user_id' => $d['user_id'],
                        'account' => 'dealer_wallet_payable',
                        'debit' => 0.00,
                        'credit' => $d['amount'],
                    ]);
                }
            }

            // Mark pool allocations as settled
            foreach ($poolAllocations as $alloc) {
                $alloc->update([
                    'status' => 'settled',
                    'metadata' => array_merge($alloc->metadata ?? [], [
                        'settled_at' => now()->toIso8601String(),
                        'period' => $periodMonth,
                        'qualifiers' => $qualifierCount,
                        'share_per_qualifier' => $baseShare,
                    ]),
                ]);
            }

            Audit::record(null, 'commissions.salary_pool_settled', $periodMonth, [
                'total_pool' => $totalPool,
                'qualifiers' => $qualifierCount,
                'total_distributed' => $totalDistributed,
            ]);

            return [
                'status' => 'settled',
                'period' => $periodMonth,
                'total_pool' => $totalPool,
                'qualifiers_count' => $qualifierCount,
                'distributed' => $distributed,
            ];
        }, 3);
    }

    /**
     * Settle and distribute the 4% Leadership Rank Royalty Pool across MO, AM, SM, and ME tiers (1% each).
     */
    public function settleRankRoyaltyPool(string $periodMonth): array
    {
        return DB::transaction(function () use ($periodMonth): array {
            $poolAllocations = CommissionAllocation::where('category', 'rank_royalty_pool')
                ->where('status', 'pending')
                ->where('created_at', 'like', "{$periodMonth}%")
                ->lockForUpdate()
                ->get();

            $totalPool = round($poolAllocations->sum(fn($a) => (float)$a->amount), 2);
            if ($totalPool <= 0) {
                return ['status' => 'empty_pool', 'total_pool' => 0.00];
            }

            // 4% total pool is split into 4 rank buckets (1% each = 25% of pool)
            $tierPool = round($totalPool / 4, 2);
            $ranks = ['mo', 'am', 'sm', 'me'];
            $distributions = [];
            $totalDistributed = 0.00;

            foreach ($ranks as $rankCode) {
                $leaders = DealerRank::where('current_rank', $rankCode)
                    ->whereHas('user', fn($q) => $q->where('status', 'active'))
                    ->with('user')
                    ->get()
                    ->filter(function($rank) use ($rankCode) {
                        $eval = $this->evaluatePaidAsRank($rank->user_id);
                        return $eval['paid_as_rank'] === $rankCode;
                    });

                $count = $leaders->count();
                if ($count === 0) {
                    $distributions[$rankCode] = [
                        'qualifiers' => 0,
                        'allocated' => 0.00,
                        'rolled_to_reserve' => $tierPool,
                    ];
                    continue;
                }

                $share = floor(($tierPool / $count) * 100) / 100;
                $rem = round($tierPool - ($share * $count), 2);

                $tierLeaders = [];
                foreach ($leaders as $idx => $leader) {
                    $leaderShare = ($idx === 0) ? round($share + $rem, 2) : $share;
                    $this->creditWallet($leader->user_id, $leaderShare);

                    Audit::notice(
                        $leader->user_id,
                        'Rank Royalty Distributed',
                        "You received ৳{$leaderShare} from the " . strtoupper($rankCode) . " Leadership Royalty Pool for {$periodMonth}."
                    );

                    $tierLeaders[] = [
                        'user_id' => $leader->user_id,
                        'user_name' => $leader->user->name,
                        'amount' => $leaderShare,
                    ];
                    $totalDistributed += $leaderShare;
                }

                $distributions[$rankCode] = [
                    'qualifiers' => $count,
                    'allocated' => round($share * $count + $rem, 2),
                    'leaders' => $tierLeaders,
                ];
            }

            // Post double-entry journal entry
            if ($totalDistributed > 0) {
                $entryKey = "commissions.rank_royalty.{$periodMonth}";
                if (!DB::table('journal_entries')->where('event_key', $entryKey)->exists()) {
                    $entryId = (string) Str::uuid();
                    DB::table('journal_entries')->insert([
                        'id' => $entryId,
                        'event_key' => $entryKey,
                        'description' => "Leadership rank royalty distribution for period {$periodMonth}",
                        'actor_id' => null,
                        'created_at' => now(),
                    ]);

                    DB::table('journal_lines')->insert([
                        'entry_id' => $entryId,
                        'user_id' => null,
                        'account' => 'commission_expense',
                        'debit' => $totalDistributed,
                        'credit' => 0.00,
                    ]);

                    foreach ($distributions as $tierData) {
                        if (!empty($tierData['leaders'])) {
                            foreach ($tierData['leaders'] as $l) {
                                DB::table('journal_lines')->insert([
                                    'entry_id' => $entryId,
                                    'user_id' => $l['user_id'],
                                    'account' => 'dealer_wallet_payable',
                                    'debit' => 0.00,
                                    'credit' => $l['amount'],
                                ]);
                            }
                        }
                    }
                }
            }

            // Mark pool allocations as settled
            foreach ($poolAllocations as $alloc) {
                $alloc->update([
                    'status' => 'settled',
                    'metadata' => array_merge($alloc->metadata ?? [], [
                        'settled_at' => now()->toIso8601String(),
                        'period' => $periodMonth,
                        'distribution_summary' => $distributions,
                    ]),
                ]);
            }

            Audit::record(null, 'commissions.rank_royalty_settled', $periodMonth, [
                'total_pool' => $totalPool,
                'total_distributed' => $totalDistributed,
                'tiers' => array_keys($distributions),
            ]);

            return [
                'status' => 'settled',
                'period' => $periodMonth,
                'total_pool' => $totalPool,
                'total_distributed' => $totalDistributed,
                'distributions' => $distributions,
            ];
        }, 3);
    }

    /**
     * Claim and pay out the one-time leadership rank achievement incentive.
     */
    public function claimOneTimeRankIncentive(int $userId, float $customAmount = 0.00): array
    {
        return DB::transaction(function () use ($userId, $customAmount): array {
            $rank = DealerRank::where('user_id', $userId)->lockForUpdate()->firstOrFail();
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();

            if ($rank->one_time_incentive_paid) {
                return ['success' => false, 'message' => 'Incentive already claimed and paid.'];
            }

            if ($rank->current_rank === 'dealer') {
                return ['success' => false, 'message' => 'Dealer rank does not qualify for one-time rank incentive.'];
            }

            $defaultAmounts = [
                'mo' => 5000.00,
                'am' => 15000.00,
                'sm' => 50000.00,
                'me' => 200000.00,
            ];

            $amount = $customAmount > 0 ? $customAmount : ($defaultAmounts[$rank->current_rank] ?? 5000.00);

            $rank->update([
                'one_time_incentive_paid' => true,
                'one_time_incentive_paid_at' => now(),
            ]);

            $this->creditWallet($userId, $amount);

            $entryKey = "commissions.incentive.{$userId}.{$rank->current_rank}";
            if (!DB::table('journal_entries')->where('event_key', $entryKey)->exists()) {
                $entryId = (string) Str::uuid();
                DB::table('journal_entries')->insert([
                    'id' => $entryId,
                    'event_key' => $entryKey,
                    'description' => "One-time rank incentive payout for {$user->name} (" . strtoupper($rank->current_rank) . ")",
                    'actor_id' => $userId,
                    'created_at' => now(),
                ]);

                DB::table('journal_lines')->insert([
                    'entry_id' => $entryId,
                    'user_id' => null,
                    'account' => 'commission_expense',
                    'debit' => $amount,
                    'credit' => 0.00,
                ]);

                DB::table('journal_lines')->insert([
                    'entry_id' => $entryId,
                    'user_id' => $userId,
                    'account' => 'dealer_wallet_payable',
                    'debit' => 0.00,
                    'credit' => $amount,
                ]);
            }

            Audit::notice(
                $userId,
                'One-Time Rank Incentive Received',
                "Congratulations! You received your one-time incentive of ৳{$amount} for achieving " . strtoupper($rank->current_rank) . " rank."
            );

            return [
                'success' => true,
                'user_id' => $userId,
                'rank' => $rank->current_rank,
                'amount' => $amount,
                'paid_at' => now()->toIso8601String(),
            ];
        }, 3);
    }

    /**
     * Credit dealer wallet balance atomically with pessimistic row locking and automated clawback debt clearance.
     */
    public function creditWallet(int $userId, float $amount): void
    {
        if ($amount <= 0) return;

        DB::table('wallets')->insertOrIgnore([
            'user_id' => $userId,
            'available' => 0.00,
            'held' => 0.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $wallet = DB::table('wallets')
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        if ($wallet) {
            // Check if user has pending unrecovered clawback debt from prior order reversals
            $debtAllocations = CommissionAllocation::where('user_id', $userId)
                ->where('status', 'reversed')
                ->whereRaw("(metadata->>'unrecovered_deficit')::numeric > 0")
                ->get();

            $remainingCredit = $amount;
            $totalDebtCleared = 0.00;

            foreach ($debtAllocations as $debtAlloc) {
                if ($remainingCredit <= 0) break;

                $meta = $debtAlloc->metadata ?? [];
                $deficit = (float)($meta['unrecovered_deficit'] ?? 0);
                if ($deficit > 0) {
                    $offset = min($remainingCredit, $deficit);
                    $newDeficit = round($deficit - $offset, 2);
                    $remainingCredit = round($remainingCredit - $offset, 2);
                    $totalDebtCleared += $offset;

                    $meta['unrecovered_deficit'] = $newDeficit;
                    $debtAlloc->update(['metadata' => $meta]);
                }
            }

            if ($totalDebtCleared > 0) {
                Audit::notice(
                    $userId,
                    'Clawback Debt Cleared',
                    "৳{$totalDebtCleared} of your commission was automatically applied to settle prior refund clawback debt."
                );
            }

            $newAvailable = round((float)$wallet->available + $remainingCredit, 2);
            DB::table('wallets')
                ->where('user_id', $userId)
                ->update([
                    'available' => $newAvailable,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Reserve funds into dealer wallet held balance atomically.
     */
    public function holdWallet(int $userId, float $amount): void
    {
        if ($amount <= 0) return;

        DB::table('wallets')->insertOrIgnore([
            'user_id' => $userId,
            'available' => 0.00,
            'held' => 0.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('wallets')
            ->where('user_id', $userId)
            ->increment('held', $amount);
    }

    /**
     * Release matured commissions whose cooling-off period has elapsed.
     */
    public function releaseMaturedCommissions(): array
    {
        return DB::transaction(function (): array {
            $now = now();
            $maturingAllocations = CommissionAllocation::where('status', 'maturing')
                ->whereNotNull('user_id')
                ->where(function ($q) use ($now) {
                    $q->whereNull('metadata->maturation_due_at')
                      ->orWhere('metadata->maturation_due_at', '<=', $now->toIso8601String());
                })
                ->lockForUpdate()
                ->get();

            if ($maturingAllocations->isEmpty()) {
                return ['status' => 'no_matured_commissions', 'released_count' => 0, 'total_amount' => 0.00];
            }

            $releasedCount = 0;
            $totalReleased = 0.00;

            foreach ($maturingAllocations as $alloc) {
                $amount = (float) $alloc->amount;
                $userId = $alloc->user_id;

                if ($amount <= 0 || !$userId) {
                    continue;
                }

                $wallet = DB::table('wallets')->where('user_id', $userId)->lockForUpdate()->first();
                if ($wallet) {
                    $currentHeld = (float) $wallet->held;
                    $deductFromHeld = min($currentHeld, $amount);
                    $newHeld = max(0.00, round($currentHeld - $deductFromHeld, 2));

                    DB::table('wallets')->where('user_id', $userId)->update([
                        'held' => $newHeld,
                        'updated_at' => now(),
                    ]);

                    $this->creditWallet($userId, $amount);

                    $alloc->update([
                        'status' => 'available',
                        'metadata' => array_merge($alloc->metadata ?? [], [
                            'matured_at' => now()->toIso8601String(),
                            'maturation_released' => true,
                        ]),
                    ]);

                    $entryKey = "commissions.matured.{$alloc->id}";
                    if (!DB::table('journal_entries')->where('event_key', $entryKey)->exists()) {
                        $entryId = (string) Str::uuid();
                        DB::table('journal_entries')->insert([
                            'id' => $entryId,
                            'event_key' => $entryKey,
                            'description' => "Maturation release for commission allocation #{$alloc->id}",
                            'actor_id' => $userId,
                            'created_at' => now(),
                        ]);

                        DB::table('journal_lines')->insert([
                            'entry_id' => $entryId,
                            'user_id' => $userId,
                            'account' => 'dealer_wallet_held',
                            'debit' => $amount,
                            'credit' => 0.00,
                        ]);

                        DB::table('journal_lines')->insert([
                            'entry_id' => $entryId,
                            'user_id' => $userId,
                            'account' => 'dealer_wallet_payable',
                            'debit' => 0.00,
                            'credit' => $amount,
                        ]);
                    }

                    Audit::notice(
                        $userId,
                        'Commission Matured & Released',
                        "৳{$amount} from order #{$alloc->order_id} has cleared the cooling-off period and is now available in your wallet."
                    );

                    $releasedCount++;
                    $totalReleased += $amount;
                }
            }

            return [
                'status' => 'success',
                'released_count' => $releasedCount,
                'total_amount' => round($totalReleased, 2),
            ];
        }, 3);
    }

    /**
     * Evaluate a dealer's current Paid-As rank based on monthly volume and grace period.
     */
    public function evaluatePaidAsRank(int $userId): array
    {
        $rank = DealerRank::where('user_id', $userId)->first();
        if (!$rank || $rank->current_rank === 'dealer') {
            return [
                'lifetime_rank' => 'dealer',
                'paid_as_rank' => 'dealer',
                'monthly_volume' => (float)($rank?->monthly_purchase_total ?? 0),
                'required_volume' => 0.00,
                'is_qualified' => true,
                'in_grace_period' => false,
                'status' => 'active',
            ];
        }

        $maintenanceRule = CommissionRuleParameter::getRule('rank_maintenance');
        $params = $maintenanceRule ? ($maintenanceRule->parameters ?? []) : [];
        $graceDays = (int)($params['grace_period_days'] ?? 30);

        $thresholds = [
            'mo' => (float)($params['mo_min_monthly_volume'] ?? 5000.00),
            'am' => (float)($params['am_min_monthly_volume'] ?? 15000.00),
            'sm' => (float)($params['sm_min_monthly_volume'] ?? 30000.00),
            'me' => (float)($params['me_min_monthly_volume'] ?? 50000.00),
        ];

        $required = $thresholds[$rank->current_rank] ?? 5000.00;
        $currentMonthly = (float)$rank->monthly_purchase_total;

        if ($currentMonthly >= $required) {
            return [
                'lifetime_rank' => $rank->current_rank,
                'paid_as_rank' => $rank->current_rank,
                'monthly_volume' => $currentMonthly,
                'required_volume' => $required,
                'is_qualified' => true,
                'in_grace_period' => false,
                'status' => 'qualified',
            ];
        }

        // Check if within grace period since rank achievement
        $achievedAt = $rank->achieved_at ? \Carbon\Carbon::parse($rank->achieved_at) : now();
        $daysSinceAchieved = $achievedAt->diffInDays(now());
        $inGrace = $daysSinceAchieved <= $graceDays;

        return [
            'lifetime_rank' => $rank->current_rank,
            'paid_as_rank' => $inGrace ? $rank->current_rank : 'dealer',
            'monthly_volume' => $currentMonthly,
            'required_volume' => $required,
            'is_qualified' => false,
            'in_grace_period' => $inGrace,
            'grace_days_remaining' => max(0, $graceDays - $daysSinceAchieved),
            'status' => $inGrace ? 'grace_period' : 'demoted',
        ];
    }

    /**
     * Run What-If Simulation of compensation plan parameter changes against historical order volume.
     */
    public function simulatePlan(array $parameterOverrides, ?string $dateFrom = null, ?string $dateTo = null, int $limitOrders = 100): array
    {
        $activeRules = CommissionRuleParameter::where('is_active', true)->get()->keyBy('rule_key');

        $defaultRates = [
            'own_dealer_cashback' => 5.00,
            'direct_referral' => 10.00,
            'd2d_gen2' => 2.00,
            'd2d_gen3' => 1.00,
            'area_dealer_pool' => 10.00,
            'dealer_monthly_salary' => 2.00,
            'rank_royalty' => 4.00,
            'rank_incentive' => 2.00,
            'company_marketing' => 2.00,
            'car_bike_incentive' => 2.00,
        ];

        $simulatedRates = [];
        foreach ($defaultRates as $key => $defaultVal) {
            if ($key === 'd2d_gen2') {
                $simulatedRates[$key] = isset($parameterOverrides['d2d_gen2'])
                    ? (float)$parameterOverrides['d2d_gen2']
                    : (float)($activeRules['d2d_multitier']->parameters['gen2_pct'] ?? $defaultVal);
            } elseif ($key === 'd2d_gen3') {
                $simulatedRates[$key] = isset($parameterOverrides['d2d_gen3'])
                    ? (float)$parameterOverrides['d2d_gen3']
                    : (float)($activeRules['d2d_multitier']->parameters['gen3_pct'] ?? $defaultVal);
            } else {
                $simulatedRates[$key] = isset($parameterOverrides[$key])
                    ? (float)$parameterOverrides[$key]
                    : (isset($activeRules[$key]) ? (float)$activeRules[$key]->percentage : $defaultVal);
            }
        }

        $totalProposedPct = array_sum($simulatedRates);
        $exceedsCap = $totalProposedPct > 40.0001;

        $ordersQuery = Order::whereIn('status', ['delivered', 'approved', 'submitted', 'partially_dispatched'])
            ->when($dateFrom, fn($q) => $q->where('created_at', '>=', $dateFrom))
            ->when($dateTo, fn($q) => $q->where('created_at', '<=', $dateTo))
            ->limit($limitOrders);

        $orders = $ordersQuery->get();

        $isSynthetic = false;
        if ($orders->isEmpty()) {
            $isSynthetic = true;
            $orderTotals = [1000.0, 5000.0, 10000.0, 25000.0, 50000.0, 100000.0];
        } else {
            $orderTotals = $orders->pluck('total')->map(fn($t) => (float)$t)->filter(fn($t) => $t > 0)->all();
            if (empty($orderTotals)) {
                $isSynthetic = true;
                $orderTotals = [5000.0, 15000.0, 30000.0];
            }
        }

        $totalGrossSales = array_sum($orderTotals);

        $baselineCategoryTotals = [];
        $simulatedCategoryTotals = [];

        foreach ($defaultRates as $key => $defaultVal) {
            $baselinePct = $defaultVal;
            if ($key === 'd2d_gen2') {
                $baselinePct = (float)($activeRules['d2d_multitier']->parameters['gen2_pct'] ?? 2.0);
            } elseif ($key === 'd2d_gen3') {
                $baselinePct = (float)($activeRules['d2d_multitier']->parameters['gen3_pct'] ?? 1.0);
            } elseif (isset($activeRules[$key])) {
                $baselinePct = (float)$activeRules[$key]->percentage;
            }

            $simPct = $simulatedRates[$key];

            $baselineCategoryTotals[$key] = round($totalGrossSales * ($baselinePct / 100), 2);
            $simulatedCategoryTotals[$key] = round($totalGrossSales * ($simPct / 100), 2);
        }

        $baselineTotalCommissions = array_sum($baselineCategoryTotals);
        $simulatedTotalCommissions = array_sum($simulatedCategoryTotals);

        $baselineCompanyRetention = max(0.00, round($totalGrossSales - $baselineTotalCommissions, 2));
        $simulatedCompanyRetention = max(0.00, round($totalGrossSales - $simulatedTotalCommissions, 2));

        $baselineRetentionPct = $totalGrossSales > 0 ? round(($baselineCompanyRetention / $totalGrossSales) * 100, 2) : 60.0;
        $simulatedRetentionPct = $totalGrossSales > 0 ? round(($simulatedCompanyRetention / $totalGrossSales) * 100, 2) : 60.0;

        $varianceAmount = round($simulatedTotalCommissions - $baselineTotalCommissions, 2);
        $variancePct = $baselineTotalCommissions > 0 ? round(($varianceAmount / $baselineTotalCommissions) * 100, 2) : 0.0;

        $slices = [];
        $categoryLabels = [
            'own_dealer_cashback' => 'Own Dealer Cashback',
            'direct_referral' => 'Direct Referral (Gen 1)',
            'd2d_gen2' => 'Generation 2 Override',
            'd2d_gen3' => 'Generation 3 Override',
            'area_dealer_pool' => 'Area Dealer 5-Tier Pool',
            'dealer_monthly_salary' => 'Dealer Monthly Salary Pool',
            'rank_royalty' => 'Leadership Rank Royalty Pool',
            'rank_incentive' => 'One-Time Rank Incentive Pool',
            'company_marketing' => 'Company Marketing Reserve',
            'car_bike_incentive' => 'Car & Bike Incentive Fund',
        ];

        foreach ($defaultRates as $key => $defaultVal) {
            $bAmt = $baselineCategoryTotals[$key] ?? 0.00;
            $sAmt = $simulatedCategoryTotals[$key] ?? 0.00;
            $delta = round($sAmt - $bAmt, 2);

            $slices[] = [
                'key' => $key,
                'name' => $categoryLabels[$key] ?? $key,
                'baseline_pct' => $defaultVal,
                'simulated_pct' => $simulatedRates[$key],
                'baseline_amount' => $bAmt,
                'simulated_amount' => $sAmt,
                'delta_amount' => $delta,
            ];
        }

        $isSafe = !$exceedsCap && $simulatedRetentionPct >= 60.00;

        return [
            'is_synthetic' => $isSynthetic,
            'is_synthetic_benchmark' => $isSynthetic,
            'orders_evaluated_count' => count($orderTotals),
            'orders_analyzed_count' => count($orderTotals),
            'total_gross_sales' => $totalGrossSales,
            'gross_sales_tested' => $totalGrossSales,
            'proposed_pool_sum_pct' => round($totalProposedPct, 2),
            'total_proposed_pct' => round($totalProposedPct, 2),
            'exceeds_cap' => $exceedsCap,
            'is_safe' => $isSafe,
            'baseline' => [
                'total_commissions' => $baselineTotalCommissions,
                'company_retention' => $baselineCompanyRetention,
                'retention_pct' => $baselineRetentionPct,
            ],
            'simulated' => [
                'total_commissions' => $simulatedTotalCommissions,
                'company_retention' => $simulatedCompanyRetention,
                'retention_pct' => $simulatedRetentionPct,
            ],
            'variance' => [
                'amount' => $varianceAmount,
                'pct' => $variancePct,
            ],
            'slices' => $slices,
            'slices_comparison' => $slices,
            'guardrail_alerts' => $exceedsCap ? [
                "CRITICAL: Proposed commission pool sum ({$totalProposedPct}%) exceeds the 40.00% zero-deficit ceiling!"
            ] : [
                "Zero-deficit budget ceiling compliant ({$totalProposedPct}% <= 40.00%). Company retains {$simulatedRetentionPct}% of gross collections."
            ],
        ];
    }

    /**
     * Calculate Tax Deducted at Source (TDS) for commission withdrawals.
     */
    public function calculatePayoutTds(float $amount, ?float $customTdsPct = null): array
    {
        $tdsRule = CommissionRuleParameter::getRule('payout_tax_withholding');
        $pct = $customTdsPct !== null ? $customTdsPct : ($tdsRule ? (float)$tdsRule->percentage : 5.00);
        $tax = round($amount * ($pct / 100), 2);
        $net = round($amount - $tax, 2);

        return [
            'gross_amount' => $amount,
            'tds_pct' => $pct,
            'tds_amount' => $tax,
            'net_amount' => $net,
        ];
    }

    /**
     * Update buyer's personal cumulative volume and check for MO promotion.
     */
    public function updateRankVolume(int $userId, float $cashAmount): void
    {
        $rank = DealerRank::firstOrCreate(
            ['user_id' => $userId],
            [
                'current_rank' => 'dealer',
                'lifetime_purchase_total' => 0.00,
                'monthly_purchase_total' => 0.00,
            ]
        );

        $rank->increment('lifetime_purchase_total', $cashAmount);
        $rank->increment('monthly_purchase_total', $cashAmount);
        $rank->refresh();

        // Check Marketing Officer (MO) promotion: ৳2,50,000 lifetime purchase
        if ($rank->current_rank === 'dealer' && $rank->lifetime_purchase_total >= 250000.00) {
            $rank->update([
                'current_rank' => 'mo',
                'achieved_at' => now(),
            ]);
            Audit::notice($userId, 'Rank Achieved: Marketing Officer (MO)', 'Congratulations! You reached Marketing Officer status with ৳2,50,000 personal purchases.');
            
            // Check uplines for Area Manager (AM) promotion
            $this->checkUplinesForAmPromotion($userId);
        }
    }

    /**
     * Check if uplines have 10 MOs in 3 generations to unlock Area Manager (AM).
     */
    public function checkUplinesForAmPromotion(int $newMoUserId): void
    {
        $uplines = ReferralTreePath::where('descendant_id', $newMoUserId)
            ->where('depth', '>', 0)
            ->where('depth', '<=', 3)
            ->get();

        foreach ($uplines as $u) {
            $ancestorId = $u->ancestor_id;
            $ancestorRank = DealerRank::where('user_id', $ancestorId)->first();
            if ($ancestorRank && $ancestorRank->current_rank === 'mo') {
                // Count MOs in ancestor's 3 generations
                $moCount = DB::table('referral_tree_paths as p')
                    ->join('dealer_ranks as r', 'r.user_id', '=', 'p.descendant_id')
                    ->where('p.ancestor_id', $ancestorId)
                    ->whereBetween('p.depth', [1, 3])
                    ->whereIn('r.current_rank', ['mo', 'am', 'sm', 'me'])
                    ->distinct('p.descendant_id')
                    ->count();

                if ($moCount >= 10) {
                    $ancestorRank->update([
                        'current_rank' => 'am',
                        'achieved_at' => now(),
                    ]);
                    Audit::notice($ancestorId, 'Rank Achieved: Area Manager (AM)', "Congratulations! You promoted to Area Manager with {$moCount} MO leaders in your 3 generations.");
                }
            }
        }
    }

    /**
     * Update Car & Bike Milestone progress for all ancestors in referral tree.
     */
    public function updateCarBikeMilestonesForAncestors(int $buyerId, float $cashAmount): void
    {
        $ancestors = ReferralTreePath::where('descendant_id', $buyerId)
            ->where('depth', '>', 0)
            ->get();

        foreach ($ancestors as $a) {
            $this->recalculateCarBikeForUser($a->ancestor_id);
        }
    }

    /**
     * Recalculate Car & Bike progress with the Anti-Whale Maximum Leg Capping Rule.
     */
    public function recalculateCarBikeForUser(int $userId): array
    {
        $bikeData = $this->evaluateAntiWhaleLegs($userId, 'bike');
        $carData = $this->evaluateAntiWhaleLegs($userId, 'car');

        // Update Bike progress
        RewardMilestoneProgress::updateOrCreate(
            ['user_id' => $userId, 'milestone_type' => 'bike'],
            [
                'target_amount' => $bikeData['target_amount'],
                'leg_cap_amount' => $bikeData['leg_cap_amount'],
                'raw_team_volume' => $bikeData['raw_team_volume'],
                'qualifying_volume' => $bikeData['qualifying_volume'],
                'achieved' => $bikeData['achieved'],
                'achieved_at' => $bikeData['achieved'] ? now() : null,
                'leg_breakdown' => $bikeData['legs'],
            ]
        );

        // Update Car progress
        RewardMilestoneProgress::updateOrCreate(
            ['user_id' => $userId, 'milestone_type' => 'car'],
            [
                'target_amount' => $carData['target_amount'],
                'leg_cap_amount' => $carData['leg_cap_amount'],
                'raw_team_volume' => $carData['raw_team_volume'],
                'qualifying_volume' => $carData['qualifying_volume'],
                'achieved' => $carData['achieved'],
                'achieved_at' => $carData['achieved'] ? now() : null,
                'leg_breakdown' => $carData['legs'],
            ]
        );

        return ['bike' => $bikeData, 'car' => $carData];
    }

    /**
     * Evaluate anti-whale leg contributions for a specific milestone.
     */
    public function evaluateAntiWhaleLegs(int $userId, string $type): array
    {
        $rule = CommissionRuleParameter::getRule('car_bike_incentive');
        $params = $rule ? ($rule->parameters ?? []) : [];

        $target = $type === 'bike'
            ? (float)($params['bike_target_amount'] ?? 6000000.00)
            : (float)($params['car_target_amount'] ?? 250000000.00);

        $cap = $type === 'bike'
            ? (float)($params['bike_leg_cap_amount'] ?? 800000.00)
            : (float)($params['car_leg_cap_amount'] ?? 20000000.00);

        // Find all direct children (Gen 1 frontline branches)
        $frontlines = ReferralTreePath::where('ancestor_id', $userId)
            ->where('depth', 1)
            ->with('descendant')
            ->get();

        $legs = [];
        $rawTotal = 0.00;
        $qualifyingTotal = 0.00;

        foreach ($frontlines as $fl) {
            $childId = $fl->descendant_id;
            $childUser = $fl->descendant;

            // Total volume of all descendants in this child's sub-tree (infinite depth, strictly delivered orders)
            $branchVolume = (float) DB::table('orders as o')
                ->join('referral_tree_paths as p', 'p.descendant_id', '=', 'o.user_id')
                ->where('p.ancestor_id', $childId)
                ->where('o.status', 'delivered')
                ->sum('o.total');

            $totalLegVolume = $branchVolume; // Closure table depth 0 includes child's own purchases
            $cappedVolume = min($totalLegVolume, $cap);
            $rawTotal += $totalLegVolume;
            $qualifyingTotal += $cappedVolume;

            $legs[] = [
                'dealer_id' => $childId,
                'dealer_name' => $childUser ? $childUser->name : "Dealer #{$childId}",
                'dealer_code' => $childUser ? $childUser->dealer_code : null,
                'raw_volume' => $totalLegVolume,
                'qualifying_volume' => $cappedVolume,
                'is_capped' => $totalLegVolume > $cap,
                'excess_volume' => max(0, $totalLegVolume - $cap),
                'cap_limit' => $cap,
            ];
        }

        // Sort legs descending by volume
        usort($legs, fn($a, $b) => $b['raw_volume'] <=> $a['raw_volume']);

        return [
            'type' => $type,
            'target_amount' => $target,
            'leg_cap_amount' => $cap,
            'raw_team_volume' => $rawTotal,
            'qualifying_volume' => $qualifyingTotal,
            'achieved' => $qualifyingTotal >= $target,
            'percentage' => $target > 0 ? min(100.0, round(($qualifyingTotal / $target) * 100, 2)) : 0,
            'legs' => $legs,
        ];
    }
}
