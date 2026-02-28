<?php

declare(strict_types=1);

namespace HardImpact\OpenClaw\Tests;

use HardImpact\OpenClaw\OpenClawServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            OpenClawServiceProvider::class,
        ];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('openclaw.gateway_ca_bundle', '/dev/null');
        $app['config']->set('openclaw.agent_model', null);
        $app['config']->set('openclaw.gateway_model', null);
    }
}
