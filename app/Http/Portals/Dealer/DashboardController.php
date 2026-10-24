<?php

namespace App\Http\Portals\Dealer;

use App\Domain\Pricing\ProductPricing;
use App\Http\Portals\Shared\WorkspaceController;
use App\Models\Order;
use Illuminate\Http\Request;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DashboardController
{
    public function __construct(private WorkspaceController $workspace) {}

    public function index(Request $request, ProductPricing $pricing, string $section = 'overview'): Response
    {
        return $this->workspace->index($request, $pricing, $section);
    }

    public function fullReportPdf(Request $request): BinaryFileResponse
    {
        return $this->workspace->fullReportPdf($request);
    }

    public function idCardPdf(Request $request): BinaryFileResponse
    {
        return $this->workspace->idCardPdf($request);
    }

    public function referralQrSvg(Request $request): \Illuminate\Http\Response
    {
        return $this->workspace->referralQrSvg($request);
    }

    public function show(Request $request, Order $order): Response
    {
        return $this->workspace->show($request, $order);
    }
}
