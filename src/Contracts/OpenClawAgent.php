<?php

declare(strict_types=1);

namespace HardImpact\OpenClaw\Contracts;

/**
 * Interface for agent models that participate in the OpenClaw wake/status system.
 */
interface OpenClawAgent
{
    /**
     * Get the agent's canonical identifier (used for gateway health lookups).
     */
    public function getAgentIdentifier(): string;

    /**
     * Get the agent's display name (used for logging and notifications).
     */
    public function getAgentDisplayName(): string;

    /**
     * Get the agent's LLM model name (used for per-model rate limiting).
     * Returns null if no specific model is configured.
     */
    public function getAgentModel(): ?string;

    /**
     * Update awake status based on session age from health check.
     */
    public function updateAwakeStatus(int $sessionAgeMs): void;

    /**
     * Mark the agent as sleeping (no active session).
     */
    public function markAsSleeping(): void;
}
