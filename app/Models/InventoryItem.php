<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryItem extends Model
{
    use SoftDeletes;

    protected $table = 'inventory_items';

    protected $fillable = [
        'name',
        'category',
        'category_id',
        'specification',
        'tools_or_equipment',
        'picture',
        'quantity',
        'donations',
        'eom_count',
        'description',
        'status',
        'unit_price',
        'is_required',
        'max_quantity_per_request',
        'archived',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'quantity' => 'integer',
        'donations' => 'integer',
        'eom_count' => 'integer',
        'unit_price' => 'decimal:2',
        'is_required' => 'boolean',
        'max_quantity_per_request' => 'integer',
        'archived' => 'boolean'
    ];

    public function categoryRelationship(): BelongsTo
    {
        return $this->belongsTo(InventoryCategory::class, 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
