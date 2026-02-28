<?php

declare(strict_types=1);

namespace HardImpact\OpenClaw\Commands;

use HardImpact\OpenClaw\Contracts\GatewayHealthClient;
use HardImpact\OpenClaw\Contracts\OpenClawAgent;
use HardImpact\OpenClaw\Contracts\OpenClawGateway;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Check agent awake/sleep status via OpenClaw gateway WebSocket health endpoint.
 *
 * This command queries the gateway health status for all agents and updates their
 * status fields consistently. It does NOT reset rate limits or wake agents.
 */
class CheckAgentStatusCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'openclaw:check-status';

    /**
     * @var string
     */
    protected $description = 'Check agent awake/sleep status via OpenClaw gateway (free, no API calls)';

    public function __construct(
        private readonly GatewayHealthClient $healthClient,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /** @var class-string<Model&OpenClawAgent>|null $agentModelClass */
        $agentModelClass = Config::get('openclaw.agent_model');

        if ($agentModelClass === null) {
            $this->error('No agent model configured. Set openclaw.agent_model in config.');

            return self::FAILURE;
        }

        /** @var string $gatewayRelation */
        $gatewayRelation = Config::get('openclaw.agent_gateway_relation', 'instance');

        $totalAgents = 0;
        $statusUpdated = 0;
        $statusMarkedSleeping = 0;

        $agentModelClass::with($gatewayRelation)
            ->whereHas($gatewayRelation, fn (\Illuminate\Contracts\Database\Query\Builder $q) => $q->whereNotNull('gateway_url'))
            ->chunkById(100, function (Collection $chunk) use ($gatewayRelation, &$totalAgents, &$statusUpdated, &$statusMarkedSleeping): void {
                /** @var Collection<int, Model&OpenClawAgent> $chunk */
                $this->processAgentChunk($chunk, $gatewayRelation, $totalAgents, $statusUpdated, $statusMarkedSleeping);
            });

        $this->info(sprintf(
            'Status check complete: %d agents updated, %d marked sleeping, %d total',
            $statusUpdated,
            $statusMarkedSleeping,
            $totalAgents,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Model&OpenClawAgent>  $agents
     */
    private function processAgentChunk(
        Collection $agents,
        string $gatewayRelation,
        int &$totalAgents,
        int &$statusUpdated,
        int &$statusMarkedSleeping,
    ): void {
        $totalAgents += $agents->count();

        /** @var Collection<int, Model&OpenClawGateway> $uniqueGateways */
        $uniqueGateways = $agents->pluck($gatewayRelation)->unique('id');
        $gatewaysByUrl = $uniqueGateways->groupBy(fn (Model&OpenClawGateway $g): ?string => $g->getGatewayUrl());

        /** @var array<int, array<string, Model&OpenClawAgent>> $agentsByGateway */
        $agentsByGateway = [];
        foreach ($agents as $agent) {
            /** @var Model&OpenClawGateway $gateway */
            $gateway = $agent->getRelation($gatewayRelation);
            /** @var int $gatewayId */
            $gatewayId = $gateway->getKey();
            $agentsByGateway[$gatewayId][$agent->getAgentIdentifier()] = $agent;
        }

        foreach ($gatewaysByUrl as $gatewayUrl => $gateways) {
            /** @var string|null $gatewayUrl */
            /** @var Collection<int, Model&OpenClawGateway> $gateways */
            if (empty($gatewayUrl)) {
                continue;
            }

            /** @var Model&OpenClawGateway $firstGateway */
            $firstGateway = $gateways->first();
            $gatewayToken = mb_trim($firstGateway->getGatewayAuthToken() ?? '');

            if ($gatewayToken === '') {
                Log::warning('CheckAgentStatus: No gateway auth token for health check', [
                    'gateway_url' => $gatewayUrl,
                    'gateway' => $firstGateway->getGatewayDisplayName(),
                ]);

                foreach ($gateways as $gateway) {
                    /** @var int $gatewayId */
                    $gatewayId = $gateway->getKey();
                    foreach ($agentsByGateway[$gatewayId] ?? [] as $agent) {
                        $agent->markAsSleeping();
                        $statusMarkedSleeping++;
                    }
                }

                continue;
            }

            $healthStatus = $this->healthClient->getHealthStatus($gatewayUrl, $gatewayToken);

            if ($healthStatus === null) {
                Log::warning('CheckAgentStatus: WebSocket health check failed', [
                    'gateway_url' => $gatewayUrl,
                ]);

                foreach ($gateways as $gateway) {
                    /** @var int $gatewayId */
                    $gatewayId = $gateway->getKey();
                    foreach ($agentsByGateway[$gatewayId] ?? [] as $agent) {
                        $agent->markAsSleeping();
                        $statusMarkedSleeping++;
                    }
                }

                continue;
            }

            foreach ($gateways as $gateway) {
                /** @var int $gatewayId */
                $gatewayId = $gateway->getKey();
                foreach ($agentsByGateway[$gatewayId] ?? [] as $agent) {
                    $agentId = $agent->getAgentIdentifier();

                    if (isset($healthStatus[$agentId])) {
                        $agentData = $healthStatus[$agentId];
                        $agent->updateAwakeStatus($agentData['age'] ?? 0);
                        $agent->update(['last_seen_at' => now()]);
                        $statusUpdated++;
                    } else {
                        $agent->markAsSleeping();
                        $statusMarkedSleeping++;
                    }
                }
            }
        }
    }
}
