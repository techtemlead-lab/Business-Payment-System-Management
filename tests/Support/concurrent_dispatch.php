<?php

/**
 * One side of the race in StockLotsConcurrencyTest. Runs in its own PHP process so it
 * has its own database connection, waits for the shared start time, and dispatches.
 * Usage: php concurrent_dispatch.php <actor id> <order id> <branch id> <quantity> <start time>
 */

use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $actorId, $orderId, $branchId, $quantity, $startAt] = $argv;
$actor = User::query()->findOrFail((int) $actorId);
$order = Order::query()->findOrFail($orderId);

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    app(OrderService::class)->dispatch($actor, $order, [
        'items' => [['line_id' => (int) $order->lines()->value('id'), 'quantity' => (int) $quantity]],
        'idempotency_key' => (string) Str::uuid(),
        'courier' => 'Race courier',
        'tracking' => 'RACE',
        'branch_id' => (int) $branchId,
    ]);
    echo 'sent';
} catch (ValidationException) {
    echo 'refused';
}
