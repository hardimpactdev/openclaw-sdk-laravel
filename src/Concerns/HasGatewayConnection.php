<?php

declare(strict_types=1);

namespace HardImpact\OpenClaw\Concerns;

/**
 * Trait for gateway/instance models that connect to OpenClaw.
 *
 * Implements the OpenClawGateway contract using standard column names:
 * - gateway_url (string, nullable)
 * - gateway_token (string, nullable)
 * - gateway_auth_token (string, nullable)
 * - name (string)
 *
 * @property string|null $gateway_url
 * @property string|null $gateway_token
 * @property string|null $gateway_auth_token
 * @property string $name
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasGatewayConnection
{
    public function getGatewayUrl(): ?string
    {
        return $this->gateway_url;
    }

    public function getGatewayToken(): ?string
    {
        return $this->gateway_token;
    }

    public function getGatewayAuthToken(): ?string
    {
        return $this->gateway_auth_token;
    }

    public function getGatewayDisplayName(): string
    {
        return $this->name;
    }

    /**
     * Get the attributes that should be hidden for the OpenClaw gateway.
     *
     * Merge this into your model's $hidden property to protect secrets.
     *
     * @return list<string>
     */
    public static function openClawGatewayHidden(): array
    {
        return ['gateway_token', 'gateway_auth_token'];
    }
}
