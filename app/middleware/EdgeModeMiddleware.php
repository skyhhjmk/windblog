<?php

namespace app\middleware;

use app\service\EdgeNodeService;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class EdgeModeMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        if (!EdgeNodeService::isEdgeMode()) {
            return $handler($request);
        }

        $request->edgeMode = true;
        $request->datacenterAvailable = EdgeNodeService::isDatacenterAvailable();
        $request->degradedMode = EdgeNodeService::isDegradedMode();

        $response = $handler($request);

        $response->withHeader('X-Edge-Mode', 'true');
        $response->withHeader('X-Datacenter-Status', $request->datacenterAvailable ? 'online' : 'offline');

        if ($request->degradedMode) {
            $response->withHeader('X-Service-Degraded', 'true');
            $response->withHeader('X-Degraded-Duration', (string) EdgeNodeService::getDegradedDuration());
        }

        return $response;
    }
}
