<?php

namespace Database\Seeders;

use App\Models\Dealer;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local') || ! config('dealer.demo_enabled')) {
            throw new \LogicException('Demo seeding is allowed only in an explicitly enabled local environment.');
        }
        $admin = User::firstOrCreate(['email' => 'admin@example.test'], ['name' => 'Alex Morgan', 'password' => bin2hex(random_bytes(32))]);
        $admin->forceFill(['role' => 'admin'])->save();
        $rows = [['Farhan Ahmed', 'farhan@example.test', 'NX-DH-001', 'Divisional', 'Dhaka', null, null, null, 'active', 'Ahmed Health Partners'],
            ['Nusrat Jahan', 'nusrat@example.test', 'NX-CT-002', 'District', 'Chattogram', 'Chattogram', null, null, 'active', 'Jahan Distribution'],
            ['Rahim Uddin', 'rahim@example.test', 'NX-RJ-003', 'Upazila / Thana', 'Rajshahi', 'Rajshahi', 'Boalia', null, 'active', 'Uddin Pharma'],
            ['Ayesha Rahman', 'ayesha@example.test', 'NX-KH-004', 'Union', 'Khulna', 'Khulna', 'Dumuria', 'Kharnia', 'pending', 'Rahman Health'],
            ['Imran Hossain', 'imran@example.test', 'NX-SY-005', 'District', 'Sylhet', 'Sylhet', null, null, 'active', 'Hossain & Partners'],
            ['Sadia Islam', 'sadia@example.test', 'NX-RP-006', 'Union', 'Rangpur', 'Rangpur', 'Mithapukur', 'Balarhat', 'pending', 'Islam Pharmacy']];
        foreach ($rows as [$name,$email,$code,$level,$division,$district,$upazila,$union,$status,$business]) {
            $u = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => bin2hex(random_bytes(32))]);
            if (! Dealer::where('user_id', $u->id)->exists()) {
                $d = new Dealer(['code' => $code, 'level' => $level, 'division' => $division, 'district' => $district, 'upazila' => $upazila, 'union_name' => $union, 'status' => $status, 'business_name' => $business]);
                $d->user_id = $u->id;
                $d->save();
            }
        }
        // No fabricated sales, earnings, ledger entries, or payouts are seeded.
    }
}
