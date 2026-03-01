<?php

declare(strict_types=1);

use HardImpact\OpenClaw\Clients\GatewayWebSocketClient;

describe('GatewayWebSocketClient', function (): void {
    describe('peerNameFromGatewayUrl', function (): void {
        it('extracts hostname from WSS URL', function (): void {
            expect(GatewayWebSocketClient::peerNameFromGatewayUrl('wss://openclaw.bear'))
                ->toBe('openclaw.bear');
        });

        it('extracts hostname from URL with port', function (): void {
            expect(GatewayWebSocketClient::peerNameFromGatewayUrl('wss://openclaw.bear:8443'))
                ->toBe('openclaw.bear');
        });

        it('returns empty string for invalid URL', function (): void {
            expect(GatewayWebSocketClient::peerNameFromGatewayUrl('not-a-url'))
                ->toBe('');
        });
    });

    describe('wssToHttps', function (): void {
        it('converts WSS to HTTPS', function (): void {
            expect(GatewayWebSocketClient::wssToHttps('wss://openclaw.bear'))
                ->toBe('https://openclaw.bear');
        });

        it('converts WSS to HTTPS with path', function (): void {
            expect(GatewayWebSocketClient::wssToHttps('wss://openclaw.bear/ws'))
                ->toBe('https://openclaw.bear/ws');
        });

        it('leaves non-WSS URLs unchanged', function (): void {
            expect(GatewayWebSocketClient::wssToHttps('https://openclaw.bear'))
                ->toBe('https://openclaw.bear');
        });

        it('converts ws:// to http://', function (): void {
            expect(GatewayWebSocketClient::wssToHttps('ws://openclaw.bear'))
                ->toBe('http://openclaw.bear');
        });

        it('converts ws:// to http:// with path', function (): void {
            expect(GatewayWebSocketClient::wssToHttps('ws://openclaw.bear/ws'))
                ->toBe('http://openclaw.bear/ws');
        });
    });

    describe('getHealthStatus', function (): void {
        it('returns null on connection failure', function (): void {
            $client = new GatewayWebSocketClient;

            // This will fail to connect (no server) and return null
            $result = $client->getHealthStatus('wss://localhost:19999', 'fake-token');

            expect($result)->toBeNull();
        });
    });
});
