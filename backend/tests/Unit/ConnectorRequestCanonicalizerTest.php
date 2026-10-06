<?php

namespace Tests\Unit;

use App\Services\ConnectorRequestCanonicalizer;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class ConnectorRequestCanonicalizerTest extends TestCase
{
    public function test_canonical_get_uses_empty_body_and_excludes_query_string(): void
    {
        $request = Request::create('/api/v1/connector/jobs?cursor=abc', 'get');
        $canonical = (new ConnectorRequestCanonicalizer)->canonicalize($request, '1791288000', 'AAAAAAAAAAAAAAAAAAAAAA');

        self::assertSame("SP-HMAC-SHA256\nGET\n/api/v1/connector/jobs\n1791288000\nAAAAAAAAAAAAAAAAAAAAAA\n".hash('sha256', ''), $canonical);
        self::assertNotSame("\n", substr($canonical, -1));
    }

    public function test_canonical_post_hashes_exact_raw_body_bytes_and_uppercases_method(): void
    {
        $body = "{\"a\":1, \"b\":2}\n";
        $request = Request::create('/api/v1/connector/inventory', 'post', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        $canonical = (new ConnectorRequestCanonicalizer)->canonicalize($request, '1791288000', 'AAAAAAAAAAAAAAAAAAAAAA');

        self::assertSame("SP-HMAC-SHA256\nPOST\n/api/v1/connector/inventory\n1791288000\nAAAAAAAAAAAAAAAAAAAAAA\n".hash('sha256', $body), $canonical);
        self::assertNotSame(hash('sha256', '{"a":1,"b":2}'), hash('sha256', $body));
    }
}
