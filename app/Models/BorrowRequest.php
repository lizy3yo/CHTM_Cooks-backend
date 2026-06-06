<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BorrowRequest extends Model
{
    protected $table = 'borrow_requests';

    protected $fillable = [
        'student_id',
        'instructor_id',
        'custodian_id',
        'class_code_id',
        'purpose',
        'usage_location',
        'borrow_date',
        'return_date',
        'status',
        'reject_reason',
        'rejection_notes',
        'appeal_reason',
        'appealed_at',
        'appeal_count',
        'approved_at',
        'rejected_at',
        'released_at',
        'picked_up_at',
        'missing_at',
        'resolved_at',
        'last_reminder_at',
        'reminder_count',
        'returned_at',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'borrow_date' => 'datetime',
        'return_date' => 'datetime',
        'appealed_at' => 'datetime',
        'appeal_count' => 'integer',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'released_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'missing_at' => 'datetime',
        'resolved_at' => 'datetime',
        'last_reminder_at' => 'datetime',
        'reminder_count' => 'integer',
        'returned_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    public function classCode(): BelongsTo
    {
        return $this->belongsTo(ClassCode::class, 'class_code_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BorrowRequestItem::class, 'borrow_request_id');
    }
}
