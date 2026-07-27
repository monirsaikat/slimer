<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Models\Product;
use App\Application\Models\ProductVariationValue;
use App\Application\Services\AuditService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ProductController extends Controller
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
            'status' => ['sometimes', 'in:draft,active,archived'],
            'sort' => ['sometimes', 'in:id,name,base_price,stock_quantity,created_at,updated_at'],
            'direction' => ['sometimes', 'in:asc,desc'],
        ]);
        if ($filters instanceof Response) {
            return $filters;
        }

        $query = Product::query()->withCount('variations');
        if (isset($filters['search'])) {
            $search = addcslashes(trim($filters['search']), '%_\\');
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $products = $query
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->paginate(
                (int) ($filters['per_page'] ?? 15),
                ['*'],
                'page',
                (int) ($filters['page'] ?? 1),
            );

        return $this->respondWithData(['products' => $products]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $product = $this->findProduct((int) $args['id']);

        return $product
            ? $this->respondWithData(['product' => $product])
            : $this->respondWithError('Product not found', 404);
    }

    public function store(Request $request): Response
    {
        $data = $this->validateRequest($request, $this->rules());
        if ($data instanceof Response) {
            return $data;
        }

        $product = Capsule::connection()->transaction(function () use ($data): Product {
            $product = new Product();
            $this->fillProduct($product, $data);
            $product->save();
            $this->syncAttributes($product, $data['attribute_ids'] ?? []);

            return $product;
        });
        $product = $this->findProduct($product->id);
        $this->audit->record(
            $request,
            'product.created',
            Product::class,
            $product?->id,
            null,
            $product?->toArray(),
        );

        return $this->respondWithData([
            'message' => 'Product created successfully',
            'product' => $product,
        ], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $product = Product::query()->with('attributes')->find((int) $args['id']);
        if (!$product) {
            return $this->respondWithError('Product not found', 404);
        }

        $data = $this->validateRequest($request, $this->rules($product));
        if ($data instanceof Response) {
            return $data;
        }
        if ($data === []) {
            return $this->respondWithValidationError(['body' => ['Provide at least one field to update.']]);
        }

        $oldValues = $product->toArray();
        Capsule::connection()->transaction(function () use ($product, $data): void {
            $this->fillProduct($product, $data);
            $product->save();
            if (array_key_exists('attribute_ids', $data)) {
                $this->syncAttributes($product, $data['attribute_ids']);
            }
        });
        $updated = $this->findProduct($product->id);
        $this->audit->record(
            $request,
            'product.updated',
            Product::class,
            $product->id,
            $oldValues,
            $updated?->toArray(),
        );

        return $this->respondWithData([
            'message' => 'Product updated successfully',
            'product' => $updated,
        ]);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $product = $this->findProduct((int) $args['id']);
        if (!$product) {
            return $this->respondWithError('Product not found', 404);
        }

        $oldValues = $product->toArray();
        $product->delete();
        $this->audit->record($request, 'product.deleted', Product::class, $product->id, $oldValues);

        return $this->respondWithMessage('Product deleted successfully');
    }

    /** @return array<string, mixed> */
    private function rules(?Product $product = null): array
    {
        $id = $product?->id;
        $presence = $product ? 'sometimes' : 'required';

        return [
            'name' => [$presence, 'string', 'min:2', 'max:150'],
            'slug' => [
                $presence,
                'string',
                'max:160',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                'unique:products,slug' . ($id ? ",{$id}" : ''),
            ],
            'sku' => ['sometimes', 'nullable', 'string', 'max:100', 'unique:products,sku' . ($id ? ",{$id}" : '')],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'status' => ['sometimes', 'in:draft,active,archived'],
            'base_price' => [$presence, 'numeric', 'min:0', 'max:9999999999.99'],
            'currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'track_inventory' => ['sometimes', 'boolean'],
            'stock_quantity' => ['sometimes', 'integer', 'min:0', 'max:4294967295'],
            'attribute_ids' => ['sometimes', 'array', 'max:50'],
            'attribute_ids.*' => ['integer', 'distinct', 'exists:product_attributes,id'],
        ];
    }

    /** @param array<string, mixed> $data */
    private function fillProduct(Product $product, array $data): void
    {
        foreach (
            [
            'name', 'slug', 'sku', 'description', 'status', 'base_price',
            'currency', 'track_inventory', 'stock_quantity',
            ] as $field
        ) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                if (is_string($value)) {
                    $value = trim($value);
                }
                if ($field === 'sku' && $value === '') {
                    $value = null;
                }
                $product->{$field} = $field === 'currency' && is_string($value) ? strtoupper($value) : $value;
            }
        }
        $product->status ??= 'draft';
        $product->currency ??= 'USD';
        $product->track_inventory ??= true;
        $product->stock_quantity ??= 0;
    }

    /** @param array<int, int|string> $attributeIds */
    private function syncAttributes(Product $product, array $attributeIds): void
    {
        $sync = [];
        foreach (array_values($attributeIds) as $index => $attributeId) {
            $sync[(int) $attributeId] = ['sort_order' => $index];
        }
        $product->attributes()->sync($sync);
        $variationIds = $product->variations()->pluck('id');
        if ($variationIds->isNotEmpty()) {
            $values = ProductVariationValue::query()->whereIn('variation_id', $variationIds);
            if ($sync === []) {
                $values->delete();
            } else {
                $values->whereNotIn('attribute_id', array_keys($sync))->delete();
            }
        }
    }

    private function findProduct(int $id): ?Product
    {
        return Product::query()
            ->with(['attributes.options', 'variations.values'])
            ->withCount('variations')
            ->find($id);
    }
}
