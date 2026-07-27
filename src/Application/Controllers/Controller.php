<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use Illuminate\Support\Facades\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

abstract class Controller
{
    /**
     * Validate a JSON/form request body and return only validated fields.
     *
     * @param array<string, mixed> $rules
     * @return array<string, mixed>|Response
     */
    protected function validateRequest(Request $request, array $rules): array|Response
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->respondWithValidationError([
                'body' => ['The request body must be a JSON object.'],
            ]);
        }

        return $this->validateData($body, $rules);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $rules
     * @return array<string, mixed>|Response
     */
    protected function validateData(array $data, array $rules): array|Response
    {
        $data = $this->normalizeFloatValues($data);
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            return $this->respondWithValidationError($validator->errors()->toArray());
        }

        return $validator->validated();
    }

    /**
     * Laravel's numeric validator delegates decimal floats to brick/math,
     * which deprecates native float input. JSON clients naturally send floats,
     * so normalize them to their lossless numeric string form before validation.
     *
     * @param array<string|int, mixed> $data
     * @return array<string|int, mixed>
     */
    private function normalizeFloatValues(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_float($value)) {
                $data[$key] = (string) $value;
            } elseif (is_array($value)) {
                $data[$key] = $this->normalizeFloatValues($value);
            }
        }

        return $data;
    }

    /**
     * Helper method to respond with data.
     *
     * @param mixed $data
     * @param int $status
     * @return Response
     */
    protected function respondWithData($data, int $status = 200): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    /**
     * Helper method to respond with an error.
     *
     * @param string $error
     * @param int $status
     * @return Response
     */
    protected function respondWithError(string $error, int $status = 400): Response
    {
        $response = new SlimResponse();
        $errorData = ['error' => $error];
        $response->getBody()->write(json_encode($errorData, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    /**
     * Method to handle internal server errors.
     *
     * @param string $message
     * @return Response
     */
    protected function internalServerError(string $message): Response
    {
        return $this->respondWithError($message, 500);
    }

    /**
     * A helper to handle successful responses with a message.
     *
     * @param string $message
     * @param int $status
     * @return Response
     */
    protected function respondWithMessage(string $message, int $status = 200): Response
    {
        return $this->respondWithData(['message' => $message], $status);
    }

    /**
     * Helper for responding with validation errors.
     *
     * @param array $errors
     * @return Response
     */
    protected function respondWithValidationError(array $errors): Response
    {
        return $this->respondWithData([
            'error'  => 'Validation failed',
            'errors' => $errors,
        ], 422);
    }
}
