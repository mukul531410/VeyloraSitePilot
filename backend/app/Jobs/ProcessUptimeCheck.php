<?php

namespace App\Jobs;

use App\Models\Site;
use App\Models\HealthCheck;
use App\Models\UptimeCheck;
use App\Services\SslCertificateInspector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class ProcessUptimeCheck implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, Queueable;

    public int $timeout = 30;
    public int $tries = 3;

    private string $correlationId;

    public function __construct(
        public string $siteId,
    ) {
        $this->correlationId = Str::uuid()->toString();
    }

    public function uniqueId(): string
    {
        return 'uptime-check:' . $this->siteId;
    }

    public function handle(): void
    {
        $site = Site::findOrFail($this->siteId);

        $startTime = now();
        $httpStatus = null;
        $responseMs = null;
        $status = UptimeCheck::STATUS_DOWN;
        $requestStartedAt = microtime(true);

        try {
            $response = Http::timeout(10)->get($site->url);
            $responseMs = (int) round((microtime(true) - $requestStartedAt) * 1000);

            if ($response->successful() || $response->redirect()) {
                $status = UptimeCheck::STATUS_UP;
                $httpStatus = $response->status();
            } else {
                $httpStatus = $response->status();
            }
        } catch (\Illuminate\Http\Client\ConnectionException) {
            $status = UptimeCheck::STATUS_DOWN;
        } catch (RuntimeException $e) {
            $status = UptimeCheck::STATUS_DOWN;
        }

        UptimeCheck::create([
            'site_id' => $site->id,
            'status' => $status,
            'http_status' => $httpStatus,
            'response_ms' => $responseMs,
            'checked_at' => $startTime,
        ]);

        if (parse_url($site->url, PHP_URL_SCHEME) === 'https') {
            $sslCheck = app(SslCertificateInspector::class)->inspect($site->url);

            HealthCheck::create([
                'site_id' => $site->id,
                'check_type' => HealthCheck::CHECK_TYPE_SSL,
                'status' => $sslCheck['status'],
                'value_json' => $sslCheck['value'],
                'checked_at' => $startTime,
            ]);
        }

        Log::info('ProcessUptimeCheck completed', [
            'site_id' => $site->id,
            'status' => $status,
            'http_status' => $httpStatus,
            'response_ms' => $responseMs,
            'correlation_id' => $this->correlationId,
        ]);
    }
}
