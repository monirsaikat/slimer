<?php

namespace App\Application\Middleware;

use App\Application\Models\User;
use App\Application\Models\AuthToken;
use App\Application\Services\TokenService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class JwtMiddleware
{
    private string $jwtSecret; // Replace with your secure secret key

    public function __construct(private TokenService $tokens)
    {
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $this->jwtSecret = env('JWT_SECRET');

        $authHeader = $request->getHeaderLine('Authorization');

        if (!$authHeader) {
            return $this->unauthorizedResponse('Authorization header not provided');
        }

        if (!preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $matches)) {
            return $this->unauthorizedResponse('Invalid Authorization header');
        }

        $token = $matches[1];
        try {
            $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));

            if (
                ($decoded->typ ?? null) !== AuthToken::ACCESS
                || !isset($decoded->jti, $decoded->ver)
            ) {
                return $this->unauthorizedResponse('Invalid access token');
            }

            $userId = isset($decoded->sub) ? (int) $decoded->sub : 0;
            $user = User::find($userId);

            if (
                !$user
                || $user->status !== User::ACTIVE
                || $user->email_verified_at === null
                || (int) $decoded->ver !== $user->auth_version
                || !$this->tokens->isAccessTokenActive((string) $decoded->jti, $user)
            ) {
                return $this->unauthorizedResponse('The account is unavailable');
            }

            $request = $request
                ->withAttribute('user', $user)
                ->withAttribute('jwt', $decoded);
        } catch (\Throwable $e) {
            return $this->unauthorizedResponse('Invalid or expired token');
        }

        return $handler->handle($request);
    }

    /**
     * Generate an unauthorized response with a customizable message.
     *
     * @param string $message
     * @param string|null $details
     * @return Response
     */
    private function unauthorizedResponse(string $message, ?string $details = null): Response
    {
        $response = new \Slim\Psr7\Response();
        $errorData = [
            'error' => $message,
        ];

        if ($details) {
            $errorData['details'] = $details;
        }

        $response->getBody()->write(json_encode($errorData));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
    }
}
