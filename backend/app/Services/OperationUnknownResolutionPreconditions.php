<?php

namespace App\Services;

use App\Exceptions\OperationUnknownResolutionException;
use App\Models\Operation;
use App\Models\OperationAttempt;
use App\Models\Site;
use App\Models\User;

class OperationUnknownResolutionPreconditions
{
    public function __construct(
        private OperationUnknownResolutionAuthorization $authorization,
    ) {}

    public function assess(?User $actor, Operation $operation, string $resolution, ?string $reason, ?Site $expectedSite = null): void
    {
        $this->authorization->authorize($actor, $operation, $expectedSite);

        $source = Operation::query()->with([
            'site.organization',
            'attempts',
            'result',
        ])->find($operation->getKey());

        if ($source === null) {
            throw new OperationUnknownResolutionException(OperationUnknownResolutionException::SOURCE_MISSING);
        }

        if ($source->status !== Operation::STATUS_UNKNOWN) {
            throw new OperationUnknownResolutionException(OperationUnknownResolutionException::SOURCE_NOT_UNKNOWN);
        }

        if ($source->isResolved()) {
            throw new OperationUnknownResolutionException(OperationUnknownResolutionException::ALREADY_RESOLVED);
        }

        $site = $source->site;
        $organization = $site?->organization;
        if ($site === null || $organization === null
            || (string) $site->organization_id !== (string) $organization->id) {
            throw new OperationUnknownResolutionException(OperationUnknownResolutionException::WRONG_TENANT);
        }

        if (! in_array($resolution, Operation::RESOLUTIONS, true)) {
            throw new OperationUnknownResolutionException(OperationUnknownResolutionException::INVALID_RESOLUTION);
        }

        if (trim((string) $reason) === '') {
            throw new OperationUnknownResolutionException(OperationUnknownResolutionException::MISSING_REASON);
        }

        $this->assertEvidenceExists($source);
    }

    private function assertEvidenceExists(Operation $operation): void
    {
        $hasAttempts = $operation->attempts()->exists();
        $hasResult = $operation->result()->exists();

        if (! $hasAttempts && ! $hasResult) {
            throw new OperationUnknownResolutionException(OperationUnknownResolutionException::EVIDENCE_MISSING);
        }

        $hasTimeoutEvidence = $operation->attempts()
            ->where('status', OperationAttempt::STATUS_TIMEOUT)
            ->where('retryable', false)
            ->where('error_code', OperationAttempt::ERROR_TIMEOUT)
            ->whereNotNull('connector_job_id')
            ->whereNotNull('claimed_by_connection_id')
            ->exists();

        if (! $hasTimeoutEvidence && ! $hasResult) {
            throw new OperationUnknownResolutionException(OperationUnknownResolutionException::EVIDENCE_MISSING);
        }
    }
}
