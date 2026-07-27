<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Models\Product;
use App\Application\Models\ProductVariation;
use App\Application\Models\SalesOrder;
use App\Application\Models\SalesOrderItem;
use App\Application\Models\SalesPayment;
use App\Application\Models\User;
use App\Application\Services\AuditService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;

class SalesOrderController extends Controller
{
    private const STOCK_STATUSES = ['confirmed', 'processing', 'completed'];

    public function __construct(private AuditService $audit)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $this->validateData($request->getQueryParams(), [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:100'],
            'customer_id' => ['sometimes', 'integer', 'exists:customers,id'],
            'status' => ['sometimes', 'in:draft,confirmed,processing,completed,cancelled'],
            'payment_status' => ['sometimes', 'in:unpaid,partial,paid'],
            'sort' => ['sometimes', 'in:id,order_number,grand_total,ordered_at,created_at,updated_at'],
            'direction' => ['sometimes', 'in:asc,desc'],
        ]);
        if ($filters instanceof Response) {
            return $filters;
        }

        $query = SalesOrder::query()->with('customer')->withCount('items')->withSum('completedPayments as paid_total', 'amount');
        if (isset($filters['search'])) {
            $search = addcslashes(trim($filters['search']), '%_\\');
            $query->where(function ($builder) use ($search): void {
                $builder->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customer) use ($search): void {
                        $customer->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhereRaw("CONCAT_WS(' ', first_name, last_name) LIKE ?", ["%{$search}%"])
                            ->orWhere('company', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }
        foreach (['customer_id', 'status', 'payment_status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        return $this->respondWithData(['orders' => $query
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->paginate((int) ($filters['per_page'] ?? 15), ['*'], 'page', (int) ($filters['page'] ?? 1))]);
    }

    public function stats(): Response
    {
        return $this->respondWithData([
            'total' => SalesOrder::query()->count(),
            'draft' => SalesOrder::query()->where('status', 'draft')->count(),
            'open' => SalesOrder::query()->whereIn('status', ['confirmed', 'processing'])->count(),
            'completed' => SalesOrder::query()->where('status', 'completed')->count(),
            'revenue' => (string) SalesOrder::query()->where('status', 'completed')->sum('grand_total'),
            'outstanding' => (string) SalesOrder::query()
                ->whereIn('status', ['confirmed', 'processing', 'completed'])
                ->whereIn('payment_status', ['unpaid', 'partial'])
                ->sum('grand_total'),
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $order = $this->findOrder((int) $args['id']);

        return $order
            ? $this->respondWithData(['order' => $order])
            : $this->respondWithError('Sales order not found', 404);
    }

    public function store(Request $request): Response
    {
        $data = $this->validateRequest($request, $this->rules());
        if ($data instanceof Response) {
            return $data;
        }

        try {
            $order = Capsule::connection()->transaction(function () use ($data, $request): SalesOrder {
                $order = new SalesOrder();
                $this->fillOrder($order, $data);
                $order->order_number = 'SO-' . Carbon::now()->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $order->payment_status = 'unpaid';
                /** @var User|null $actor */
                $actor = $request->getAttribute('user');
                $order->created_by = $actor?->id;
                $order->save();
                $this->replaceItems($order, $data['items']);
                $this->recalculate($order);
                if ($this->usesStock($order->status)) {
                    $this->adjustInventory($order->items, -1);
                    $order->ordered_at ??= Carbon::now();
                    $order->save();
                }
                return $order;
            });
        } catch (RuntimeException $exception) {
            return $this->respondWithError($exception->getMessage(), 409);
        }

        $created = $this->findOrder($order->id);
        $this->audit->record($request, 'sales_order.created', SalesOrder::class, $order->id, null, $created?->toArray());

        return $this->respondWithData(['message' => 'Sales order created successfully', 'order' => $created], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $order = SalesOrder::query()->with('items')->find((int) $args['id']);
        if (!$order) {
            return $this->respondWithError('Sales order not found', 404);
        }
        $data = $this->validateRequest($request, $this->rules($order));
        if ($data instanceof Response) {
            return $data;
        }
        if ($data === []) {
            return $this->respondWithValidationError(['body' => ['Provide at least one field to update.']]);
        }

        $oldValues = $this->findOrder($order->id)?->toArray();
        try {
            Capsule::connection()->transaction(function () use ($order, $data): void {
                if ($this->usesStock($order->status)) {
                    $this->adjustInventory($order->items, 1);
                }
                $this->fillOrder($order, $data);
                $order->save();
                if (array_key_exists('items', $data)) {
                    $this->replaceItems($order, $data['items']);
                }
                $this->recalculate($order);
                $order->load('items');
                if ($this->usesStock($order->status)) {
                    $this->adjustInventory($order->items, -1);
                    $order->ordered_at ??= Carbon::now();
                    $order->save();
                }
            });
        } catch (RuntimeException $exception) {
            return $this->respondWithError($exception->getMessage(), 409);
        }

        $updated = $this->findOrder($order->id);
        $this->audit->record($request, 'sales_order.updated', SalesOrder::class, $order->id, $oldValues, $updated?->toArray());
        return $this->respondWithData(['message' => 'Sales order updated successfully', 'order' => $updated]);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $order = SalesOrder::query()->with('items')->find((int) $args['id']);
        if (!$order) {
            return $this->respondWithError('Sales order not found', 404);
        }
        if ($order->payments()->exists()) {
            return $this->respondWithError('Remove recorded payments before deleting this order', 409);
        }
        $oldValues = $this->findOrder($order->id)?->toArray();
        Capsule::connection()->transaction(function () use ($order): void {
            if ($this->usesStock($order->status)) {
                $this->adjustInventory($order->items, 1);
            }
            $order->delete();
        });
        $this->audit->record($request, 'sales_order.deleted', SalesOrder::class, $order->id, $oldValues);
        return $this->respondWithMessage('Sales order deleted successfully');
    }

    public function addPayment(Request $request, Response $response, array $args): Response
    {
        $order = SalesOrder::query()->with('completedPayments')->find((int) $args['id']);
        if (!$order) {
            return $this->respondWithError('Sales order not found', 404);
        }
        if (in_array($order->status, ['draft', 'cancelled'], true)) {
            return $this->respondWithError('Payments can only be recorded for active orders', 409);
        }
        $data = $this->validateRequest($request, [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'method' => ['required', 'in:cash,card,bank_transfer,mobile_wallet,other'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:150'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'paid_at' => ['sometimes', 'date'],
        ]);
        if ($data instanceof Response) {
            return $data;
        }
        $paid = (float) $order->completedPayments->sum('amount');
        $outstanding = max(0, (float) $order->grand_total - $paid);
        if ((float) $data['amount'] > $outstanding + 0.001) {
            return $this->respondWithValidationError(['amount' => ['Amount exceeds the outstanding balance.']]);
        }

        $payment = Capsule::connection()->transaction(function () use ($order, $data, $request): SalesPayment {
            $payment = new SalesPayment();
            $payment->order_id = $order->id;
            $payment->amount = $data['amount'];
            $payment->method = $data['method'];
            $payment->reference = isset($data['reference']) ? trim((string) $data['reference']) ?: null : null;
            $payment->notes = isset($data['notes']) ? trim((string) $data['notes']) ?: null : null;
            $payment->status = 'completed';
            $payment->paid_at = $data['paid_at'] ?? Carbon::now();
            /** @var User|null $actor */
            $actor = $request->getAttribute('user');
            $payment->created_by = $actor?->id;
            $payment->save();
            $this->refreshPaymentStatus($order);
            return $payment;
        });
        $this->audit->record($request, 'sales_payment.created', SalesPayment::class, $payment->id, null, $payment->toArray());
        return $this->respondWithData(['message' => 'Payment recorded successfully', 'payment' => $payment, 'order' => $this->findOrder($order->id)], 201);
    }

    public function destroyPayment(Request $request, Response $response, array $args): Response
    {
        $order = SalesOrder::query()->find((int) $args['id']);
        $payment = $order?->payments()->find((int) $args['paymentId']);
        if (!$order || !$payment) {
            return $this->respondWithError('Payment not found', 404);
        }
        $oldValues = $payment->toArray();
        Capsule::connection()->transaction(function () use ($payment, $order): void {
            $payment->delete();
            $this->refreshPaymentStatus($order);
        });
        $this->audit->record($request, 'sales_payment.deleted', SalesPayment::class, $payment->id, $oldValues);
        return $this->respondWithMessage('Payment removed successfully');
    }

    private function rules(?SalesOrder $order = null): array
    {
        $presence = $order ? 'sometimes' : 'required';
        return [
            'customer_id' => [$presence, 'integer', 'exists:customers,id'],
            'status' => [$order ? 'sometimes' : 'required', 'in:draft,confirmed,processing,completed,cancelled'],
            'currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'order_discount' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'shipping_total' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'items' => [$order ? 'sometimes' : 'required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variation_id' => ['sometimes', 'nullable', 'integer', 'exists:product_variations,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'items.*.discount_amount' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'items.*.tax_amount' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
        ];
    }

    private function fillOrder(SalesOrder $order, array $data): void
    {
        foreach (['customer_id', 'status', 'currency', 'order_discount', 'shipping_total', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
                $order->{$field} = $field === 'currency' ? strtoupper((string) $value) : ($value === '' ? null : $value);
            }
        }
        $order->status ??= 'draft';
        $order->currency ??= 'USD';
        $order->order_discount ??= 0;
        $order->shipping_total ??= 0;
    }

    private function replaceItems(SalesOrder $order, array $items): void
    {
        $order->items()->delete();
        foreach ($items as $data) {
            $product = Product::query()->with('variations')->findOrFail((int) $data['product_id']);
            $variation = isset($data['variation_id']) ? $product->variations->firstWhere('id', (int) $data['variation_id']) : null;
            if (isset($data['variation_id']) && !$variation) {
                throw new RuntimeException("Selected variation does not belong to {$product->name}.");
            }
            $quantity = (int) $data['quantity'];
            $unitPrice = (float) ($data['unit_price'] ?? ($variation?->price ?? $product->base_price));
            $discount = (float) ($data['discount_amount'] ?? 0);
            $tax = (float) ($data['tax_amount'] ?? 0);
            $base = $quantity * $unitPrice;
            if ($discount > $base) {
                throw new RuntimeException("Discount cannot exceed the line subtotal for {$product->name}.");
            }
            $item = new SalesOrderItem();
            $item->order_id = $order->id;
            $item->product_id = $product->id;
            $item->variation_id = $variation?->id;
            $item->product_name = $product->name;
            $item->variation_name = $variation?->name;
            $item->sku = $variation?->sku ?? $product->sku;
            $item->quantity = $quantity;
            $item->unit_price = number_format($unitPrice, 2, '.', '');
            $item->discount_amount = number_format($discount, 2, '.', '');
            $item->tax_amount = number_format($tax, 2, '.', '');
            $item->line_total = number_format($base - $discount + $tax, 2, '.', '');
            $item->save();
        }
        $order->load('items');
    }

    private function recalculate(SalesOrder $order): void
    {
        $order->load('items');
        $subtotal = $order->items->sum(fn ($item) => (int) $item->quantity * (float) $item->unit_price);
        $lineDiscount = (float) $order->items->sum('discount_amount');
        $tax = (float) $order->items->sum('tax_amount');
        $discountTotal = $lineDiscount + (float) $order->order_discount;
        if ($discountTotal > $subtotal) {
            throw new RuntimeException('Total discounts cannot exceed the order subtotal.');
        }
        $grandTotal = max(0, $subtotal - $discountTotal + $tax + (float) $order->shipping_total);
        $paid = (float) $order->completedPayments()->sum('amount');
        if ($paid > $grandTotal + 0.001) {
            throw new RuntimeException('Order total cannot be reduced below the amount already paid.');
        }
        $order->subtotal = number_format($subtotal, 2, '.', '');
        $order->discount_total = number_format($discountTotal, 2, '.', '');
        $order->tax_total = number_format($tax, 2, '.', '');
        $order->grand_total = number_format($grandTotal, 2, '.', '');
        $order->save();
        $this->refreshPaymentStatus($order);
    }

    private function refreshPaymentStatus(SalesOrder $order): void
    {
        $paid = (float) $order->completedPayments()->sum('amount');
        $total = (float) $order->grand_total;
        $order->payment_status = $paid <= 0 ? 'unpaid' : ($paid + 0.001 >= $total ? 'paid' : 'partial');
        $order->save();
    }

    private function usesStock(string $status): bool
    {
        return in_array($status, self::STOCK_STATUSES, true);
    }

    private function adjustInventory($items, int $direction): void
    {
        foreach ($items as $item) {
            $product = Product::query()->lockForUpdate()->find($item->product_id);
            if (!$product || !$product->track_inventory) {
                continue;
            }
            $quantity = (int) $item->quantity;
            if ($item->variation_id) {
                $stock = ProductVariation::query()->lockForUpdate()->find($item->variation_id);
                if (!$stock) {
                    throw new RuntimeException("Variation for {$item->product_name} is no longer available.");
                }
            } else {
                $stock = $product;
            }
            $next = (int) $stock->stock_quantity + ($direction * $quantity);
            if ($next < 0) {
                throw new RuntimeException("Insufficient stock for {$item->product_name}.");
            }
            $stock->stock_quantity = $next;
            $stock->save();
        }
    }

    private function findOrder(int $id): ?SalesOrder
    {
        return SalesOrder::query()
            ->with(['customer', 'items', 'payments'])
            ->withSum('completedPayments as paid_total', 'amount')
            ->find($id);
    }
}
