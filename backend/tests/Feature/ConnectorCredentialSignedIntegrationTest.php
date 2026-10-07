<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Services\ConnectorCredentialLifecycle;
use App\Services\ConnectorHmacVerifier;
use App\Services\ConnectorRequestCanonicalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ConnectorCredentialSignedIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_issued_and_rotated_credentials_authenticate_through_signed_middleware_and_overlap_expires(): void
    {
        $organization = Organization::factory()->create();
        $site = Site::factory()->create(['organization_id' => $organization->id]);
        $connection = SiteConnection::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $lifecycle = app(ConnectorCredentialLifecycle::class);
        $first = $lifecycle->issueInitial($connection);

        Route::middleware('connector.signed')->get('/api/v1/_test/credential-lifecycle', fn () => response()->json(['authenticated' => true]));
        $this->get('/api/v1/_test/credential-lifecycle', $this->headers($first['credential']->id, base64_decode($first['secret'])))->assertOk();

        $rotated = $lifecycle->rotate($first['credential']->id, 1);
        $this->get('/api/v1/_test/credential-lifecycle', $this->headers($rotated['credential']->id, base64_decode($rotated['secret'])))->assertOk();
        $this->get('/api/v1/_test/credential-lifecycle', $this->headers($first['credential']->id, base64_decode($first['secret'])))->assertOk();

        $this->travelTo($rotated['credential']->issued_at->copy()->addDay());
        $this->get('/api/v1/_test/credential-lifecycle', $this->headers($first['credential']->id, base64_decode($first['secret'])))
            ->assertUnauthorized()->assertJsonPath('error.code', 'connector_unauthorized');
    }

    /** @return array<string, string> */
    private function headers(string $credentialId, string $secret): array
    {
        $timestamp = (string) now()->timestamp;
        $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $url = '/api/v1/_test/credential-lifecycle';
        $request = Request::create($url, 'GET');
        $canonical = app(ConnectorRequestCanonicalizer::class)->canonicalize($request, $timestamp, $nonce);
        $signature = app(ConnectorHmacVerifier::class)->sign($secret, $canonical);

        return [
            'Authorization' => 'SP-HMAC '.$credentialId,
            'X-SP-Credential-Id' => $credentialId,
            'X-SP-Timestamp' => $timestamp,
            'X-SP-Nonce' => $nonce,
            'X-SP-Signature' => $signature,
        ];
    }
}
