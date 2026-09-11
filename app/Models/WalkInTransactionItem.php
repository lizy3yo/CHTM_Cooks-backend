<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalkInTransactionItem extends Model
{
    protected $table = 'walk_in_transaction_items';

    public $timestamps = false;

    protected $fillable = [
        'walk_in_transaction_id',
        'item_id',
        'name',
        'category',
        'quantity',
        'inspection_status',
        'inspection_notes',
        'replacement_quantity',
        'due_date',
        'additional_returned',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'replacement_quantity' => 'integer',
        'additional_returned' => 'integer',
        'due_date' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(WalkInTransaction::class, 'walk_in_transaction_id');
    }

    /**
     * The inventory item this line refers to (includes soft-deleted items so
     * older walk-ins still show their photo).
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id')->withTrashed();
    }
}
