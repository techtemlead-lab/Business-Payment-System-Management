<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('linked_staff_role', 80)->nullable()->after('role');
            $table->json('linked_staff_permissions')->nullable()->after('linked_staff_role');
            $table->timestamp('staff_linked_at')->nullable()->after('linked_staff_permissions');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['linked_staff_role', 'linked_staff_permissions', 'staff_linked_at']);
        });
    }
};
