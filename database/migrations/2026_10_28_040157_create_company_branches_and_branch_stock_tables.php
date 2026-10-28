<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_branches', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 150);
            $table->string('division', 80)->nullable();
            $table->string('district', 80)->nullable();
            $table->string('upazila', 80)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->foreignId('place_code_id')->nullable()->constrained('place_codes')->nullOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_delivery_point')->default(true);
            $table->boolean('is_stock_hub')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'is_delivery_point']);
            $table->index(['division', 'district']);
        });

        Schema::create('branch_product_stocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('company_branches')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->integer('low_stock_at')->default(10);
            $table->timestamps();
            $table->unique(['branch_id', 'product_id']);
        });

        Schema::create('branch_staff_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('company_branches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('duty', 40)->default('storekeeper');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unique(['branch_id', 'user_id']);
            $table->index(['user_id', 'is_primary']);
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->after('product_id')->constrained('company_branches')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('active_branch_id')->nullable()->after('status')->constrained('company_branches')->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('fulfillment_branch_id')->nullable()->after('status')->constrained('company_branches')->nullOnDelete();
        });

        Schema::table('shipments', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->after('order_id')->constrained('company_branches')->nullOnDelete();
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE branch_product_stocks ADD CONSTRAINT branch_stock_nonnegative CHECK (quantity >= 0)');
        }

        $now = now();
        $headOfficeId = DB::table('company_branches')->insertGetId([
            'code' => 'HO-PIRGANJ',
            'name' => 'Head Office · Pirganj',
            'division' => 'Rangpur',
            'district' => 'Rangpur',
            'upazila' => 'Pirganj',
            'address' => 'Thana Road, Pirganj 5470, Rangpur',
            'phone' => '+8801730532966',
            'is_active' => true,
            'is_delivery_point' => true,
            'is_stock_hub' => true,
            'notes' => 'Seeded default hub for existing company-wide stock.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $products = DB::table('products')->select('id', 'stock', 'low_stock_at')->get();
        foreach ($products as $product) {
            DB::table('branch_product_stocks')->insert([
                'branch_id' => $headOfficeId,
                'product_id' => $product->id,
                'quantity' => max(0, (int) $product->stock),
                'low_stock_at' => max(0, (int) $product->low_stock_at),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('branch_id');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fulfillment_branch_id');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('active_branch_id');
        });
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('branch_id');
        });
        Schema::dropIfExists('branch_staff_assignments');
        Schema::dropIfExists('branch_product_stocks');
        Schema::dropIfExists('company_branches');
    }
};
