<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_former_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40)->unique();
            $table->timestamps();
        });

        // Dealers closed before this rule still held their places. Closing now gives the place up.
        DB::table('area_dealer_assignments')
            ->where('is_active', true)
            ->whereIn('user_id', DB::table('users')->whereNotNull('closed_at')->select('id'))
            ->update(['is_active' => false, 'territory_key' => null, 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_former_codes');
    }
};
