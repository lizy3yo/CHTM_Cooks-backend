<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A laboratory course from the prospectus that classes are opened for. */
class Course extends Model
{
    protected $fillable = [
        'code',
        'title',
        'program',
        'year_level',
        'semester',
        'lab_units',
        'is_active',
    ];

    protected $casts = [
        'year_level' => 'integer',
        'lab_units' => 'integer',
        'is_active' => 'boolean',
    ];

    public function classCodes(): HasMany
    {
        return $this->hasMany(ClassCode::class);
    }
}
