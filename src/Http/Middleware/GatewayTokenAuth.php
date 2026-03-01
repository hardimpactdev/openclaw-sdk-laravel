<?php

declare(strict_types=1);

namespace HardImpact\OpenClaw\Http\Middleware;

use Closure;
use HardImpact\OpenClaw\Contracts\OpenClawGateway;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate requests using a gateway's auth token.
 *
 * Validates that the request includes a valid Authorization header
 * with a Bearer token matching the gateway model's auth token.
 *
 * Resolves the gateway model from a route parameter (configurable).
 */
final class GatewayTokenAuth
{
    /**
     * @param  Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $routeParam = 'name'): Response
    {
        $identifier = $request->route($routeParam);

        if ($identifier === null) {
            return response()->json([
                'error' => 'Gateway identifier not found in route.',
            ], 400);
        }

        if ($identifier instanceof Model && $identifier instanceof OpenClawGateway) {
            $gateway = $identifier;
        } elseif (is_scalar($identifier)) {
            /** @var class-string<Model&OpenClawGateway>|null $gatewayModelClass */
            $gatewayModelClass = Config::get('openclaw.gateway_model');

            if ($gatewayModelClass === null) {
                return response()->json([
                    'error' => 'Gateway model not configured.',
                ], 500);
            }

            /** @var (Model&OpenClawGateway)|null $gateway */
            $gateway = $gatewayModelClass::query()->where($routeParam, $identifier)->first();
        } else {
            return response()->json([
                'error' => 'Invalid gateway identifier.',
            ], 400);
        }

        if ($gateway === null) {
            return response()->json([
                'error' => 'Gateway not found.',
            ], 404);
        }

        $authHeader = $request->header('Authorization');
        if ($authHeader === null || ! str_starts_with($authHeader, 'Bearer ')) {
            return response()->json([
                'error' => 'Missing or invalid Authorization header. Expected: Bearer {token}',
            ], 401);
        }

        $providedToken = mb_substr($authHeader, 7);
        $expectedToken = $gateway->getGatewayAuthToken();

        if ($expectedToken === null || $expectedToken === '') {
            return response()->json([
                'error' => 'Gateway has no authentication token configured.',
            ], 403);
        }

        if (! hash_equals($expectedToken, $providedToken)) {
            return response()->json([
                'error' => 'Invalid authentication token.',
            ], 401);
        }

        $request->setUserResolver(fn () => $gateway);

        return $next($request);
    }
}
