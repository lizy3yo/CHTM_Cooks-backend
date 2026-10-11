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
        'due_date',
        'good_quantity',
        'damaged_quantity',
        'missing_quantity',
        'over_returned_quantity',
        'inspection_remarks',
        'inspection_photos'
    ];

    protected $casts = [
        'quantity' => 'integer',
        'inspection_date' => 'datetime',
        'replacement_quantity' => 'integer',
        'due_date' => 'datetime',
        'good_quantity' => 'integer',
        'damaged_quantity' => 'integer',
        'missing_quantity' => 'integer',
        'over_returned_quantity' => 'integer',
        'inspection_photos' => 'array'
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
