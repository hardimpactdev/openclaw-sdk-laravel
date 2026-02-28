<?php

declare(strict_types=1);

namespace HardImpact\OpenClaw\Contracts;

interface GatewayHealthClient
{
    /**
     * Connect to gateway and retrieve health status for all agents.
     *
     * @return array<string, array{age: int|null, awake: bool}>|null
     *                                                               Map of agent_id to session data, or null on failure
     */
    public function getHealthStatus(string $gatewayUrl, string $gatewayToken): ?array;
}
