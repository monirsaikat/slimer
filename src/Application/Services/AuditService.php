<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Models\AuditLog;
use App\Application\Models\User;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuditService
{
    /**
     * Audit failures never interrupt the operation being audited.
     *
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     */
    public function record(
        Request $request,
        string $action,
        ?string $entityType = null,
        int|string|null $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null,
        ?User $actor = null,
    ): void {
        try {
            $requestActor = $request->getAttribute('user');
            if ($actor === null && $requestActor instanceof User) {
                $actor = $requestActor;
            }

            $log = new AuditLog();
            $log->actor_id = $actor?->id;
            $log->action = substr($action, 0, 100);
            $log->entity_type = $entityType !== null ? substr($entityType, 0, 150) : null;
            $log->entity_id = $entityId !== null ? substr((string) $entityId, 0, 100) : null;
            $log->description = $description !== null ? substr($description, 0, 500) : null;
            $log->old_values = $this->sanitize($oldValues);
            $log->new_values = $this->sanitize($newValues);
            $log->ip_address = $this->ipAddress($request);
            $log->user_agent = $this->userAgent($request);
            $log->request_id = $this->requestId($request);
            $log->save();
        } catch (\Throwable $exception) {
            // Auditing must not turn a successful API operation into a failure.
            error_log('Audit log write failed: ' . $exception::class);
        }
    }

    /**
     * @param array<string, mixed>|null $values
     * @return array<string, mixed>|null
     */
    private function sanitize(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $sanitized = [];
        foreach ($values as $key => $value) {
            if (preg_match('/password|token|secret|authorization|cookie/i', (string) $key)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            $sanitized[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $sanitized;
    }

    private function ipAddress(Request $request): ?string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        return is_string($ip) ? substr($ip, 0, 45) : null;
    }

    private function userAgent(Request $request): ?string
    {
        $userAgent = trim($request->getHeaderLine('User-Agent'));
        return $userAgent !== '' ? substr($userAgent, 0, 255) : null;
    }

    private function requestId(Request $request): string
    {
        $attribute = $request->getAttribute('request_id');
        if (is_string($attribute) && $attribute !== '') {
            return substr($attribute, 0, 64);
        }

        $requestId = trim($request->getHeaderLine('X-Request-Id'));
        return $requestId !== '' ? substr($requestId, 0, 64) : bin2hex(random_bytes(16));
    }
}
