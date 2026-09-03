<?php

namespace App\Models;

use App\Traits\StockUsageModelTrait;
use App\Traits\DivisionScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AtkStockUsage extends Model
{
    use DivisionScoped;
    use StockUsageModelTrait;

    protected $fillable = [
        'request_number',
        'requester_id',
        'division_id',
        'notes',
        'request_type',
        'potential_cost',
    ];

    public function getRouteKeyName(): string
    {
        return 'request_number';
    }

    protected $casts = [
        'potential_cost' => 'integer',
    ];

    /**
     * Update potential_cost based on associated items
     */
    public function updatePotentialCost(): void
    {
        $items = $this->atkStockUsageItems;

        $potentialCost = 0;
        foreach ($items as $item) {
            $potentialCost += ($item->quantity * $item->moving_average_cost);
        }

        $this->potential_cost = $potentialCost;
        $this->save();
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function division()
    {
        return $this->belongsTo(UserDivision::class, 'division_id');
    }

    public function atkStockUsageItems()
    {
        return $this->hasMany(AtkStockUsageItem::class, 'usage_id');
    }

    public function approval()
    {
        return $this->morphOne(Approval::class, 'approvable');
    }

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

    /**
     * Generic items relationship for unified approval system
     */
    public function items()
    {
        return $this->hasMany(AtkStockUsageItem::class, 'usage_id');
    }

    /**
     * Get the approval status from the latest approval history
     */
    public function getApprovalStatusAttribute()
    {
        $latestApproval = $this->approvalHistory()
            ->orderBy('performed_at', 'desc')
            ->first();

        if (! $latestApproval) {
            return 'pending'; // Default status if no approval history
        }

        return $latestApproval->action;
    }

    /**
     * Get the user who approved the usage from the latest approval history
     */
    public function getApprovedByAttribute()
    {
        $latestApproval = $this->approvalHistory()
            ->where('action', 'approved')
            ->orderBy('performed_at', 'desc')
            ->first();

        if (! $latestApproval) {
            return null;
        }

        return $latestApproval->user;
    }
}
