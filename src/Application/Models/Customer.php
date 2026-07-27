<?php

declare(strict_types=1);

namespace App\Application\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $table = 'customers';

    protected $appends = ['display_name'];

    protected $casts = [
        'shipping_same_as_billing' => 'boolean',
    ];

    public function getDisplayNameAttribute(): string
    {
        $name = trim((string) $this->first_name . ' ' . (string) $this->last_name);

        return $this->company ?: $name;
    }
}
