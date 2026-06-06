<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    protected $table = 'support_tickets';

    protected $fillable = [
        'student_id',
        'owner_role',
        'subject',
        'status',
        'last_message_at',
        'unread_by_superadmin',
        'unread_by_student'
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'unread_by_superadmin' => 'integer',
        'unread_by_student' => 'integer'
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class, 'support_ticket_id');
    }
}
