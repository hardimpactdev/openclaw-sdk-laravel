<?php

declare(strict_types=1);

use HardImpact\OpenClaw\Concerns\HasWakeManagement;
use HardImpact\OpenClaw\Contracts\OpenClawAgent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('test_agents', function (Blueprint $table): void {
        $table->id();
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
        $table->timestamps();
    });
});

function createTestAgent(array $attributes = []): Model&OpenClawAgent
{
    /** @var Model&OpenClawAgent $agent */
    $agent = TestAgent::create(array_merge([
        'agent_id' => 'test-agent',
        'name' => 'Test Agent',
        'model' => 'claude-sonnet-4',
    ], $attributes));

    return $agent;
}

describe('canWake', function (): void {
    it('returns true when agent has never been woken', function (): void {
        $agent = createTestAgent();

        expect($agent->canWake())->toBeTrue();
    });

    it('returns false when wake is disabled', function (): void {
        $agent = createTestAgent(['wake_enabled' => false]);

        expect($agent->canWake())->toBeFalse();
    });

    it('returns false when in backoff period', function (): void {
        $agent = createTestAgent([
            'backoff_until' => now()->addMinutes(5),
        ]);

        expect($agent->canWake())->toBeFalse();
    });

    it('returns true when backoff has expired', function (): void {
        $agent = createTestAgent([
            'backoff_until' => now()->subMinute(),
        ]);

        expect($agent->canWake())->toBeTrue();
    });

    it('returns false when interval not elapsed', function (): void {
        $agent = createTestAgent([
            'last_wake_at' => now()->subSeconds(60),
            'wake_interval_seconds' => 120,
        ]);

        expect($agent->canWake())->toBeFalse();
    });

    it('returns true when interval has elapsed', function (): void {
        $agent = createTestAgent([
            'last_wake_at' => now()->subSeconds(130),
            'wake_interval_seconds' => 120,
        ]);

        expect($agent->canWake())->toBeTrue();
    });
});

describe('recordWakeSuccess', function (): void {
    it('updates wake counters and resets failures', function (): void {
        $agent = createTestAgent([
            'consecutive_failures' => 3,
            'backoff_until' => now()->addMinutes(5),
        ]);

        $agent->recordWakeSuccess();
        $agent->refresh();

        expect($agent->consecutive_failures)->toBe(0)
            ->and($agent->backoff_until)->toBeNull()
            ->and($agent->wake_count)->toBe(1)
            ->and($agent->wake_count_minute)->toBe(1)
            ->and($agent->last_wake_at)->not->toBeNull();
    });

    it('increments daily counter for same day', function (): void {
        $agent = createTestAgent([
            'wake_count_today' => 5,
            'wake_count_today_date' => now(),
        ]);

        $agent->recordWakeSuccess();
        $agent->refresh();

        expect($agent->wake_count_today)->toBe(6);
    });

    it('resets daily counter for new day', function (): void {
        $agent = createTestAgent([
            'wake_count_today' => 5,
            'wake_count_today_date' => now()->subDay(),
        ]);

        $agent->recordWakeSuccess();
        $agent->refresh();

        expect($agent->wake_count_today)->toBe(1);
    });
});

describe('recordWakeFailure', function (): void {
    it('increments consecutive failures and sets backoff', function (): void {
        $agent = createTestAgent();

        $agent->recordWakeFailure();
        $agent->refresh();

        expect($agent->consecutive_failures)->toBe(1)
            ->and($agent->backoff_until)->not->toBeNull()
            ->and($agent->backoff_until->isFuture())->toBeTrue();
    });

    it('applies exponential backoff', function (): void {
        $agent = createTestAgent(['consecutive_failures' => 2]);

        $agent->recordWakeFailure();
        $agent->refresh();

        // 3rd failure: base * 2^2 = 120 * 4 = 480s
        expect($agent->consecutive_failures)->toBe(3);
        $backoffSeconds = $agent->getCurrentBackoffSeconds();
        expect($backoffSeconds)->toBeGreaterThanOrEqual(470)
            ->and($backoffSeconds)->toBeLessThanOrEqual(490);
    });
});

describe('recordRateLimitResponse', function (): void {
    it('respects server retry-after within bounds', function (): void {
        $agent = createTestAgent();

        $agent->recordRateLimitResponse(300);
        $agent->refresh();

        expect($agent->consecutive_failures)->toBe(1);
        $backoff = $agent->getCurrentBackoffSeconds();
        expect($backoff)->toBeGreaterThanOrEqual(290)
            ->and($backoff)->toBeLessThanOrEqual(310);
    });

    it('clamps retry-after to max backoff', function (): void {
        $agent = createTestAgent();

        $agent->recordRateLimitResponse(9999);
        $agent->refresh();

        $backoff = $agent->getCurrentBackoffSeconds();
        expect($backoff)->toBeLessThanOrEqual(610);
    });
});

describe('updateAwakeStatus', function (): void {
    it('marks agent as awake when session age is below threshold', function (): void {
        $agent = createTestAgent();

        $agent->updateAwakeStatus(5000);
        $agent->refresh();

        expect($agent->is_awake)->toBeTrue()
            ->and($agent->session_age_ms)->toBe(5000)
            ->and($agent->last_status_check_at)->not->toBeNull();
    });

    it('marks agent as sleeping when session age exceeds threshold', function (): void {
        $agent = createTestAgent(['is_awake' => true]);

        $agent->updateAwakeStatus(70000);
        $agent->refresh();

        expect($agent->is_awake)->toBeFalse()
            ->and($agent->session_age_ms)->toBe(70000);
    });
});

describe('markAsSleeping', function (): void {
    it('clears awake status and session age', function (): void {
        $agent = createTestAgent([
            'is_awake' => true,
            'session_age_ms' => 5000,
        ]);

        $agent->markAsSleeping();
        $agent->refresh();

        expect($agent->is_awake)->toBeFalse()
            ->and($agent->session_age_ms)->toBeNull()
            ->and($agent->last_status_check_at)->not->toBeNull();
    });
});

describe('secondsUntilCanWake', function (): void {
    it('returns 0 when never woken', function (): void {
        $agent = createTestAgent();

        expect($agent->secondsUntilCanWake())->toBe(0);
    });

    it('returns PHP_INT_MAX when disabled', function (): void {
        $agent = createTestAgent(['wake_enabled' => false]);

        expect($agent->secondsUntilCanWake())->toBe(PHP_INT_MAX);
    });

    it('returns remaining backoff time', function (): void {
        $agent = createTestAgent([
            'backoff_until' => now()->addSeconds(100),
        ]);

        $remaining = $agent->secondsUntilCanWake();
        expect($remaining)->toBeGreaterThanOrEqual(95)
            ->and($remaining)->toBeLessThanOrEqual(105);
    });
});

// Test model definition
class TestAgent extends Model implements OpenClawAgent
{
    use HasWakeManagement;

    protected $table = 'test_agents';

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
            'wake_interval_seconds' => 'integer',
            'wake_count_minute' => 'integer',
            'consecutive_failures' => 'integer',
            'backoff_until' => 'datetime',
            'wake_count' => 'integer',
            'wake_count_today' => 'integer',
            'wake_count_today_date' => 'date',
        ];
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
