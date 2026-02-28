<?php

declare(strict_types=1);

namespace HardImpact\OpenClaw\Events;

use DateTimeImmutable;
use HardImpact\OpenClaw\Contracts\OpenClawAgent;
use HardImpact\OpenClaw\Contracts\OpenClawGateway;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Event fired when a wake attempt fails.
 */
final class AgentWakeFailed
{
    use Dispatchable;

    public function __construct(
        public OpenClawAgent $agent,
        public OpenClawGateway $gateway,
        public string $error,
        public int $consecutiveFailures,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable,
    ) {}
}
