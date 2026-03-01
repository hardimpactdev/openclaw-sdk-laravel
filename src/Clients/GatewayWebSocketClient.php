<?php

declare(strict_types=1);

namespace HardImpact\OpenClaw\Clients;

use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\TimeoutCancellation;
use Amp\Websocket\Client\Rfc6455Connector;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\Client\WebsocketHandshake;
use HardImpact\OpenClaw\Contracts\GatewayHealthClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class GatewayWebSocketClient implements GatewayHealthClient
{
    /**
     * Extract TLS peer name (SNI) from a gateway URL.
     */
    public static function peerNameFromGatewayUrl(string $gatewayUrl): string
    {
        return (string) (parse_url($gatewayUrl, PHP_URL_HOST) ?? '');
    }

    /**
     * Connect to gateway and retrieve health status for all agents.
     *
     * @return array<string, array{age: int|null, awake: bool}>|null
     *                                                               Map of agent_id to session data, or null on failure
     */
    public function getHealthStatus(string $gatewayUrl, string $gatewayToken): ?array
    {
        try {
            $connection = $this->connect($gatewayUrl, $gatewayToken);

            if (! $connection instanceof WebsocketConnection) {
                return null;
            }

            try {
                return $this->sendHealthRequest($connection);
            } finally {
                $connection->close();
            }
        } catch (Throwable $throwable) {
            Log::warning('GatewayWebSocketClient: Health check failed', [
                'gateway_url' => $gatewayUrl,
                'error' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Convert WSS URL to HTTPS URL for wake requests.
     */
    public static function wssToHttps(string $wssUrl): string
    {
        return str_replace(['wss://', 'ws://'], ['https://', 'http://'], $wssUrl);
    }

    /**
     * Establish WebSocket connection with token auth.
     */
    private function connect(string $gatewayUrl, string $gatewayToken): ?WebsocketConnection
    {
        $connector = $this->createConnector($gatewayUrl);
        $handshake = (new WebsocketHandshake($gatewayUrl))
            ->withHeader('Authorization', 'Bearer '.$gatewayToken);

        try {
            $connection = $connector->connect($handshake);
        } catch (Throwable $throwable) {
            Log::warning('GatewayWebSocketClient: Connection failed', [
                'gateway_url' => $gatewayUrl,
                'error' => $throwable->getMessage(),
            ]);

            return null;
        }

        $challenge = $this->waitForChallenge($connection);

        if ($challenge === null) {
            $connection->close();

            return null;
        }

        if (! $this->sendConnectRequest($connection, $gatewayToken)) {
            $connection->close();

            return null;
        }

        if (! $this->waitForHelloOk($connection)) {
            $connection->close();

            return null;
        }

        return $connection;
    }

    /**
     * Create WebSocket connector with TLS context.
     */
    private function createConnector(string $gatewayUrl): Rfc6455Connector
    {
        /** @var string|null $caBundlePath */
        $caBundlePath = Config::get('openclaw.gateway_ca_bundle');

        $peerName = self::peerNameFromGatewayUrl($gatewayUrl);

        $tlsContext = new ClientTlsContext($peerName);

        if ($caBundlePath !== null) {
            $tlsContext = $tlsContext->withCaFile($caBundlePath);
        }

        $tlsContext = $tlsContext->withPeerVerification();

        $connectContext = (new ConnectContext)
            ->withTcpNoDelay()
            ->withTlsContext($tlsContext);

        $httpClient = (new HttpClientBuilder)
            ->usingPool(new UnlimitedConnectionPool(
                new DefaultConnectionFactory(connectContext: $connectContext)
            ))
            ->build();

        return new Rfc6455Connector(httpClient: $httpClient);
    }

    /**
     * Wait for connect.challenge event.
     *
     * @return array{nonce: string, ts: int}|null
     */
    private function waitForChallenge(WebsocketConnection $connection): ?array
    {
        /** @var int $timeoutMs */
        $timeoutMs = Config::get('openclaw.health.connect_timeout_ms', 10000);
        $cancellation = new TimeoutCancellation($timeoutMs / 1000);

        try {
            $message = $connection->receive($cancellation);

            if (! $message instanceof \Amp\Websocket\WebsocketMessage) {
                Log::warning('GatewayWebSocketClient: No challenge received (connection closed)');

                return null;
            }

            $data = json_decode($message->buffer(), true);

            if (! is_array($data)) {
                Log::warning('GatewayWebSocketClient: Invalid challenge JSON');

                return null;
            }

            if (($data['type'] ?? null) === 'event' && ($data['event'] ?? null) === 'connect.challenge') {
                /** @var mixed $payload */
                $payload = $data['payload'] ?? [];

                if (is_array($payload) && isset($payload['nonce'], $payload['ts']) && is_int($payload['ts']) && (is_string($payload['nonce']) || is_int($payload['nonce']))) {
                    return [
                        'nonce' => (string) $payload['nonce'],
                        'ts' => $payload['ts'],
                    ];
                }
            }

            Log::warning('GatewayWebSocketClient: Unexpected message waiting for challenge', [
                'type' => $data['type'] ?? 'unknown',
                'event' => $data['event'] ?? 'unknown',
            ]);

            return null;
        } catch (Throwable $throwable) {
            Log::warning('GatewayWebSocketClient: Challenge timeout or error', [
                'error' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Send connect request with token auth.
     */
    private function sendConnectRequest(WebsocketConnection $connection, string $token): bool
    {
        $request = [
            'type' => 'req',
            'id' => 'connect-'.uniqid(),
            'method' => 'connect',
            'params' => [
                'minProtocol' => 3,
                'maxProtocol' => 3,
                'client' => [
                    'id' => 'gateway-client',
                    'mode' => 'backend',
                    'version' => '1.0.0',
                    'platform' => PHP_OS,
                ],
                'caps' => [],
                'auth' => [
                    'token' => $token,
                ],
                'role' => 'operator',
                'scopes' => ['operator.admin'],
            ],
        ];

        try {
            $encoded = json_encode($request);
            if ($encoded === false) {
                return false;
            }

            $connection->sendText($encoded);

            return true;
        } catch (Throwable $throwable) {
            Log::warning('GatewayWebSocketClient: Connect request failed', [
                'error' => $throwable->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Wait for hello-ok response.
     */
    private function waitForHelloOk(WebsocketConnection $connection): bool
    {
        /** @var int $timeoutMs */
        $timeoutMs = Config::get('openclaw.health.request_timeout_ms', 5000);
        $cancellation = new TimeoutCancellation($timeoutMs / 1000);

        try {
            $message = $connection->receive($cancellation);

            if (! $message instanceof \Amp\Websocket\WebsocketMessage) {
                Log::warning('GatewayWebSocketClient: No hello-ok received (connection closed)');

                return false;
            }

            $data = json_decode($message->buffer(), true);

            if (! is_array($data)) {
                Log::warning('GatewayWebSocketClient: Invalid hello-ok JSON');

                return false;
            }

            if (($data['type'] ?? null) === 'res' && ($data['ok'] ?? false) === true) {
                return true;
            }

            if (($data['type'] ?? null) === 'res' && ($data['ok'] ?? false) === false) {
                $error = $data['error'] ?? 'Unknown error';
                Log::warning('GatewayWebSocketClient: Connect failed', [
                    'error' => is_array($error) ? json_encode($error) : $error,
                ]);

                return false;
            }

            Log::warning('GatewayWebSocketClient: Unexpected message waiting for hello-ok', [
                'type' => $data['type'] ?? 'unknown',
            ]);

            return false;
        } catch (Throwable $throwable) {
            Log::warning('GatewayWebSocketClient: Hello-ok timeout or error', [
                'error' => $throwable->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send health request and parse response.
     *
     * @return array<string, array{age: int|null, awake: bool}>|null
     */
    private function sendHealthRequest(WebsocketConnection $connection): ?array
    {
        $request = [
            'type' => 'req',
            'id' => 'health-'.uniqid(),
            'method' => 'health',
        ];

        try {
            $encoded = json_encode($request);
            if ($encoded === false) {
                return null;
            }

            $connection->sendText($encoded);
        } catch (Throwable $throwable) {
            Log::warning('GatewayWebSocketClient: Health request failed', [
                'error' => $throwable->getMessage(),
            ]);

            return null;
        }

        /** @var int $timeoutMs */
        $timeoutMs = Config::get('openclaw.health.request_timeout_ms', 5000);
        $cancellation = new TimeoutCancellation($timeoutMs / 1000);

        try {
            $message = $connection->receive($cancellation);

            if (! $message instanceof \Amp\Websocket\WebsocketMessage) {
                Log::warning('GatewayWebSocketClient: No health response received (connection closed)');

                return null;
            }

            $data = json_decode($message->buffer(), true);

            if (! is_array($data)) {
                Log::warning('GatewayWebSocketClient: Invalid health response JSON');

                return null;
            }

            if (($data['type'] ?? null) === 'res' && ($data['ok'] ?? false) === true) {
                /** @var mixed $payload */
                $payload = $data['payload'] ?? [];

                if (is_array($payload) && isset($payload['agents']) && is_array($payload['agents'])) {
                    /** @var array<int, array<string, mixed>> $agents */
                    $agents = $payload['agents'];

                    return $this->parseHealthResponse($agents);
                }
            }

            if (($data['type'] ?? null) === 'res' && ($data['ok'] ?? false) === false) {
                $error = $data['error'] ?? 'Unknown error';
                Log::warning('GatewayWebSocketClient: Health request failed', [
                    'error' => is_array($error) ? json_encode($error) : $error,
                ]);

                return null;
            }

            Log::warning('GatewayWebSocketClient: Unexpected health response', [
                'type' => $data['type'] ?? 'unknown',
            ]);

            return null;
        } catch (Throwable $throwable) {
            Log::warning('GatewayWebSocketClient: Health response timeout or error', [
                'error' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Parse health response into agent session data.
     *
     * @param  array<mixed>  $agents
     * @return array<string, array{age: int|null, awake: bool}>
     */
    private function parseHealthResponse(array $agents): array
    {
        /** @var int $awakeThresholdMs */
        $awakeThresholdMs = Config::get('openclaw.health.awake_threshold_ms', 60000);

        $result = [];

        foreach ($agents as $agent) {
            if (! is_array($agent)) {
                continue;
            }

            if (! isset($agent['agentId'])) {
                continue;
            }

            if (! is_string($agent['agentId'])) {
                continue;
            }

            $agentId = $agent['agentId'];
            $sessions = $agent['sessions'] ?? [];

            if (! is_array($sessions)) {
                $result[$agentId] = ['age' => null, 'awake' => false];

                continue;
            }

            $recentSessions = $sessions['recent'] ?? [];

            if (! is_array($recentSessions)) {
                $result[$agentId] = ['age' => null, 'awake' => false];

                continue;
            }

            $mainSession = null;

            foreach ($recentSessions as $session) {
                if (! is_array($session)) {
                    continue;
                }

                if (! isset($session['key'])) {
                    continue;
                }

                if (! is_string($session['key'])) {
                    continue;
                }

                $key = $session['key'];

                if (str_ends_with($key, ':main')) {
                    $mainSession = $session;

                    break;
                }
            }

            if ($mainSession === null) {
                $result[$agentId] = ['age' => null, 'awake' => false];

                continue;
            }

            $rawAge = $mainSession['age'] ?? null;
            $age = ($rawAge !== null && is_numeric($rawAge)) ? (int) $rawAge : null;
            $isAwake = $age !== null && $age < $awakeThresholdMs;

            $result[$agentId] = [
                'age' => $age,
                'awake' => $isAwake,
            ];
        }

        return $result;
    }
}
