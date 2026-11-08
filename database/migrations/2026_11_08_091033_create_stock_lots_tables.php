<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lot-level stock. Existing branch stock becomes one "opening" lot per product and
     * branch, carrying the product's current batch and expiry. The statement runs while
     * stock writes wait on the table lock, and the migration stops and rolls back if the
     * lots do not add up to the branch totals, so no total can change.
     */
    public function up(): void
    {
        Schema::create('stock_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('company_branches')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('batch_number', 60)->nullable();
            $table->date('expires_on')->nullable();
            $table->integer('quantity')->default(0);
            $table->integer('received_quantity');
            $table->date('received_on');
            $table->decimal('unit_cost', 14, 2)->nullable();
            $table->string('source', 32);
            $table->string('reference')->nullable();
            $table->foreignId('parent_lot_id')->nullable()->constrained('stock_lots')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('near_expiry_noticed_at')->nullable();
            $table->timestamp('expired_noticed_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'product_id', 'expires_on']);
            $table->index('batch_number');
            $table->index('expires_on');
        });

        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('source_branch_id')->constrained('company_branches')->restrictOnDelete();
            $table->foreignId('destination_branch_id')->constrained('company_branches')->restrictOnDelete();
            $table->integer('quantity');
            $table->string('status', 20)->default('requested')->index();
            $table->string('note', 500)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_lot_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lot_id')->constrained('stock_lots')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('company_branches')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->integer('quantity');
            $table->string('kind', 32);
            $table->string('reference')->nullable();
            $table->foreignUuid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained('order_lines')->nullOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->foreignId('prescription_id')->nullable()->constrained('prescriptions')->nullOnDelete();
            $table->foreignId('stock_transfer_id')->nullable()->constrained('stock_transfers')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['lot_id', 'kind']);
            $table->index('order_line_id');
            $table->index('shipment_id');
            $table->index('stock_transfer_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_lots ADD CONSTRAINT stock_lots_quantity_nonnegative CHECK (quantity >= 0)');
            DB::statement('LOCK TABLE branch_product_stocks IN SHARE ROW EXCLUSIVE MODE');
        }

        $now = now();
        DB::table('branch_product_stocks as stock')
            ->join('products', 'products.id', '=', 'stock.product_id')
            ->where('stock.quantity', '>', 0)
            ->orderBy('stock.id')
            ->select('stock.branch_id', 'stock.product_id', 'stock.quantity', 'stock.created_at', 'products.batch_number', 'products.expires_on', 'products.cost_price')
            ->lazy(500, 'stock.id')
            ->each(function (object $row) use ($now): void {
                $reference = 'opening:'.$row->branch_id.':'.$row->product_id;
                $lotId = DB::table('stock_lots')->insertGetId([
                    'branch_id' => $row->branch_id,
                    'product_id' => $row->product_id,
                    'batch_number' => $row->batch_number,
                    'expires_on' => $row->expires_on,
                    'quantity' => $row->quantity,
                    'received_quantity' => $row->quantity,
                    'received_on' => $row->created_at !== null ? substr((string) $row->created_at, 0, 10) : $now->toDateString(),
                    'unit_cost' => $row->cost_price,
                    'source' => 'opening',
                    'reference' => $reference,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('stock_lot_movements')->insert([
                    'lot_id' => $lotId,
                    'branch_id' => $row->branch_id,
                    'product_id' => $row->product_id,
                    'quantity' => $row->quantity,
                    'kind' => 'opening',
                    'reference' => $reference,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

        $lots = (int) DB::table('stock_lots')->sum('quantity');
        $branchTotals = (int) DB::table('branch_product_stocks')->where('quantity', '>', 0)->sum('quantity');
        if ($lots !== $branchTotals) {
            throw new RuntimeException("Opening lots ({$lots}) do not match branch stock ({$branchTotals}).");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_lot_movements');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_lots');
    }
};
