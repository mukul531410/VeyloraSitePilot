<?php

namespace Tests\Feature;

use App\Models\ConnectorCredential;
use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Services\ConnectorCredentialLifecycle;
use App\Services\ConnectorRequestNonceStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConnectorCredentialMySqlConcurrencyTest extends TestCase
{
    public function test_mysql_concurrent_rotation_serializes_version_and_primary_and_nonce_scope(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            self::markTestSkipped('Run this integration test with DB_CONNECTION=mysql.');
        }

        if (! Schema::hasTable('migrations')) {
            Artisan::call('migrate');
        }

        $organization = Organization::factory()->create();
        $site = Site::factory()->create(['organization_id' => $organization->id]);
        $connection = SiteConnection::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $issued = app(ConnectorCredentialLifecycle::class)->issueInitial($connection);
        $initialConnection = SiteConnection::factory()->create(['site_id' => $site->id, 'status' => 'active']);

        try {
            $issueCode = "try { app(\\App\\Services\\ConnectorCredentialLifecycle::class)->issueInitial(\\App\\Models\\SiteConnection::findOrFail('{$initialConnection->id}')); echo 'issued'; } catch (\\App\\Exceptions\\ConnectorCredentialException \$e) { echo \$e->reason; }";
            $issueResults = $this->runTogether($issueCode);
            sort($issueResults);
            self::assertSame(['connector_unauthorized', 'issued'], $issueResults);
            self::assertSame(1, ConnectorCredential::query()->where('site_connection_id', $initialConnection->id)->count());

            $rotationCode = "try { app(\\App\\Services\\ConnectorCredentialLifecycle::class)->rotate('{$issued['credential']->id}', 1); echo 'rotated'; } catch (\\App\\Exceptions\\ConnectorCredentialException \$e) { echo \$e->reason; }";
            $rotationResults = $this->runTogether($rotationCode);
            sort($rotationResults);
            self::assertSame(['credential_version_conflict', 'rotated'], $rotationResults);
            self::assertSame(1, ConnectorCredential::query()->where('site_connection_id', $connection->id)->where('status', ConnectorCredential::STATUS_PRIMARY)->count());
            self::assertSame(2, ConnectorCredential::query()->where('site_connection_id', $connection->id)->count());

            $nonceCredential = ConnectorCredential::query()->where('site_connection_id', $connection->id)
                ->where('status', ConnectorCredential::STATUS_PRIMARY)->firstOrFail();
            $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
            $nonceCode = "var_export(app(\\App\\Services\\ConnectorRequestNonceStore::class)->reserve('{$nonceCredential->id}', '{$nonce}'));";
            $nonceResults = $this->runTogether($nonceCode);
            sort($nonceResults);
            self::assertSame(['false', 'true'], $nonceResults);
            self::assertSame(1, DB::table('connector_request_nonces')->where('credential_id', $nonceCredential->id)->count());
            self::assertTrue(app(ConnectorRequestNonceStore::class)->reserve($issued['credential']->id, $nonce));
            self::assertSame(1, DB::table('connector_request_nonces')->where('credential_id', $issued['credential']->id)->count());
        } finally {
            SiteConnection::query()->whereKey($connection->id)->delete();
            SiteConnection::query()->whereKey($initialConnection->id)->delete();
            $site->delete();
            $organization->delete();
        }
    }

    /** @return list<string> */
    private function runTogether(string $code): array
    {
        $processes = [];
        foreach ([1, 2] as $_) {
            $process = new Process([PHP_BINARY, 'artisan', 'tinker', '--execute='.$code], base_path());
            $process->start();
            $processes[] = $process;
        }

        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $results[] = trim($process->getOutput());
        }

        return $results;
    }
}
