<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CallCategory extends Model
{
    use SoftDeletes;

    protected $table = 'call_categories';

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'is_enabled',
        'source',
        'status',
        'generated_by_model',
        'generated_at',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'generated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    // Relationships
    public function subCategories()
    {
        return $this->hasMany(SubCategory::class, 'category_id');
    }

    /**
     * Scope: only enabled categories
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope: only disabled categories
     */
    public function scopeDisabled($query)
    {
        return $query->where('is_enabled', false);
    }

    /**
     * Check if this is the "General" category
     */
    public function isGeneral(): bool
    {
        return $this->name === 'General';
    }

    /**
     * Count enabled categories
     */
    public static function countEnabled(): int
    {
        return static::enabled()->count();
    }

    /**
     * Get the last enabled category (for validation)
     */
    public static function getLastEnabled()
    {
        return static::enabled()->first();
    }
}
