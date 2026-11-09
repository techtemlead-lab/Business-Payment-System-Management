<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Accounts\CustomerBook;
use App\Domain\Accounts\PartnerAlerts;
use App\Domain\Accounts\PartnerNextSteps;
use App\Domain\Content\SalesKit;
use App\Domain\Orders\DatedSchemes;
use App\Domain\Orders\PartnerContests;
use App\Domain\Orders\PartnerScorecard;
use App\Domain\Orders\RepeatOrderOptions;

/**
 * Partner tools that sit on top of the existing portal pages.
 */
final class DealerToolsSection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        $isPartner = in_array($context->user->role, ['dealer', 'sub_dealer'], true) && ! $context->usingStaffWorkspace;
        $props = [];
        if ($isPartner && $context->section === 'purchase') {
            $props['repeat_orders'] = app(RepeatOrderOptions::class)->for($context->user);
            $props['running_schemes'] = app(DatedSchemes::class)->runningToday();
        }

        if ($isPartner && $context->section === 'overview') {
            $props['partner_tools'] = [
                ...app(PartnerAlerts::class)->cardFor($context->user),
                'next_steps' => app(PartnerNextSteps::class)->for($context->user),
            ];
        }

        if ($isPartner && $context->section === 'customers') {
            $props['customer_book'] = app(CustomerBook::class)->pageFor($context->user, $context->request->string('q')->toString() ?: null, $context->request->string('status')->toString() ?: null);
        }
        if ($context->section === 'schemes') {
            $props['schemes_admin'] = app(DatedSchemes::class)->forOwner($context->user);
        }
        if ($context->isStaff && $context->section === 'partner-scorecard') {
            $props['partner_scorecard'] = app(PartnerScorecard::class)->page($context->request, $context->branchScope);
        }
        if ($context->section === 'contests') {
            $props['contests'] = app(PartnerContests::class)->forViewer($context->user);
        }
        if ($context->section === 'sales-kit') {
            $props['sales_kit'] = app(SalesKit::class)->forViewer($context->user);
        }

        return $props;
    }
}
