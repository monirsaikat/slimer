<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $requestId = trim($request->getHeaderLine('X-Request-Id'));
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $requestId)) {
            $requestId = bin2hex(random_bytes(16));
        }

        $response = $handler->handle($request->withAttribute('request_id', $requestId));

        return $response->withHeader('X-Request-Id', $requestId);
    }
}
