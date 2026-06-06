<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReplacementObligation extends Model
{
    protected $table = 'replacement_obligations';

    protected $fillable = [
        'borrow_request_id',
        'student_id',
        'item_id',
        'item_name',
        'item_category',
        'quantity',
        'type',
        'status',
        'amount',
        'amount_paid',
        'resolution_type',
        'resolution_date',
        'resolution_notes',
        'payment_reference',
        'incident_date',
        'incident_notes',
        'due_date',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'quantity' => 'integer',
        'amount' => 'integer',
        'amount_paid' => 'integer',
        'resolution_date' => 'datetime',
        'incident_date' => 'datetime',
        'due_date' => 'datetime'
    ];

    public function borrowRequest(): BelongsTo
    {
        return $this->belongsTo(BorrowRequest::class, 'borrow_request_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
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
