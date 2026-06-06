<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassCode extends Model
{
    protected $fillable = [
        'code',
        'course_code',
        'course_name',
        'section',
        'academic_year',
        'semester',
        'max_enrollment',
        'is_active',
        'is_archived'
    ];

    protected $casts = [
        'max_enrollment' => 'integer',
        'is_active' => 'boolean',
        'is_archived' => 'boolean'
    ];

    /**
     * Instructors assigned to this class
     */
    public function instructors()
    {
        return $this->belongsToMany(User::class, 'class_code_instructor', 'class_code_id', 'user_id');
    }

    /**
     * Students enrolled in this class
     */
    public function students()
    {
        return $this->belongsToMany(User::class, 'class_code_student', 'class_code_id', 'user_id');
    }
}
