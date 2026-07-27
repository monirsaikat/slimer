<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Models\ProductAttribute;
use App\Application\Models\ProductAttributeOption;
use App\Application\Services\AuditService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ProductAttributeController extends Controller
{
    public function __construct(private AuditService $audit)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $this->validateData($request->getQueryParams(), [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:100'],
            'type' => ['sometimes', 'in:select,text,number,color'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);
        if ($filters instanceof Response) {
            return $filters;
        }

        $query = ProductAttribute::query()->with('options')->withCount('products');
        if (isset($filters['search'])) {
            $search = addcslashes(trim($filters['search']), '%_\\');
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }
        foreach (['type', 'status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        $attributes = $query->orderBy('sort_order')->orderBy('name')->paginate(
            (int) ($filters['per_page'] ?? 20),
            ['*'],
            'page',
            (int) ($filters['page'] ?? 1),
        );

        return $this->respondWithData(['attributes' => $attributes]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $attribute = ProductAttribute::query()->with('options')->withCount('products')->find((int) $args['id']);

        return $attribute
            ? $this->respondWithData(['attribute' => $attribute])
            : $this->respondWithError('Product attribute not found', 404);
    }

    public function store(Request $request): Response
    {
        $data = $this->validateRequest($request, $this->rules());
        if ($data instanceof Response) {
            return $data;
        }
        if (in_array($data['type'], ['select', 'color'], true) && empty($data['options'])) {
            return $this->respondWithValidationError([
                'options' => ['Select and color attributes require at least one option.'],
            ]);
        }

        $attribute = Capsule::connection()->transaction(function () use ($data): ProductAttribute {
            $attribute = new ProductAttribute();
            $this->fillAttribute($attribute, $data);
            $attribute->save();
            $this->saveOptions($attribute, $data['options'] ?? []);

            return $attribute;
        });
        $attribute->load('options');
        $this->audit->record(
            $request,
            'product_attribute.created',
            ProductAttribute::class,
            $attribute->id,
            null,
            $attribute->toArray(),
        );

        return $this->respondWithData([
            'message' => 'Product attribute created successfully',
            'attribute' => $attribute,
        ], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $attribute = ProductAttribute::query()->with('options')->find((int) $args['id']);
        if (!$attribute) {
            return $this->respondWithError('Product attribute not found', 404);
        }

        $data = $this->validateRequest($request, $this->rules($attribute));
        if ($data instanceof Response) {
            return $data;
        }
        if ($data === []) {
            return $this->respondWithValidationError(['body' => ['Provide at least one field to update.']]);
        }
        $effectiveType = $data['type'] ?? $attribute->type;
        $effectiveOptions = $data['options'] ?? $attribute->options->toArray();
        if (in_array($effectiveType, ['select', 'color'], true) && $effectiveOptions === []) {
            return $this->respondWithValidationError([
                'options' => ['Select and color attributes require at least one option.'],
            ]);
        }

        $oldValues = $attribute->toArray();
        $updated = Capsule::connection()->transaction(function () use ($attribute, $data): ProductAttribute {
            $this->fillAttribute($attribute, $data);
            $attribute->save();
            if (array_key_exists('options', $data) || !in_array($attribute->type, ['select', 'color'], true)) {
                $this->saveOptions($attribute, $data['options'] ?? []);
            }

            return $attribute;
        });
        $updated->load('options');
        $this->audit->record(
            $request,
            'product_attribute.updated',
            ProductAttribute::class,
            $updated->id,
            $oldValues,
            $updated->toArray(),
        );

        return $this->respondWithData([
            'message' => 'Product attribute updated successfully',
            'attribute' => $updated,
        ]);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $attribute = ProductAttribute::query()->with('options')->find((int) $args['id']);
        if (!$attribute) {
            return $this->respondWithError('Product attribute not found', 404);
        }

        $oldValues = $attribute->toArray();
        $attribute->delete();
        $this->audit->record(
            $request,
            'product_attribute.deleted',
            ProductAttribute::class,
            $attribute->id,
            $oldValues,
        );

        return $this->respondWithMessage('Product attribute deleted successfully');
    }

    /** @return array<string, mixed> */
    private function rules(?ProductAttribute $attribute = null): array
    {
        $id = $attribute?->id;
        $type = $attribute?->type;
        $optionsRule = in_array($type, ['select', 'color'], true)
            ? ['sometimes', 'array', 'min:1', 'max:100']
            : ['sometimes', 'array', 'max:100'];

        return [
            'name' => [$attribute ? 'sometimes' : 'required', 'string', 'min:2', 'max:100'],
            'slug' => [
                $attribute ? 'sometimes' : 'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                'unique:product_attributes,slug' . ($id ? ",{$id}" : ''),
            ],
            'type' => [$attribute ? 'sometimes' : 'required', 'in:select,text,number,color'],
            'status' => ['sometimes', 'in:active,inactive'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'options' => $optionsRule,
            'options.*.id' => ['sometimes', 'nullable', 'integer', 'distinct'],
            'options.*.label' => ['required_with:options', 'string', 'min:1', 'max:100'],
            'options.*.value' => [
                'required_with:options',
                'string',
                'min:1',
                'max:100',
                'distinct',
            ],
            'options.*.sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ];
    }

    /** @param array<string, mixed> $data */
    private function fillAttribute(ProductAttribute $attribute, array $data): void
    {
        foreach (['name', 'slug', 'type', 'status', 'sort_order'] as $field) {
            if (array_key_exists($field, $data)) {
                $attribute->{$field} = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
            }
        }
        $attribute->status ??= 'active';
        $attribute->sort_order ??= 0;
    }

    /** @param array<int, array<string, mixed>> $options */
    private function saveOptions(ProductAttribute $attribute, array $options): void
    {
        $ids = array_values(array_filter(array_map(
            fn (array $option): ?int => isset($option['id']) ? (int) $option['id'] : null,
            $options,
        )));
        if (
            $ids !== [] && ProductAttributeOption::query()
            ->where('attribute_id', $attribute->id)->whereIn('id', $ids)->count() !== count($ids)
        ) {
            throw new \InvalidArgumentException('An attribute option does not belong to this attribute.');
        }

        $attribute->options()->when($ids !== [], fn ($query) => $query->whereNotIn('id', $ids))->delete();
        foreach ($options as $index => $data) {
            $option = isset($data['id'])
                ? ProductAttributeOption::query()->findOrFail((int) $data['id'])
                : new ProductAttributeOption();
            $option->attribute_id = $attribute->id;
            $option->label = trim((string) $data['label']);
            $option->value = trim((string) $data['value']);
            $option->sort_order = (int) ($data['sort_order'] ?? $index);
            $option->save();
        }
    }
}
