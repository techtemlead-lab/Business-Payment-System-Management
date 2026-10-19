<?php

namespace App\Http\Portals\Sadmin;

use App\Domain\Pricing\ProductPricing;
use App\Http\Portals\Shared\WorkspaceController;
use App\Models\Order;
use Illuminate\Http\Request;
use Inertia\Response;

class DashboardController
{
    public function __construct(private WorkspaceController $workspace) {}

    public function index(Request $request, ProductPricing $pricing, string $section = 'overview'): Response
    {
        return $this->workspace->index($request, $pricing, $section);
    }

    public function show(Request $request, Order $order): Response
    {
        return $this->workspace->show($request, $order);
    }

    public function createAccount(Request $request): Response
    {
        return $this->workspace->createAccount($request);
    }
}
