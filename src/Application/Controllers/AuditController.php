<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Models\AuditLog;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuditController extends Controller
{
    public function list(Request $request): Response
    {
        $filters = $this->validateData($request->getQueryParams(), [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'actor_id' => ['sometimes', 'integer', 'min:1'],
            'action' => ['sometimes', 'string', 'max:100'],
            'entity_type' => ['sometimes', 'string', 'max:150'],
            'entity_id' => ['sometimes', 'string', 'max:100'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'search' => ['sometimes', 'string', 'max:100'],
        ]);

        if ($filters instanceof Response) {
            return $filters;
        }

        $query = AuditLog::query()->orderByDesc('id');
        foreach (['actor_id', 'action', 'entity_type', 'entity_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }
        if (isset($filters['search'])) {
            $search = addcslashes(trim($filters['search']), '%_\\');
            $query->where(function ($builder) use ($search): void {
                $builder->where('action', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $this->respondWithData([
            'audit_logs' => $query->paginate(
                (int) ($filters['per_page'] ?? 25),
                ['*'],
                'page',
                (int) ($filters['page'] ?? 1),
            ),
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $log = AuditLog::query()->find((int) $args['id']);

        return $log instanceof AuditLog
            ? $this->respondWithData(['audit_log' => $log])
            : $this->respondWithError('Audit log not found', 404);
    }
}
