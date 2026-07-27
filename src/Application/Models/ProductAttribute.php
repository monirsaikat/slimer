<?php

declare(strict_types=1);

namespace App\Application\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductAttribute extends Model
{
    protected $table = 'product_attributes';

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function options(): HasMany
    {
        return $this->hasMany(ProductAttributeOption::class, 'attribute_id')
            ->orderBy('sort_order')->orderBy('id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'product_attribute_product',
            'attribute_id',
            'product_id',
        );
    }
}
