<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Orders\TopSellersBoard;

/**
 * The monthly top-sellers board: dealers and sub-dealers see the public board,
 * office staff see the full ranking under reports.
 */
final class TopSellersSection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'top-sellers') {
            return [];
        }

        $request = $context->request;
        $month = $request->filled('month') ? $request->string('month')->toString() : null;
        $board = app(TopSellersBoard::class);

        if ($context->isStaff) {
            $division = $request->filled('division') ? mb_substr($request->string('division')->toString(), 0, 100) : null;

            return ['top_sellers' => $board->forStaff($month, $division, $context->branchScope)];
        }

        abort_unless(in_array($context->user->role, ['dealer', 'sub_dealer'], true), 403);

        return ['top_sellers' => $board->forPartner($context->user, $month)];
    }
}
