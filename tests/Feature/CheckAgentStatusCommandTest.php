<?php

declare(strict_types=1);

use HardImpact\OpenClaw\Concerns\HasGatewayConnection;
use HardImpact\OpenClaw\Concerns\HasWakeManagement;
use HardImpact\OpenClaw\Contracts\GatewayHealthClient;
use HardImpact\OpenClaw\Contracts\OpenClawAgent;
use HardImpact\OpenClaw\Contracts\OpenClawGateway;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('test_gateways', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('gateway_url')->nullable();
        $table->string('gateway_token')->nullable();
        $table->string('gateway_auth_token')->nullable();
        $table->timestamps();
    });

    Schema::create('test_cmd_agents', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('test_gateway_id');
        $table->string('agent_id');
        $table->string('name');
        $table->string('model')->nullable();
        $table->boolean('wake_enabled')->default(true);
        $table->unsignedInteger('wake_interval_seconds')->default(120);
        $table->timestamp('last_wake_at')->nullable();
        $table->unsignedInteger('wake_count_minute')->default(0);
        $table->unsignedInteger('consecutive_failures')->default(0);
        $table->timestamp('backoff_until')->nullable();
        $table->unsignedInteger('wake_count')->default(0);
        $table->unsignedInteger('wake_count_today')->default(0);
        $table->date('wake_count_today_date')->nullable();
        $table->boolean('is_awake')->default(false);
        $table->unsignedInteger('session_age_ms')->nullable();
        $table->timestamp('last_status_check_at')->nullable();
        $table->timestamp('last_seen_at')->nullable();
        $table->timestamps();
    });

    config()->set('openclaw.agent_model', TestCmdAgent::class);
    config()->set('openclaw.gateway_model', TestGateway::class);
    config()->set('openclaw.agent_gateway_relation', 'gateway');
});

describe('CheckAgentStatusCommand', function (): void {
    it('fails when no agent model is configured', function (): void {
        config()->set('openclaw.agent_model', null);

        $this->artisan('openclaw:check-status')
            ->assertFailed();
    });

    it('updates agent status from health check', function (): void {
        $gateway = TestGateway::create([
            'name' => 'test-instance',
            'gateway_url' => 'wss://openclaw.test',
            'gateway_auth_token' => 'test-token',
        ]);

        $agent = TestCmdAgent::create([
            'test_gateway_id' => $gateway->id,
            'agent_id' => 'bear',
            'name' => 'Bear',
            'model' => 'claude-sonnet-4',
        ]);

        // Mock the health client
        $this->app->bind(GatewayHealthClient::class, function () {
            return new class implements GatewayHealthClient
            {
                public function getHealthStatus(string $gatewayUrl, string $gatewayToken): ?array
                {
                    return [
                        'bear' => ['age' => 5000, 'awake' => true],
                    ];
                }
            };
        });

        $this->artisan('openclaw:check-status')
            ->assertSuccessful();

        $agent->refresh();
        expect($agent->is_awake)->toBeTrue()
            ->and($agent->session_age_ms)->toBe(5000);
    });

    it('marks agents as sleeping when not found in health response', function (): void {
        $gateway = TestGateway::create([
            'name' => 'test-instance',
            'gateway_url' => 'wss://openclaw.test',
            'gateway_auth_token' => 'test-token',
        ]);

        $agent = TestCmdAgent::create([
            'test_gateway_id' => $gateway->id,
            'agent_id' => 'missing-agent',
            'name' => 'Missing',
            'is_awake' => true,
        ]);

        $this->app->bind(GatewayHealthClient::class, function () {
            return new class implements GatewayHealthClient
            {
                public function getHealthStatus(string $gatewayUrl, string $gatewayToken): ?array
                {
                    return [];
                }
            };
        });

        $this->artisan('openclaw:check-status')
            ->assertSuccessful();

        $agent->refresh();
        expect($agent->is_awake)->toBeFalse()
            ->and($agent->session_age_ms)->toBeNull();
    });

    it('marks agents as sleeping when health check fails', function (): void {
        $gateway = TestGateway::create([
            'name' => 'test-instance',
            'gateway_url' => 'wss://openclaw.test',
            'gateway_auth_token' => 'test-token',
        ]);

        $agent = TestCmdAgent::create([
            'test_gateway_id' => $gateway->id,
            'agent_id' => 'bear',
            'name' => 'Bear',
            'is_awake' => true,
        ]);

        $this->app->bind(GatewayHealthClient::class, function () {
            return new class implements GatewayHealthClient
            {
                public function getHealthStatus(string $gatewayUrl, string $gatewayToken): ?array
                {
                    return null;
                }
            };
        });

        $this->artisan('openclaw:check-status')
            ->assertSuccessful();

        $agent->refresh();
        expect($agent->is_awake)->toBeFalse();
    });
});

// Test models for the command
class TestGateway extends Model implements OpenClawGateway
{
    use HasGatewayConnection;

    protected $table = 'test_gateways';

    protected $guarded = [];
}

class TestCmdAgent extends Model implements OpenClawAgent
{
    use HasWakeManagement;

    protected $table = 'test_cmd_agents';

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'wake_enabled' => true,
        'wake_interval_seconds' => 120,
        'consecutive_failures' => 0,
        'wake_count' => 0,
        'wake_count_today' => 0,
        'wake_count_minute' => 0,
    ];

    protected function casts(): array
    {
        return [
            'wake_enabled' => 'boolean',
            'is_awake' => 'boolean',
            'session_age_ms' => 'integer',
            'last_wake_at' => 'datetime',
            'last_status_check_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'wake_interval_seconds' => 'integer',
            'wake_count_minute' => 'integer',
            'consecutive_failures' => 'integer',
            'backoff_until' => 'datetime',
            'wake_count' => 'integer',
            'wake_count_today' => 'integer',
            'wake_count_today_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<TestGateway, $this>
     */
    public function gateway(): BelongsTo
    {
        return $this->belongsTo(TestGateway::class, 'test_gateway_id');
    }

    public function getAgentIdentifier(): string
    {
        return $this->agent_id;
    }

    public function getAgentDisplayName(): string
    {
        return $this->name;
    }

    public function getAgentModel(): ?string
    {
        return $this->model;
    }
}
