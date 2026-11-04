<?php

namespace Tests\Unit;

use App\Support\VolumeHistoryPlan;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class VolumeHistoryPlanTest extends TestCase
{
    public function test_plan_covers_branches_partners_products_and_invoice_total(): void
    {
        $this->assertSame(15, VolumeHistoryPlan::BRANCHES);
        $this->assertGreaterThanOrEqual(70, VolumeHistoryPlan::PRODUCTS);
        $this->assertGreaterThanOrEqual(100, VolumeHistoryPlan::ORDERS_PER_PARTNER);
        $this->assertGreaterThanOrEqual(50_000, VolumeHistoryPlan::INVOICE_TAKA);
        $this->assertGreaterThan(100_000_000_000, VolumeHistoryPlan::invoiceTotalTaka());

        $dealers = VolumeHistoryPlan::dealersPerBranch();
        $this->assertCount(15, $dealers);
        $this->assertSame(VolumeHistoryPlan::dealerCount(), array_sum($dealers));
        foreach ($dealers as $count) {
            $this->assertGreaterThanOrEqual(50, $count);
        }
        $this->assertSame(15 * 50, VolumeHistoryPlan::subDealerCount());
        $this->assertCount(15, VolumeHistoryPlan::branches());

        $period = (new DateTimeImmutable(VolumeHistoryPlan::PERIOD_START))
            ->diff(new DateTimeImmutable(VolumeHistoryPlan::PERIOD_END));
        $months = ($period->y * 12) + $period->m;
        $this->assertGreaterThanOrEqual(3, $months);
        $this->assertLessThanOrEqual(8, $months);
    }

    public function test_rank_tree_can_qualify_leadership_and_a_car_reward(): void
    {
        $tree = VolumeHistoryPlan::dealerTree();
        $children = array_fill(0, count($tree), 0);
        $ranks = [];
        foreach ($tree as $slot => $node) {
            $ranks[$node['rank']] = ($ranks[$node['rank']] ?? 0) + 1;
            if ($node['parent'] !== null) {
                $children[$node['parent']]++;
            }
        }

        $this->assertSame(1, $ranks['me']);
        $this->assertSame(10, $ranks['sm']);
        $this->assertSame(100, $ranks['am']);
        $this->assertSame(13, $children[0]);

        $salesManagers = [];
        foreach ($tree as $slot => $node) {
            if ($node['parent'] === 0 && $node['rank'] === 'sm') {
                $salesManagers[] = $slot;
                $this->assertSame(10, $children[$slot]);
            }
        }
        $this->assertCount(10, $salesManagers);
        foreach ($tree as $slot => $node) {
            if ($node['rank'] === 'am') {
                $this->assertSame(10, $children[$slot]);
            }
        }

        $sponsors = VolumeHistoryPlan::subDealerSponsors();
        $this->assertCount(750, $sponsors);
        $branchOf = VolumeHistoryPlan::branchIndexByDealer();
        $perBranch = array_fill(0, 15, 0);
        foreach ($sponsors as $slot) {
            $perBranch[$branchOf[$slot]]++;
        }
        $this->assertSame(array_fill(0, 15, 50), $perBranch);
    }
}
