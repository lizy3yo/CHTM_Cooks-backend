<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeletedInventoryItem extends Model
{
    protected $table = 'deleted_inventory_items';

    public $timestamps = false;

    protected $fillable = [
        'original_id',
        'item_data',
        'deleted_by',
        'deleted_by_name',
        'deleted_by_role',
        'deleted_at',
        'scheduled_deletion',
        'reason',
        'ip_address'
    ];

    protected $casts = [
        'item_data' => 'array',
        'deleted_at' => 'datetime',
        'scheduled_deletion' => 'datetime'
    ];

    public function deletedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
