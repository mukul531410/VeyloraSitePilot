<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Services\ConnectorRequestNonceStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConnectorNonceMySqlConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql_unique_scope_rejects_duplicate_nonce_reservations(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            self::markTestSkipped('Run this integration test with DB_CONNECTION=mysql.');
        }

        $organization = Organization::factory()->create();
        $site = Site::factory()->create(['organization_id' => $organization->id]);
        $connection = SiteConnection::factory()->create(['site_id' => $site->id]);
        $store = app(ConnectorRequestNonceStore::class);
        $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

        self::assertTrue($store->reserve($connection->id, $nonce));
        self::assertFalse($store->reserve($connection->id, $nonce));
        $this->assertDatabaseCount('connector_request_nonces', 1);
    }
}
