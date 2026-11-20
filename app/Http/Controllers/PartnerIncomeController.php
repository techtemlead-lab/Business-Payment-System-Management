<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\PartnerIncomeBook;
use App\Domain\Accounts\PartnerIncomeStatementPdf;
use App\Domain\Finance\FundSpendPartners;
use App\Domain\Finance\TreasuryAccess;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A partner's income, one part at a time. A partner sees only their own; office staff who may open a
 * partner's money page can print that partner's statements too.
 */
class PartnerIncomeController extends Controller
{
    public function show(Request $request, PartnerIncomeBook $income): Response
    {
        $partner = $this->ownPartner($request);

        return Inertia::render('MyIncome', [
            'section' => 'my-money',
            'income' => $income->page($request, $partner),
            'benefits' => app(FundSpendPartners::class)->linesFor($partner),
        ]);
    }

    public function pdf(Request $request, PartnerIncomeStatementPdf $pdf, string $part): BinaryFileResponse
    {
        return $this->file($request, $pdf, $this->ownPartner($request), $part);
    }

    public function pdfFor(Request $request, PartnerIncomeStatementPdf $pdf, User $partner, string $part): BinaryFileResponse
    {
        abort_unless(TreasuryAccess::mayOpenPartner($request->user()), 403);
        abort_unless(in_array($partner->role, ['dealer', 'sub_dealer'], true), 404);

        return $this->file($request, $pdf, $partner, $part);
    }

    private function file(Request $request, PartnerIncomeStatementPdf $pdf, User $partner, string $part): BinaryFileResponse
    {
        abort_unless(in_array($part, PartnerIncomeBook::parts(), true), 404);
        Audit::record($request->user()->id, 'report.exported', 'partner-income-statement', ['partner_id' => $partner->id, 'part' => $part]);

        return $pdf->download($request, $partner, $part);
    }

    private function ownPartner(Request $request): User
    {
        $partner = $request->user();
        abort_unless(in_array($partner->role, ['dealer', 'sub_dealer'], true), 403);

        return $partner;
    }
}
