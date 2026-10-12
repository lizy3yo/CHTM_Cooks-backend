<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One deed of donation: who gave it, for which program, and the signed copy.
 * The items given under it are `donations` rows pointing back here.
 */
class DonationDeed extends Model
{
    protected $fillable = [
        'reference',
        'representative_name',
        'submitter_email',
        'student_id',
        'program',
        'date_donated',
        'or_number',
        'declared_total',
        'notes',
        'files',
        'submitted_at',
        'encoded_by',
    ];

    protected $casts = [
        'date_donated' => 'date',
        'declared_total' => 'decimal:2',
        'files' => 'array',
        'submitted_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(Donation::class, 'deed_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function encoder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encoded_by');
    }
}
