<?php

declare(strict_types=1);

namespace App\Application\ResponseEmitter;

use Psr\Http\Message\ResponseInterface;
use Slim\ResponseEmitter as SlimResponseEmitter;

class ResponseEmitter extends SlimResponseEmitter
{
    /**
     * {@inheritdoc}
     */
    public function emit(ResponseInterface $response): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        $response = $response
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader(
                'Access-Control-Allow-Headers',
                'X-Requested-With, Content-Type, Accept, Origin, Authorization',
            )
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withAddedHeader('Cache-Control', 'post-check=0, pre-check=0')
            ->withHeader('Pragma', 'no-cache');

        // Allow the hosted portal to reach a developer's local XAMPP API when
        // browsers perform a Private Network Access preflight.
        if (($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_PRIVATE_NETWORK'] ?? '') === 'true') {
            $response = $response->withHeader('Access-Control-Allow-Private-Network', 'true');
        }

        if (ob_get_contents()) {
            ob_clean();
        }

        parent::emit($response);
    }
}
