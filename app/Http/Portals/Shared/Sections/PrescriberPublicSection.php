<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Clinic\ConsultationRequests;
use App\Domain\Clinic\PrescriberDirectory;
use App\Domain\Clinic\PrescriptionAccess;
use App\Models\ConsultationRequest;
use App\Support\PortalHost;
use Illuminate\Support\Arr;

/**
 * A prescriber's public-listing setting and the requests sent to them. Never another
 * prescriber's requests, even for the owner-doctor.
 */
final class PrescriberPublicSection implements WorkspaceSection
{
    private const RequestsPerPage = 50;

    public function __construct(private PrescriberDirectory $directory, private ConsultationRequests $requests) {}

    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'prescriptions') {
            return [];
        }
        $user = $context->user;
        abort_unless(PrescriptionAccess::canPrescribe($user), 403);
        $requestPage = ConsultationRequest::query()
            ->visibleTo($user)
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [ConsultationRequest::StatusNew])
            ->orderByDesc('id')
            ->paginate(self::RequestsPerPage, ['*'], 'requests_page')
            ->withQueryString()
            ->appends(['tab' => 'requests']);

        return ['prescriber_public' => [
            'listing' => $this->directory->listingFor($user),
            'public_url' => PortalHost::url('public', '/prescribers'),
            'new_requests' => ConsultationRequest::query()->visibleTo($user)->where('status', ConsultationRequest::StatusNew)->count(),
            'requests' => collect($requestPage->items())->map(fn (ConsultationRequest $row): array => $this->requests->row($row))->values()->all(),
            'requests_page' => Arr::except($requestPage->toArray(), 'data'),
        ]];
    }
}
