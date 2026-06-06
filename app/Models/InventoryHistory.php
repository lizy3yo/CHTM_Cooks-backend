<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryHistory extends Model
{
    protected $table = 'inventory_history';

    public $timestamps = false;

    protected $fillable = [
        'action',
        'entity_type',
        'entity_id',
        'entity_name',
        'user_id',
        'user_name',
        'user_role',
        'changes',
        'metadata',
        'ip_address',
        'user_agent',
        'timestamp'
    ];

    protected $casts = [
        'changes' => 'array',
        'metadata' => 'array',
        'timestamp' => 'datetime'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
