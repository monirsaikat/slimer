<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

abstract class CrudController extends Controller
{
    public function __construct(protected AuditService $audit)
    {
    }

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** @return array<string, mixed> */
    abstract protected function createRules(): array;

    /** @return array<string, mixed> */
    abstract protected function updateRules(Model $model): array;

    /** @return list<string> */
    abstract protected function writableFields(): array;

    /** @return list<string> */
    protected function searchColumns(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function filterRules(): array
    {
        return [];
    }

    /** @return list<string> */
    protected function sortableColumns(): array
    {
        return ['id'];
    }

    protected function defaultSort(): string
    {
        return 'id';
    }

    protected function resourceName(): string
    {
        $parts = explode('\\', $this->modelClass());
        return strtolower((string) end($parts));
    }

    public function index(Request $request): Response
    {
        $sortable = $this->sortableColumns();
        $rules = array_merge([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', 'in:' . implode(',', $sortable)],
            'direction' => ['sometimes', 'in:asc,desc'],
        ], $this->filterRules());

        $filters = $this->validateData($request->getQueryParams(), $rules);
        if ($filters instanceof Response) {
            return $filters;
        }

        $query = $this->newQuery();
        $searchColumns = $this->searchColumns();
        if (isset($filters['search']) && $searchColumns !== []) {
            $search = addcslashes(trim($filters['search']), '%_\\');
            $query->where(function (Builder $builder) use ($search, $searchColumns): void {
                foreach ($searchColumns as $index => $column) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $builder->{$method}($column, 'like', "%{$search}%");
                }
            });
        }

        foreach (array_keys($this->filterRules()) as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        $items = $query
            ->orderBy($filters['sort'] ?? $this->defaultSort(), $filters['direction'] ?? 'desc')
            ->paginate(
                (int) ($filters['per_page'] ?? 15),
                ['*'],
                'page',
                (int) ($filters['page'] ?? 1),
            );

        return $this->respondWithData([$this->resourceName() . 's' => $items]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $model = $this->findModel((int) $args['id']);

        return $model === null
            ? $this->respondWithError(ucfirst($this->resourceName()) . ' not found', 404)
            : $this->respondWithData([$this->resourceName() => $this->transform($model)]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validateRequest($request, $this->createRules());
        if ($data instanceof Response) {
            return $data;
        }

        $class = $this->modelClass();
        $model = new $class();
        $this->applyData($model, $data);
        $this->beforeCreate($model, $data, $request);
        $model->save();
        $this->afterCreate($model, $request);

        $this->audit->record(
            $request,
            $this->resourceName() . '.created',
            $class,
            $model->getKey(),
            null,
            $model->toArray(),
        );

        return $this->respondWithData([
            'message' => ucfirst($this->resourceName()) . ' created successfully',
            $this->resourceName() => $this->transform($model),
        ], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $model = $this->findModel((int) $args['id']);
        if ($model === null) {
            return $this->respondWithError(ucfirst($this->resourceName()) . ' not found', 404);
        }

        $data = $this->validateRequest($request, $this->updateRules($model));
        if ($data instanceof Response) {
            return $data;
        }
        if ($data === []) {
            return $this->respondWithValidationError([
                'body' => ['Provide at least one field to update.'],
            ]);
        }

        $oldValues = $model->toArray();
        $this->applyData($model, $data);
        $this->beforeUpdate($model, $data, $request);
        $model->save();
        $this->afterUpdate($model, $request);

        $this->audit->record(
            $request,
            $this->resourceName() . '.updated',
            $this->modelClass(),
            $model->getKey(),
            $oldValues,
            $model->fresh()?->toArray() ?? $model->toArray(),
        );

        return $this->respondWithData([
            'message' => ucfirst($this->resourceName()) . ' updated successfully',
            $this->resourceName() => $this->transform($model),
        ]);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $model = $this->findModel((int) $args['id']);
        if ($model === null) {
            return $this->respondWithError(ucfirst($this->resourceName()) . ' not found', 404);
        }

        $oldValues = $model->toArray();
        $this->beforeDelete($model, $request);
        $modelId = $model->getKey();
        $model->delete();
        $this->afterDelete($model, $request);

        $this->audit->record(
            $request,
            $this->resourceName() . '.deleted',
            $this->modelClass(),
            $modelId,
            $oldValues,
        );

        return $this->respondWithMessage(ucfirst($this->resourceName()) . ' deleted successfully');
    }

    /** @return Builder<Model> */
    protected function newQuery(): Builder
    {
        $class = $this->modelClass();
        return (new $class())->newQuery();
    }

    protected function findModel(int $id): ?Model
    {
        $model = $this->newQuery()->find($id);
        return $model instanceof Model ? $model : null;
    }

    /** @param array<string, mixed> $data */
    protected function applyData(Model $model, array $data): void
    {
        foreach ($this->writableFields() as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                $model->setAttribute($field, is_string($value) ? trim($value) : $value);
            }
        }
    }

    /** @return array<string, mixed> */
    protected function transform(Model $model): array
    {
        return $model->toArray();
    }

    /** @param array<string, mixed> $data */
    protected function beforeCreate(Model $model, array $data, Request $request): void
    {
    }

    protected function afterCreate(Model $model, Request $request): void
    {
    }

    /** @param array<string, mixed> $data */
    protected function beforeUpdate(Model $model, array $data, Request $request): void
    {
    }

    protected function afterUpdate(Model $model, Request $request): void
    {
    }

    protected function beforeDelete(Model $model, Request $request): void
    {
    }

    protected function afterDelete(Model $model, Request $request): void
    {
    }
}
