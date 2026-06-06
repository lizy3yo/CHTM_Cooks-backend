<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'password',
        'role',
        'first_name',
        'last_name',
        'profile_photo_url',
        'profile_photo_public_id',
        'is_active',
        'last_login',
        'email_verified',
        'email_verification_token',
        'email_verification_expires',
        'password_reset_token',
        'password_reset_expires',
        'year_level',
        'block',
        'agreed_to_terms',
        'trust_score',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'email_verified' => 'boolean',
            'agreed_to_terms' => 'boolean',
            'trust_score' => 'integer',
            'last_login' => 'datetime',
            'email_verification_expires' => 'datetime',
            'password_reset_expires' => 'datetime',
        ];
    }

    /**
     * Classes enrolled in (if user is student)
     */
    public function enrolledClasses()
    {
        return $table = $this->belongsToMany(ClassCode::class, 'class_code_student', 'user_id', 'class_code_id');
    }

    /**
     * Classes instructing (if user is instructor)
     */
    public function instructingClasses()
    {
        return $this->belongsToMany(ClassCode::class, 'class_code_instructor', 'user_id', 'class_code_id');
    }
}
