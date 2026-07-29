<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WalkInTransaction extends Model
{
    protected $table = 'walk_in_transactions';

    protected $fillable = [
        'reference',
        'student_id',
        'student_name',
        'student_identifier',
        'email',
        'class_code',
        'purpose',
        'usage_location',
        'borrow_date',
        'return_date',
        'status',
        'returned_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'borrow_date' => 'datetime',
        'return_date' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(WalkInTransactionItem::class, 'walk_in_transaction_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
