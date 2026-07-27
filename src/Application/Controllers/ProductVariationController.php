<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Models\Product;
use App\Application\Models\ProductAttributeOption;
use App\Application\Models\ProductVariation;
use App\Application\Models\ProductVariationValue;
use App\Application\Services\AuditService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ProductVariationController extends Controller
{
    public function __construct(private AuditService $audit)
    {
    }

    public function store(Request $request, Response $response, array $args): Response
    {
        $product = Product::query()->with('attributes.options')->find((int) $args['productId']);
        if (!$product) {
            return $this->respondWithError('Product not found', 404);
        }

        $data = $this->validateRequest($request, $this->rules());
        if ($data instanceof Response) {
            return $data;
        }
        if (
            isset($data['compare_at_price'])
            && $data['compare_at_price'] !== null
            && (float) $data['compare_at_price'] < (float) $data['price']
        ) {
            return $this->respondWithValidationError([
                'compare_at_price' => ['The compare-at price must be at least the selling price.'],
            ]);
        }
        $valueErrors = $this->validateValues($product, $data['values']);
        if ($valueErrors !== []) {
            return $this->respondWithValidationError($valueErrors);
        }

        $variation = Capsule::connection()->transaction(function () use ($product, $data): ProductVariation {
            $variation = new ProductVariation();
            $variation->product_id = $product->id;
            $this->fillVariation($variation, $data);
            if (!$product->variations()->exists()) {
                $variation->is_default = true;
            }
            if ($variation->is_default) {
                $product->variations()->update(['is_default' => false]);
            }
            $variation->save();
            $this->saveValues($variation, $data['values']);

            return $variation;
        });
        $variation->load('values');
        $this->audit->record(
            $request,
            'product_variation.created',
            ProductVariation::class,
            $variation->id,
            null,
            $variation->toArray(),
        );

        return $this->respondWithData([
            'message' => 'Product variation created successfully',
            'variation' => $variation,
        ], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $product = Product::query()->with('attributes.options')->find((int) $args['productId']);
        $variation = $this->findVariation((int) $args['productId'], (int) $args['id']);
        if (!$product || !$variation) {
            return $this->respondWithError('Product variation not found', 404);
        }

        $data = $this->validateRequest($request, $this->rules($variation));
        if ($data instanceof Response) {
            return $data;
        }
        if ($data === []) {
            return $this->respondWithValidationError(['body' => ['Provide at least one field to update.']]);
        }
        $effectivePrice = (float) ($data['price'] ?? $variation->price);
        if (
            isset($data['compare_at_price'])
            && $data['compare_at_price'] !== null
            && (float) $data['compare_at_price'] < $effectivePrice
        ) {
            return $this->respondWithValidationError([
                'compare_at_price' => ['The compare-at price must be at least the selling price.'],
            ]);
        }
        if (isset($data['values'])) {
            $valueErrors = $this->validateValues($product, $data['values']);
            if ($valueErrors !== []) {
                return $this->respondWithValidationError($valueErrors);
            }
        }

        $oldValues = $variation->toArray();
        Capsule::connection()->transaction(function () use ($variation, $data): void {
            $this->fillVariation($variation, $data);
            if ($variation->is_default) {
                ProductVariation::query()
                    ->where('product_id', $variation->product_id)
                    ->where('id', '!=', $variation->id)
                    ->update(['is_default' => false]);
            }
            $variation->save();
            if (isset($data['values'])) {
                $this->saveValues($variation, $data['values']);
            }
        });
        $variation->load('values');
        $this->audit->record(
            $request,
            'product_variation.updated',
            ProductVariation::class,
            $variation->id,
            $oldValues,
            $variation->toArray(),
        );

        return $this->respondWithData([
            'message' => 'Product variation updated successfully',
            'variation' => $variation,
        ]);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $variation = $this->findVariation((int) $args['productId'], (int) $args['id']);
        if (!$variation) {
            return $this->respondWithError('Product variation not found', 404);
        }

        $oldValues = $variation->toArray();
        $wasDefault = $variation->is_default;
        $productId = $variation->product_id;
        $variation->delete();
        if ($wasDefault) {
            ProductVariation::query()->where('product_id', $productId)->orderBy('id')->limit(1)
                ->update(['is_default' => true]);
        }
        $this->audit->record(
            $request,
            'product_variation.deleted',
            ProductVariation::class,
            $variation->id,
            $oldValues,
        );

        return $this->respondWithMessage('Product variation deleted successfully');
    }

    /** @return array<string, mixed> */
    private function rules(?ProductVariation $variation = null): array
    {
        $presence = $variation ? 'sometimes' : 'required';
        $id = $variation?->id;

        return [
            'name' => [$presence, 'string', 'min:1', 'max:150'],
            'sku' => [
                $presence,
                'string',
                'max:100',
                'unique:product_variations,sku' . ($id ? ",{$id}" : ''),
            ],
            'price' => [$presence, 'numeric', 'min:0', 'max:9999999999.99'],
            'compare_at_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['sometimes', 'integer', 'min:0', 'max:4294967295'],
            'status' => ['sometimes', 'in:active,inactive'],
            'is_default' => ['sometimes', 'boolean'],
            'values' => [$variation ? 'sometimes' : 'required', 'array', 'max:50'],
            'values.*.attribute_id' => ['required', 'integer', 'distinct'],
            'values.*.option_id' => ['sometimes', 'nullable', 'integer'],
            'values.*.value' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $values
     * @return array<string, list<string>>
     */
    private function validateValues(Product $product, array $values): array
    {
        $attributes = $product->attributes->keyBy('id');
        $providedIds = collect($values)->pluck('attribute_id')->map(fn ($id): int => (int) $id)->sort()->values();
        $requiredIds = $attributes->keys()->map(fn ($id): int => (int) $id)->sort()->values();
        if ($providedIds->all() !== $requiredIds->all()) {
            return ['values' => ['Provide one value for every attribute assigned to this product.']];
        }

        foreach ($values as $index => $value) {
            $attribute = $attributes->get((int) $value['attribute_id']);
            if (!$attribute) {
                return ["values.{$index}.attribute_id" => ['This attribute is not assigned to the product.']];
            }
            if (in_array($attribute->type, ['select', 'color'], true)) {
                $optionId = (int) ($value['option_id'] ?? 0);
                if (!$attribute->options->contains('id', $optionId)) {
                    return ["values.{$index}.option_id" => ['Select a valid option for this attribute.']];
                }
            } else {
                $rawValue = trim((string) ($value['value'] ?? ''));
                if ($rawValue === '') {
                    return ["values.{$index}.value" => ['Enter a value for this attribute.']];
                }
                if ($attribute->type === 'number' && !is_numeric($rawValue)) {
                    return ["values.{$index}.value" => ['The attribute value must be numeric.']];
                }
            }
        }

        return [];
    }

    /** @param array<string, mixed> $data */
    private function fillVariation(ProductVariation $variation, array $data): void
    {
        foreach (
            [
            'name', 'sku', 'price', 'compare_at_price', 'stock_quantity', 'status', 'is_default',
            ] as $field
        ) {
            if (array_key_exists($field, $data)) {
                $variation->{$field} = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
            }
        }
        $variation->stock_quantity ??= 0;
        $variation->status ??= 'active';
        $variation->is_default ??= false;
    }

    /** @param array<int, array<string, mixed>> $values */
    private function saveValues(ProductVariation $variation, array $values): void
    {
        $variation->values()->delete();
        foreach ($values as $data) {
            $option = isset($data['option_id'])
                ? ProductAttributeOption::query()->find((int) $data['option_id'])
                : null;
            $value = new ProductVariationValue();
            $value->variation_id = $variation->id;
            $value->attribute_id = (int) $data['attribute_id'];
            $value->option_id = $option?->id;
            $value->value = $option?->value ?? trim((string) ($data['value'] ?? ''));
            $value->save();
        }
    }

    private function findVariation(int $productId, int $variationId): ?ProductVariation
    {
        return ProductVariation::query()->with('values')
            ->where('product_id', $productId)->find($variationId);
    }
}
