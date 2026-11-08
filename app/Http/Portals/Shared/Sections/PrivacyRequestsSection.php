<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Clinic\PrescriptionAccess;
use App\Domain\Security\PrivacyRequests;
use App\Models\PrivacyRequest;

/**
 * The owners' queue of partners' data requests, and a partner's own requests on the security
 * page. The queue holds account details and request status only.
 */
final class PrivacyRequestsSection implements WorkspaceSection
{
    public function __construct(private PrivacyRequests $requests) {}

    public function data(SectionContext $context, array $data): array
    {
        if ($context->section === 'privacy-requests') {
            abort_unless($context->portal === 'sadmin' && PrivacyRequests::canResolve($context->user), 403);

            return ['privacy_queue' => [
                'rows' => $this->requests->queue(),
                'statuses' => PrivacyRequest::ResolutionStatuses,
            ]];
        }
        if ($context->section === 'security' && PrescriptionAccess::isPartner($context->user)) {
            return ['privacy_requests' => [
                'kinds' => PrivacyRequest::Kinds,
                'mine' => $this->requests->ownRequests($context->user),
            ]];
        }

        return [];
    }
}
