<?php

declare(strict_types=1);

namespace HardImpact\OpenClaw\Events;

use DateTimeImmutable;
use HardImpact\OpenClaw\Contracts\OpenClawAgent;
use HardImpact\OpenClaw\Contracts\OpenClawGateway;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Event fired when an agent is successfully woken via the gateway.
 */
final class AgentWoken
{
    use Dispatchable;

    public function __construct(
        public OpenClawAgent $agent,
        public OpenClawGateway $gateway,
        public string $message,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable,
    ) {}
}
