<?php

declare(strict_types=1);

namespace HardImpact\OpenClaw\Clients;

use HardImpact\OpenClaw\Contracts\OpenClawGateway;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class GatewayWakeClient
{
    /**
     * Send a wake request to a specific agent via the gateway's HTTP hook.
     *
     * @return array{success: bool, rate_limited: bool, retry_after: int|null, status_code: int|null}
     */
    public function wake(OpenClawGateway $gateway, string $agentId, string $message): array
    {
        try {
            $wssUrl = (string) $gateway->getGatewayUrl();
            $baseUrl = GatewayWebSocketClient::wssToHttps($wssUrl);
            $url = mb_rtrim($baseUrl, '/').'/hooks/agent';

            $payload = [
                'message' => $message,
                'agentId' => $agentId,
                'wakeMode' => 'now',
                'name' => 'Sequence Wake',
            ];

            /** @var int $timeout */
            $timeout = Config::get('openclaw.wake.http_timeout', 10);

            $options = ['timeout' => $timeout];
            $ca = Config::get('openclaw.gateway_ca_bundle');

            if ($ca !== null) {
                $options['verify'] = $ca;
            }

            $request = Http::withOptions($options);

            $gatewayToken = mb_trim($gateway->getGatewayToken() ?? '');
            if ($gatewayToken !== '') {
                $request = $request->withToken($gatewayToken);
            }

            $response = $request->post($url, $payload);

            if ($response->successful()) {
                Log::info('GatewayWakeClient: Successfully woke agent', [
                    'gateway' => $gateway->getGatewayDisplayName(),
                    'agent_id' => $agentId,
                ]);

                return ['success' => true, 'rate_limited' => false, 'retry_after' => null, 'status_code' => $response->status()];
            }

            if ($response->status() === 429) {
                /** @var int $baseBackoff */
                $baseBackoff = Config::get('openclaw.rate_limits.base_backoff_seconds', 120);

                $retryAfter = $response->header('retry-after');
                $retryAfterSeconds = is_numeric($retryAfter) ? (int) $retryAfter : $baseBackoff;

                Log::warning('GatewayWakeClient: Agent rate limited', [
                    'gateway' => $gateway->getGatewayDisplayName(),
                    'agent_id' => $agentId,
                    'retry_after' => $retryAfterSeconds,
                ]);

                return ['success' => false, 'rate_limited' => true, 'retry_after' => $retryAfterSeconds, 'status_code' => 429];
            }

            Log::warning('GatewayWakeClient: Failed to wake agent', [
                'gateway' => $gateway->getGatewayDisplayName(),
                'agent_id' => $agentId,
                'status' => $response->status(),
            ]);

            return ['success' => false, 'rate_limited' => false, 'retry_after' => null, 'status_code' => $response->status()];
        } catch (Throwable $throwable) {
            Log::error('GatewayWakeClient: HTTP exception', [
                'gateway' => $gateway->getGatewayDisplayName(),
                'agent_id' => $agentId,
                'error' => $throwable->getMessage(),
            ]);

            return ['success' => false, 'rate_limited' => false, 'retry_after' => null, 'status_code' => null];
        }
    }
}
