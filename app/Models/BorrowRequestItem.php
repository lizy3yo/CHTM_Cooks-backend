<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BorrowRequestItem extends Model
{
    protected $table = 'borrow_request_items';

    public $timestamps = false;

    protected $fillable = [
        'borrow_request_id',
        'item_id',
        'name',
        'quantity',
        'category',
        'picture',
        'inspection_status',
        'inspection_date',
        'inspected_by',
        'inspection_notes',
        'replacement_quantity',
        'due_date'
    ];

    protected $casts = [
        'quantity' => 'integer',
        'inspection_date' => 'datetime',
        'replacement_quantity' => 'integer',
        'due_date' => 'datetime'
    ];

    public function borrowRequest(): BelongsTo
    {
        return $this->belongsTo(BorrowRequest::class, 'borrow_request_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }
}
