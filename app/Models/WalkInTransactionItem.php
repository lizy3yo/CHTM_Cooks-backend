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
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(WalkInTransaction::class, 'walk_in_transaction_id');
    }
}
