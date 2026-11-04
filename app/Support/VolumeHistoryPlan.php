<?php

namespace App\Support;

use RuntimeException;

/**
 * Shape of the local volume history: enough partners, invoices, and rank
 * legs for car, bike, royalty, and salary rules to have something to qualify.
 */
final class VolumeHistoryPlan
{
    public const BRANCHES = 15;

    public const MIN_DEALERS_PER_BRANCH = 50;

    public const SUB_DEALERS_PER_BRANCH = 50;

    public const ORDERS_PER_PARTNER = 100;

    public const PRODUCTS = 70;

    public const INVOICE_TAKA = 700_000;

    public const PERIOD_START = '2026-03-03';

    public const PERIOD_END = '2026-11-03';

    public const SM_COUNT = 10;

    public const EXTRA_CAR_LEGS = 3;

    public const AM_PER_SM = 10;

    public const MO_PER_AM = 10;

    /**
     * @return list<array{parent: int|null, rank: string}>
     */
    public static function dealerTree(): array
    {
        $nodes = [];
        $add = function (?int $parent, string $rank) use (&$nodes): int {
            $nodes[] = ['parent' => $parent, 'rank' => $rank];

            return count($nodes) - 1;
        };
        $me = $add(null, 'me');
        $salesManagers = [];
        for ($index = 0; $index < self::SM_COUNT; $index++) {
            $salesManagers[] = $add($me, 'sm');
        }
        for ($index = 0; $index < self::EXTRA_CAR_LEGS; $index++) {
            $add($me, 'mo');
        }
        foreach ($salesManagers as $salesManager) {
            for ($area = 0; $area < self::AM_PER_SM; $area++) {
                $areaManager = $add($salesManager, 'am');
                for ($member = 0; $member < self::MO_PER_AM; $member++) {
                    $add($areaManager, 'mo');
                }
            }
        }

        return $nodes;
    }

    public static function dealerCount(): int
    {
        return count(self::dealerTree());
    }

    /**
     * @return list<int>
     */
    public static function dealersPerBranch(): array
    {
        $dealers = self::dealerCount();
        $base = intdiv($dealers, self::BRANCHES);
        $remainder = $dealers % self::BRANCHES;
        $counts = [];
        for ($branch = 0; $branch < self::BRANCHES; $branch++) {
            $count = $base + ($branch < $remainder ? 1 : 0);
            if ($count < self::MIN_DEALERS_PER_BRANCH) {
                throw new RuntimeException('Volume history does not give every branch 50 dealers.');
            }
            $counts[] = $count;
        }

        return $counts;
    }

    /**
     * @return list<int>
     */
    public static function branchIndexByDealer(): array
    {
        $assigned = [];
        $cursor = 0;
        foreach (self::dealersPerBranch() as $branch => $count) {
            for ($index = 0; $index < $count; $index++) {
                $assigned[$cursor] = $branch;
                $cursor++;
            }
        }

        return $assigned;
    }

    /**
     * Marketing officers whose sponsor is an area manager.
     *
     * @return list<int>
     */
    public static function leafDealerSlots(): array
    {
        $tree = self::dealerTree();
        $leaves = [];
        foreach ($tree as $slot => $node) {
            $parent = $node['parent'];
            if ($node['rank'] === 'mo' && $parent !== null && $tree[$parent]['rank'] === 'am') {
                $leaves[] = $slot;
            }
        }

        return $leaves;
    }

    /**
     * Fifty sub-dealers on every branch, each sponsored by a leaf dealer there.
     *
     * @return list<int>
     */
    public static function subDealerSponsors(): array
    {
        $branchOf = self::branchIndexByDealer();
        $leavesByBranch = array_fill(0, self::BRANCHES, []);
        foreach (self::leafDealerSlots() as $slot) {
            $leavesByBranch[$branchOf[$slot]][] = $slot;
        }
        $sponsors = [];
        for ($branch = 0; $branch < self::BRANCHES; $branch++) {
            $leaves = $leavesByBranch[$branch];
            if ($leaves === []) {
                throw new RuntimeException('Volume history branch '.$branch.' has no leaf dealer to sponsor sub-dealers.');
            }
            for ($index = 0; $index < self::SUB_DEALERS_PER_BRANCH; $index++) {
                $sponsors[] = $leaves[$index % count($leaves)];
            }
        }

        return $sponsors;
    }

    public static function subDealerCount(): int
    {
        return self::BRANCHES * self::SUB_DEALERS_PER_BRANCH;
    }

    public static function partnerCount(): int
    {
        return self::dealerCount() + self::subDealerCount();
    }

    public static function orderCount(): int
    {
        return self::partnerCount() * self::ORDERS_PER_PARTNER;
    }

    public static function invoiceTotalTaka(): int
    {
        return self::orderCount() * self::INVOICE_TAKA;
    }

    /**
     * @return list<array{code: string, name: string, division: string, district: string, existing: bool}>
     */
    public static function branches(): array
    {
        $rows = [
            ['HO-PIRGANJ', 'Head Office · Pirganj', 'Rangpur', 'Rangpur', true],
            ['VOL-BR-02', 'Dhaka Branch', 'Dhaka', 'Dhaka', false],
            ['VOL-BR-03', 'Gazipur Branch', 'Gazipur', 'Gazipur', false],
            ['VOL-BR-04', 'Narayanganj Branch', 'Narayanganj', 'Narayanganj', false],
            ['VOL-BR-05', 'Chattogram Branch', 'Chattogram', 'Chattogram', false],
            ['VOL-BR-06', 'Cumilla Branch', 'Cumilla', 'Cumilla', false],
            ['VOL-BR-07', 'Sylhet Branch', 'Sylhet', 'Sylhet', false],
            ['VOL-BR-08', 'Moulvibazar Branch', 'Moulvibazar', 'Moulvibazar', false],
            ['VOL-BR-09', 'Rajshahi Branch', 'Rajshahi', 'Rajshahi', false],
            ['VOL-BR-10', 'Bogura Branch', 'Bogura', 'Bogura', false],
            ['VOL-BR-11', 'Khulna Branch', 'Khulna', 'Khulna', false],
            ['VOL-BR-12', 'Jashore Branch', 'Jashore', 'Jashore', false],
            ['VOL-BR-13', 'Barishal Branch', 'Barishal', 'Barishal', false],
            ['VOL-BR-14', 'Mymensingh Branch', 'Mymensingh', 'Mymensingh', false],
            ['VOL-BR-15', 'Dinajpur Branch', 'Dinajpur', 'Dinajpur', false],
        ];
        $branches = [];
        foreach ($rows as $row) {
            $branches[] = [
                'code' => $row[0],
                'name' => $row[1],
                'division' => $row[2],
                'district' => $row[3],
                'existing' => $row[4],
            ];
        }

        return $branches;
    }
}
