<?php

/**
 * One side of the race in OrderAccountPaymentConcurrencyTest. Runs in its own PHP process so
 * it has its own database connection, waits for the shared start time, and places the order.
 * Usage: php concurrent_account_order.php <partner id> <product id> <start time>
 */

use App\Domain\Orders\OrderService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['madina.financial_activation' => true, 'madina.preview_mode' => false, 'madina.full_payment_when_ordering' => true]);

[, $partnerId, $productId, $startAt] = $argv;
$partner = User::query()->findOrFail((int) $partnerId);

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    app(OrderService::class)->create($partner, [
        'items' => [['product_id' => (int) $productId, 'quantity' => 1]],
        'idempotency_key' => (string) Str::uuid(),
        'channel' => 'Account',
    ]);
    echo 'paid';
} catch (ValidationException) {
    echo 'refused';
}
