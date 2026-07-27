<?php

declare(strict_types=1);

namespace App\Application\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $table = 'products';

    protected $casts = [
        'base_price' => 'decimal:2',
        'track_inventory' => 'boolean',
        'stock_quantity' => 'integer',
    ];

    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductAttribute::class,
            'product_attribute_product',
            'product_id',
            'attribute_id',
        )->withPivot('sort_order')->orderByPivot('sort_order');
    }

    public function variations(): HasMany
    {
        return $this->hasMany(ProductVariation::class)->orderByDesc('is_default')->orderBy('id');
    }
}
