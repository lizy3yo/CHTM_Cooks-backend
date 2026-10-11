<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Donation extends Model
{
    protected $table = 'donations';

    protected $fillable = [
        'receipt_number',
        'donor_name',
        'item_name',
        'quantity',
        'unit',
        'purpose',
        'date',
        'notes',
        'inventory_action',
        'inventory_item_id',
        'created_by',
        'unit_cost',
        'deed_status',
        'deed_file_url',
        'deed_signed_at',
        'deed_uploaded_by'
    ];

    protected $casts = [
        'quantity' => 'integer',
        'date' => 'datetime',
        'unit_cost' => 'decimal:2',
        'deed_signed_at' => 'datetime'
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
