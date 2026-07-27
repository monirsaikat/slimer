<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CustomerController extends CrudController
{
    protected function modelClass(): string
    {
        return Customer::class;
    }

    protected function resourceName(): string
    {
        return 'customer';
    }

    protected function searchColumns(): array
    {
        return ['customer_number', 'first_name', 'last_name', 'company', 'email', 'phone'];
    }

    protected function filterRules(): array
    {
        return [
            'type' => ['sometimes', 'in:individual,business'],
            'status' => ['sometimes', 'in:lead,active,inactive,blocked'],
        ];
    }

    protected function sortableColumns(): array
    {
        return ['id', 'customer_number', 'first_name', 'last_name', 'company', 'created_at', 'updated_at'];
    }

    protected function defaultSort(): string
    {
        return 'created_at';
    }

    protected function writableFields(): array
    {
        return [
            'first_name', 'last_name', 'company', 'email', 'phone', 'tax_number', 'type', 'status',
            'billing_address', 'billing_city', 'billing_state', 'billing_postal_code', 'billing_country',
            'shipping_same_as_billing', 'shipping_address', 'shipping_city', 'shipping_state',
            'shipping_postal_code', 'shipping_country', 'notes',
        ];
    }

    protected function createRules(): array
    {
        return $this->rules();
    }

    protected function updateRules(Model $model): array
    {
        return $this->rules($model);
    }

    public function stats(Request $request): Response
    {
        return $this->respondWithData([
            'total' => Customer::query()->count(),
            'by_status' => [
                'lead' => Customer::query()->where('status', 'lead')->count(),
                'active' => Customer::query()->where('status', 'active')->count(),
                'inactive' => Customer::query()->where('status', 'inactive')->count(),
                'blocked' => Customer::query()->where('status', 'blocked')->count(),
            ],
            'by_type' => [
                'individual' => Customer::query()->where('type', 'individual')->count(),
                'business' => Customer::query()->where('type', 'business')->count(),
            ],
        ]);
    }

    protected function applyData(Model $model, array $data): void
    {
        parent::applyData($model, $data);

        foreach ($this->writableFields() as $field) {
            if ($field === 'shipping_same_as_billing') {
                continue;
            }
            if ($model->getAttribute($field) === '') {
                $model->setAttribute($field, null);
            }
        }

        if (array_key_exists('email', $data) && is_string($data['email'])) {
            $email = trim($data['email']);
            $model->setAttribute('email', $email === '' ? null : strtolower($email));
        }

        if ($model->getAttribute('shipping_same_as_billing')) {
            foreach (['address', 'city', 'state', 'postal_code', 'country'] as $field) {
                $model->setAttribute('shipping_' . $field, null);
            }
        }
    }

    protected function beforeCreate(Model $model, array $data, Request $request): void
    {
        $model->setAttribute('customer_number', 'CUS-' . strtoupper(bin2hex(random_bytes(4))));
        $model->setAttribute('type', $model->getAttribute('type') ?: 'individual');
        $model->setAttribute('status', $model->getAttribute('status') ?: 'active');
        $model->setAttribute(
            'shipping_same_as_billing',
            $model->getAttribute('shipping_same_as_billing') ?? true,
        );
    }

    private function rules(?Model $customer = null): array
    {
        $presence = $customer ? 'sometimes' : 'required';
        $id = $customer?->getKey();

        return [
            'first_name' => [
                $presence, 'string', 'min:2', 'max:80', "regex:/^[\\pL\\pM][\\pL\\pM\\s.'-]*$/u",
            ],
            'last_name' => [
                $presence, 'string', 'min:2', 'max:80', "regex:/^[\\pL\\pM][\\pL\\pM\\s.'-]*$/u",
            ],
            'company' => ['sometimes', 'nullable', 'string', 'max:150'],
            'email' => [
                'sometimes', 'nullable', 'string', 'email:rfc', 'max:150',
                'unique:customers,email' . ($id ? ",{$id}" : ''),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[0-9+() .-]+$/'],
            'tax_number' => ['sometimes', 'nullable', 'string', 'max:80'],
            'type' => [$customer ? 'sometimes' : 'required', 'in:individual,business'],
            'status' => [$customer ? 'sometimes' : 'required', 'in:lead,active,inactive,blocked'],
            'billing_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'billing_city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'billing_state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'billing_postal_code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'billing_country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'shipping_same_as_billing' => ['sometimes', 'boolean'],
            'shipping_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'shipping_city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'shipping_state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'shipping_postal_code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'shipping_country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
