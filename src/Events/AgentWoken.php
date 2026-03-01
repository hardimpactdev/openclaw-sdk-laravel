<?php

declare(strict_types=1);

namespace HardImpact\OpenClaw\Events;

use DateTimeImmutable;
use HardImpact\OpenClaw\Contracts\OpenClawAgent;
use HardImpact\OpenClaw\Contracts\OpenClawGateway;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Event fired when an agent is successfully woken via the gateway.
 *
 * This event is provided as a data structure for consumers to dispatch
 * from their own wake orchestration logic. The SDK does not dispatch it
 * automatically — use AgentWoken::dispatch() after a successful wake call.
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
