<?php

declare(strict_types=1);

namespace HardImpact\OpenClaw;

use HardImpact\OpenClaw\Clients\GatewayWebSocketClient;
use HardImpact\OpenClaw\Commands\CheckAgentStatusCommand;
use HardImpact\OpenClaw\Contracts\GatewayHealthClient;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class OpenClawServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('openclaw')
            ->hasConfigFile()
            ->hasMigrations([
                'add_openclaw_wake_fields',
                'add_openclaw_gateway_fields',
            ])
            ->hasCommand(CheckAgentStatusCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->bind(GatewayHealthClient::class, GatewayWebSocketClient::class);
    }
}
