<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Operation;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApprovalApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_owner_and_admin_can_approve_and_audit_reviewer(): void
    {
        foreach (['owner', 'admin'] as $roleKey) {
            [$approval, $reviewer] = $this->makeApproval($roleKey);
            Sanctum::actingAs($reviewer);

            $this->withHeader('X-Request-ID', 'approve-'.$roleKey)
                ->postJson('/api/v1/approvals/'.$approval->id.'/approve')
                ->assertOk()
                ->assertJsonPath('request_id', 'approve-'.$roleKey)
                ->assertJsonPath('data.approval_status', ApprovalRequest::STATUS_APPROVED)
                ->assertJsonPath('data.operation_status', Operation::STATUS_QUEUED);

            $this->assertDatabaseHas('audit_logs', [
                'action' => 'approval_granted',
                'user_id' => $reviewer->id,
                'target_id' => $approval->operation_id,
            ]);
        }
    }

    public function test_owner_and_admin_can_reject_and_audit_reviewer(): void
    {
        foreach (['owner', 'admin'] as $roleKey) {
            [$approval, $reviewer] = $this->makeApproval($roleKey);
            Sanctum::actingAs($reviewer);

            $this->postJson('/api/v1/approvals/'.$approval->id.'/reject', ['reason' => 'Not approved'])
                ->assertOk()
                ->assertJsonPath('data.approval_status', ApprovalRequest::STATUS_REJECTED)
                ->assertJsonPath('data.operation_status', Operation::STATUS_CANCELLED);

            $this->assertDatabaseHas('audit_logs', [
                'action' => 'approval_rejected',
                'user_id' => $reviewer->id,
                'target_id' => $approval->operation_id,
            ]);
        }
    }

    public function test_viewer_operator_requester_and_cross_organization_reviewers_are_denied(): void
    {
        foreach (['viewer', 'operator', 'requester', 'cross_org'] as $case) {
            [$approval, $reviewer] = $this->makeApproval($case === 'cross_org' ? 'owner' : $case);
            if ($case === 'requester') {
                $reviewer = User::findOrFail($approval->requested_by);
            } elseif ($case === 'cross_org') {
                [$reviewer] = $this->makeMember('owner');
            }
            Sanctum::actingAs($reviewer);

            $this->postJson('/api/v1/approvals/'.$approval->id.'/approve')->assertForbidden();
            $this->postJson('/api/v1/approvals/'.$approval->id.'/reject', ['reason' => 'Denied'])->assertForbidden();
            $this->assertSame(0, AuditLog::query()->count());
        }
    }

    public function test_inactive_user_membership_organization_and_site_are_denied(): void
    {
        foreach (['user', 'membership', 'organization', 'site'] as $case) {
            [$approval, $reviewer, $organization, $site, $membership] = $this->makeApproval('owner');
            match ($case) {
                'user' => $reviewer->update(['status' => 'inactive']),
                'membership' => $membership->update(['status' => 'inactive']),
                'organization' => $organization->update(['status' => 'inactive']),
                'site' => $site->update(['status' => 'inactive']),
            };
            Sanctum::actingAs($reviewer);

            $this->postJson('/api/v1/approvals/'.$approval->id.'/approve')->assertForbidden();
            $this->postJson('/api/v1/approvals/'.$approval->id.'/reject', ['reason' => 'Denied'])->assertForbidden();
        }
    }

    public function test_repeated_review_rejected_and_expired_approval_is_cancelled(): void
    {
        [$approval, $reviewer] = $this->makeApproval('owner');
        Sanctum::actingAs($reviewer);
        $uri = '/api/v1/approvals/'.$approval->id;

        $this->postJson($uri.'/approve')->assertOk();
        $this->postJson($uri.'/approve')->assertStatus(409)->assertJsonPath('error.code', 'approval_not_pending');

        [$expired, $expiredReviewer] = $this->makeApproval('owner');
        $expired->update(['expires_at' => now()->subMinute()]);
        Sanctum::actingAs($expiredReviewer);

        $this->postJson('/api/v1/approvals/'.$expired->id.'/approve')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'approval_expired');
        $this->assertSame(ApprovalRequest::STATUS_EXPIRED, $expired->fresh()->status);
        $this->assertSame(Operation::STATUS_CANCELLED, Operation::findOrFail($expired->operation_id)->status);
    }

    public function test_rejected_or_expired_approval_cannot_be_approved_or_rejected_again(): void
    {
        [$approval, $reviewer] = $this->makeApproval('owner');
        Sanctum::actingAs($reviewer);
        $uri = '/api/v1/approvals/'.$approval->id;
        $this->postJson($uri.'/reject', ['reason' => 'Declined'])->assertOk();
        $this->postJson($uri.'/approve')->assertStatus(409);
        $this->postJson($uri.'/reject', ['reason' => 'Again'])->assertStatus(409);

        [$expired, $expiredReviewer] = $this->makeApproval('owner');
        $expired->update(['expires_at' => now()->subMinute()]);
        Sanctum::actingAs($expiredReviewer);
        $this->postJson('/api/v1/approvals/'.$expired->id.'/reject', ['reason' => 'Late'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'approval_expired');
        $this->assertSame(ApprovalRequest::STATUS_EXPIRED, $expired->fresh()->status);
        $this->assertSame(Operation::STATUS_CANCELLED, Operation::findOrFail($expired->operation_id)->status);
    }

    public function test_unauthenticated_missing_approval_and_invalid_rejection_use_api_envelopes(): void
    {
        $this->postJson('/api/v1/approvals/01J00000000000000000000000/approve')->assertUnauthorized();
        [$approval, $reviewer] = $this->makeApproval('owner');
        Sanctum::actingAs($reviewer);

        $this->withHeader('X-Request-ID', 'approval-validation')
            ->postJson('/api/v1/approvals/'.$approval->id.'/reject', ['reason' => ' '])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonPath('request_id', 'approval-validation');

        $this->postJson('/api/v1/approvals/01J00000000000000000000000/approve')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    private function makeApproval(string $reviewerRole = 'owner'): array
    {
        [$reviewer, $organization, $site, $membership] = $this->makeMember($reviewerRole);
        $requester = User::factory()->create();
        $requesterRole = Role::factory()->create(['organization_id' => $organization->id, 'key' => 'operation-requester']);
        OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $requester->id,
            'role_id' => $requesterRole->id,
            'status' => 'active',
        ]);
        $operation = Operation::create([
            'site_id' => $site->id,
            'operation_type' => 'action.cache_clear',
            'target_json' => ['cache_type' => 'wordpress'],
            'status' => Operation::STATUS_PENDING_APPROVAL,
            'approval_required' => true,
            'idempotency_key' => 'approval-'.str()->ulid(),
            'requested_by' => $requester->id,
        ]);
        $approval = ApprovalRequest::create([
            'organization_id' => $organization->id,
            'site_id' => $site->id,
            'operation_id' => $operation->id,
            'status' => ApprovalRequest::STATUS_PENDING,
            'requested_by' => $requester->id,
            'reason' => 'Operation requires review',
            'expires_at' => now()->addDay(),
        ]);

        return [$approval, $reviewer, $organization, $site, $membership];
    }

    private function makeMember(string $roleKey): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $role = Role::factory()->create(['organization_id' => $organization->id, 'key' => $roleKey]);
        $membership = OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $site = Site::factory()->create(['organization_id' => $organization->id]);

        return [$user, $organization, $site, $membership];
    }
}
