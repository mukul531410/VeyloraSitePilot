<?php

namespace App\Services;

use App\Models\HealthCheck;
use Illuminate\Support\Carbon;
use Throwable;

class SslCertificateInspector
{
    public function inspect(string $url): array
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        $port = $parts['port'] ?? 443;

        if (($parts['scheme'] ?? null) !== 'https' || ! $host || ! is_int($port) || $port < 1 || $port > 65535) {
            return [
                'status' => 'unknown',
                'value' => ['reason' => 'ssl_not_applicable'],
            ];
        }

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'SNI_enabled' => true,
                'peer_name' => $host,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);

        $socket = null;

        try {
            $socket = @stream_socket_client(
                "ssl://{$host}:{$port}",
                $errorCode,
                $errorMessage,
                10,
                STREAM_CLIENT_CONNECT,
                $context,
            );

            if (! is_resource($socket)) {
                return [
                    'status' => 'fail',
                    'value' => [
                        'reason' => 'certificate_connection_failed',
                        'error_code' => $errorCode ?? null,
                    ],
                ];
            }

            $params = stream_context_get_params($socket);
            $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
            $parsed = $certificate !== null ? @openssl_x509_parse($certificate) : false;

            if (! is_array($parsed) || ! isset($parsed['validTo_time_t'])) {
                return [
                    'status' => 'fail',
                    'value' => ['reason' => 'certificate_data_unavailable'],
                ];
            }

            $expiresAt = Carbon::createFromTimestampUTC((int) $parsed['validTo_time_t']);
            $remainingDays = now()->diffInDays($expiresAt, false);

            $value = [
                'expires_at' => $expiresAt->toIso8601String(),
                'remaining_days' => $remainingDays,
                'subject' => $parsed['subject']['CN'] ?? null,
                'issuer' => $parsed['issuer']['CN'] ?? null,
            ];

            if ($remainingDays < 0) {
                return ['status' => 'fail', 'value' => $value + ['reason' => 'certificate_expired']];
            }

            if ($remainingDays <= HealthCheck::SSL_CRITICAL_DAYS) {
                return ['status' => 'fail', 'value' => $value];
            }

            if ($remainingDays <= HealthCheck::SSL_WARNING_DAYS) {
                return ['status' => 'warn', 'value' => $value];
            }

            return ['status' => 'pass', 'value' => $value];
        } catch (Throwable $e) {
            return [
                'status' => 'fail',
                'value' => ['reason' => 'certificate_inspection_failed'],
            ];
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }
}
