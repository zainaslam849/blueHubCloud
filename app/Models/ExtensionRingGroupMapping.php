<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ExtensionRingGroupMapping Model
 * 
 * Maps extensions to ring groups (queues) and departments.
 * Used when PBX API doesn't provide ring_group directly in CDR.
 * 
 * Can be:
 * 1. Auto-populated from PBXware API endpoints (if available)
 * 2. Manually maintained by admins via dashboard
 * 
 * Fields:
 * - server_id: Which server/tenant this mapping belongs to
 * - extension: Extension number (e.g., "101", "201")
 * - ring_group: Group/queue name (e.g., "Sales Queue", "Support Escalation")
 * - department: Department category for routing
 * - is_active: Whether this mapping is active
 */
class ExtensionRingGroupMapping extends Model
{
    protected $table = 'extension_ring_group_mappings';

    protected $fillable = [
        'company_id',
        'server_id',
        'extension',
        'ring_group',
        'department',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the company this mapping belongs to.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Scope: active mappings only.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: filter by server.
     */
    public function scopeForServer($query, string $serverId)
    {
        return $query->where('server_id', $serverId);
    }

    /**
     * Scope: filter by company.
     */
    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
