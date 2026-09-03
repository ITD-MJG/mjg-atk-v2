<?php

namespace App\Models;

use App\Traits\StockTransferModelTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtkTransferStock extends Model
{
    use HasFactory, StockTransferModelTrait;

    protected $fillable = [
        'transfer_number',
        'requester_id',
        'requesting_division_id',
        'source_division_id',
        'notes',
    ];

    protected $casts = [
        'requester_id' => 'integer',
        'requesting_division_id' => 'integer',
        'source_division_id' => 'integer',
    ];

    // Relationship with the requesting division
    public function requestingDivision(): BelongsTo
    {
        return $this->belongsTo(UserDivision::class, 'requesting_division_id');
    }

    // Relationship with the source division
    public function sourceDivision(): BelongsTo
    {
        return $this->belongsTo(UserDivision::class, 'source_division_id');
    }

    // Relationship with the source divisions (for backward compatibility)
    public function sourceDivisions()
    {
        // Return a collection with just the single source division
        return $this->sourceDivision ? collect([$this->sourceDivision]) : collect();
    }

    // Relationship with transfer stock items
    public function transferStockItems(): HasMany
    {
        return $this->hasMany(AtkTransferStockItem::class, 'transfer_stock_id');
    }

    // Relationship with the user who requested
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * Generic division relationship for unified approval system
     */
    public function division(): BelongsTo
    {
        return $this->requestingDivision();
    }

    /**
     * Generic items relationship for unified approval system
     */
    public function items(): HasMany
    {
        return $this->transferStockItems();
    }

    // Accessor to get count of source divisions
    public function getSourceDivisionsCountAttribute()
    {
        return $this->sourceDivisions()->count();
    }

    // Accessor to get unique source divisions count
    public function getSourceDivisionsUniqueCountAttribute()
    {
        return $this->sourceDivision ? 1 : 0; // Since we now have a single source division
    }

    // Accessor for approval status with detailed information
    public function getApprovalStatusAttribute()
    {
        $approval = $this->approval;
        if (! $approval) {
            return 'Pending';
        }

        // Get the latest approval step approval
        $latestApproval = $approval
            ->approvalStepApprovals()
            ->with(['user.divisions', 'step.division'])
            ->latest('approved_at')
            ->first();

        if ($latestApproval) {
            $status = ucfirst($latestApproval->status);
            $user = $latestApproval->user;
            $step = $latestApproval->step;
            $division = $step?->division ?? $user?->divisions->first();

            if ($user && $division) {
                // Get division's initial and user's first role name
                $divisionInitial = $division->initial ?? 'N/A';
                $roleNames = $user->getRoleNames();
                $role = $roleNames->first() ?? 'N/A';

                return "{$status} by {$divisionInitial} {$role}";
            } else {
                return $status;
            }
        }

        return $approval->status
            ? ucfirst($approval->status)
            : 'Pending';
    }

    // Relationship with approval
    public function approval(): \Illuminate\Database\Eloquent\Relations\MorphOne
    {
        return $this->morphOne(Approval::class, 'approvable');
    }

    // Relationship with approval history
    public function approvalHistory()
    {
        return $this->morphMany(ApprovalHistory::class, 'approvable');
    }

    /**
     * Scope to records whose latest approval history action equals the given value.
     *
     * History actions written by ApprovalProcessingService:
     * 'submitted' (initial/resubmitted), 'pending' (step approved, flow still in progress),
     * 'approved' (flow complete), 'rejected', 'cancelled'.
     */
    public function scopeWhereLatestApprovalAction(Builder $query, string $action): Builder
    {
        return $query->whereHas('approvalHistory', function ($q) use ($action): void {
            $q->where('id', function ($sub) {
                $sub->select('id')
                    ->from('approval_histories')
                    ->whereColumn('approvable_id', $this->getTable().'.id')
                    ->where('approvable_type', static::class)
                    ->orderByDesc('performed_at')
                    ->orderByDesc('id')
                    ->limit(1);
            })->where('action', $action);
        });
    }

    // Add status attribute if it doesn't exist
    public function getStatusAttribute()
    {
        return $this->approval ? $this->approval->status : 'pending';
    }

    public function setStatusAttribute($value)
    {
        if ($this->approval) {
            $this->approval->update(['status' => $value]);
        }
    }
}
