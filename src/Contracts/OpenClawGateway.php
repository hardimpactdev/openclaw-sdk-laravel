<?php

declare(strict_types=1);

namespace HardImpact\OpenClaw\Contracts;

/**
 * Interface for gateway/instance models that connect to OpenClaw.
 */
interface OpenClawGateway
{
    /**
     * Get the WebSocket URL for health checks (e.g. wss://openclaw.bear).
     */
    public function getGatewayUrl(): ?string;

    /**
     * Get the token for HTTP wake requests (Bearer token for /hooks/agent).
     */
    public function getGatewayToken(): ?string;

    /**
     * Get the auth token for WebSocket health checks (WS handshake auth).
     */
    public function getGatewayAuthToken(): ?string;

    /**
     * Get the display name for this gateway (used for logging).
     */
    public function getGatewayDisplayName(): string;
}
