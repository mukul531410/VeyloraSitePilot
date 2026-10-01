<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\OperationRecoveryChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationRecoveryChainTest extends TestCase
{
    use RefreshDatabase;

    private function makeOperation(?Operation $source = null): Operation
    {
        if ($source !== null) {
            $site = $source->site;
            $user = $source->requestedBy;
        } else {
            $user = User::factory()->create();
            $organization = Organization::factory()->create();
            $site = Site::factory()->create(['organization_id' => $organization->id]);
        }

        return Operation::create([
            'site_id' => $site->id,
            'operation_type' => 'action.cache_clear',
            'target_json' => ['cache_type' => 'wordpress'],
            'status' => Operation::STATUS_DEAD_LETTER,
            'idempotency_key' => 'recovery-chain-' . str()->ulid(),
            'max_attempts' => 1,
            'requested_by' => $user->id,
            'recovery_of_operation_id' => $source?->id,
        ]);
    }

    public function test_default_limit_and_lineage_count_include_the_original_operation(): void
    {
        $service = app(OperationRecoveryChain::class);
        $a = $this->makeOperation();
        $b = $this->makeOperation($a);
        $c = $this->makeOperation($b);

        $this->assertSame(3, $service->maximumLength());
        $this->assertSame(1, $service->length($a));
        $this->assertSame(2, $service->length($b));
        $this->assertSame(3, $service->length($c));
    }

    public function test_locked_source_guard_allows_two_successors_and_blocks_the_third(): void
    {
        $service = app(OperationRecoveryChain::class);
        $a = $this->makeOperation();
        $b = $this->makeOperation($a);
        $c = $this->makeOperation($b);

        $this->assertSame([1, true], $service->withLockedSource(
            $a,
            fn ($locked, $count) => [$count, $locked->id === $a->id]
        ));
        $this->assertSame([2, 2], $service->withLockedSource(
            $b,
            fn ($locked, $count) => [$count, $count]
        ));

        try {
            $service->withLockedSource($c, fn ($locked, $count) => $this->makeOperation($locked));
            $this->fail('A source at the configured limit must not invoke successor work.');
        } catch (\App\Exceptions\OperationRecoveryChainException $exception) {
            $this->assertSame(\App\Exceptions\OperationRecoveryChainException::LIMIT_EXCEEDED, $exception->reason);
        }

        $this->assertDatabaseMissing('operations', ['recovery_of_operation_id' => $c->id]);
        $this->assertSame(2, Operation::whereNotNull('recovery_of_operation_id')->count());
    }

    public function test_guard_reads_a_changed_configuration_value(): void
    {
        config(['sitepilot.operations.max_recovery_chain_length' => 2]);
        $service = app(OperationRecoveryChain::class);
        $a = $this->makeOperation();
        $b = $this->makeOperation($a);

        $this->assertSame(2, $service->maximumLength());
        try {
            $service->withLockedSource($b, fn ($locked, $count) => $this->fail('The callback must not run.'));
            $this->fail('A source at the configured limit must fail closed.');
        } catch (\App\Exceptions\OperationRecoveryChainException $exception) {
            $this->assertSame(\App\Exceptions\OperationRecoveryChainException::LIMIT_EXCEEDED, $exception->reason);
        }
    }

    public function test_lineage_constraint_allows_only_one_successor_per_source(): void
    {
        $source = $this->makeOperation();
        $this->makeOperation($source);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->makeOperation($source);
    }

    public function test_non_dead_letter_source_fails_closed_before_callback(): void
    {
        $source = $this->makeOperation();
        $source->update(['status' => Operation::STATUS_UNKNOWN]);

        $this->expectException(\App\Exceptions\OperationRecoveryChainException::class);
        $this->expectExceptionMessage(\App\Exceptions\OperationRecoveryChainException::SOURCE_INELIGIBLE);
        app(OperationRecoveryChain::class)->withLockedSource($source, fn () => $this->fail('The callback must not run.'));
    }

    public function test_invalid_configuration_fails_closed(): void
    {
        config(['sitepilot.operations.max_recovery_chain_length' => 0]);

        $this->expectException(\App\Exceptions\OperationRecoveryChainException::class);
        $this->expectExceptionMessage(\App\Exceptions\OperationRecoveryChainException::INVALID_CONFIGURATION);
        app(OperationRecoveryChain::class)->maximumLength();
    }

    public function test_cyclic_and_cross_site_lineage_fail_closed(): void
    {
        $a = $this->makeOperation();
        $b = $this->makeOperation($a);
        $a->update(['recovery_of_operation_id' => $b->id]);

        try {
            app(OperationRecoveryChain::class)->length($a);
            $this->fail('A cyclic lineage must fail closed.');
        } catch (\App\Exceptions\OperationRecoveryChainException $exception) {
            $this->assertSame(\App\Exceptions\OperationRecoveryChainException::INVALID_LINEAGE, $exception->reason);
        }

        $a->update(['recovery_of_operation_id' => null]);
        $otherSiteOperation = $this->makeOperation();
        $b->update(['site_id' => $otherSiteOperation->site_id]);

        $this->expectException(\App\Exceptions\OperationRecoveryChainException::class);
        $this->expectExceptionMessage(\App\Exceptions\OperationRecoveryChainException::INVALID_LINEAGE);
        app(OperationRecoveryChain::class)->length($b);
    }

    public function test_lineage_migration_uses_restrictive_delete_behavior(): void
    {
        $foreignKeys = DB::select("PRAGMA foreign_key_list('operations')");
        $lineageForeignKey = collect($foreignKeys)->first(
            fn ($foreignKey) => $foreignKey->from === 'recovery_of_operation_id'
        );

        $this->assertNotNull($lineageForeignKey);
        $this->assertSame('operations', $lineageForeignKey->table);
        $this->assertSame('id', $lineageForeignKey->to);
        $this->assertSame('RESTRICT', strtoupper($lineageForeignKey->on_delete));
    }
}
