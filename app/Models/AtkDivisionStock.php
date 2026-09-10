<?php

namespace App\Models;

use App\Services\FloatingStockService;
use App\Services\StockTransactionService;
use App\Traits\DivisionScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class AtkDivisionStock extends Model
{
    use DivisionScoped;
    protected $fillable = [
        'division_id',
        'item_id',
        'category_id',
        'current_stock',
        'moving_average_cost',
    ];

    protected $casts = [
        'current_stock' => 'integer',
        'moving_average_cost' => 'integer',
    ];

    /**
     * Move stock from division to floating stock
     */
    public function moveToFloating(int $quantity): void
    {
        if ($quantity <= 0 || $quantity > $this->current_stock) {
            throw new \InvalidArgumentException('Invalid quantity to move.');
        }

        DB::transaction(function () use ($quantity) {
            $floatingService = app(FloatingStockService::class);
            $divisionService = app(StockTransactionService::class);

            $unitCost = $this->moving_average_cost;

            // 1. Reduce from Division Stock
            $this->update(['current_stock' => $this->current_stock - $quantity]);

            // 2. Record Division Transaction
            $divisionService->recordTransactionOnly(
                $this->division_id,
                $this->item_id,
                'transfer',
                -$quantity,
                $unitCost,
                $this
            );

            // 3. Add to Floating Stock
            $floatingService->recordTransaction(
                $this->item_id,
                'in',
                $quantity,
                $unitCost,
                $this,
                $this->division_id
            );
        });
    }

    /**
     * Relationship to get floating stock requests that match this division and item
     */
    public function incomingFloatingStockRequests()
    {
        return $this->hasMany(AtkRequestFromFloatingStockItem::class, 'item_id', 'item_id')
            ->whereHas('request', function ($query) {
                $query->where('division_id', $this->division_id);
            });
    }

    /**
     * Stock requests from this division that include this item.
     *
     * Requests are raised per division and the item lives on the request's
     * lines, so we match the division directly and constrain on the items.
     */
    public function atkStockRequests()
    {
        $itemId = $this->item_id;
        $table = $this->getTable();

        return $this->hasMany(AtkStockRequest::class, 'division_id', 'division_id')
            ->whereHas('atkStockRequestItems', function ($query) use ($itemId, $table) {
                // Instance context (e.g. a Filament relation manager) knows the item
                // up front; a query context such as whereHas() must correlate the
                // column instead, because $this->item_id is null there.
                if ($itemId !== null) {
                    $query->where('atk_stock_request_items.item_id', $itemId);

                    return;
                }

                $query->whereColumn('atk_stock_request_items.item_id', $table.'.item_id');
            });
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(UserDivision::class, 'division_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AtkItem::class, 'item_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AtkCategory::class, 'category_id');
    }

    /**
     * Get the setting for this division-stock combination
     */
    public function getSetting()
    {
        return AtkDivisionStockSetting::where('division_id', $this->division_id)
            ->where('item_id', $this->item_id)
            ->first();
    }

    /**
     * Relationship to get stock transactions that match this division and item
     * This is used by Filament for the relation manager
     */
    public function stockTransactions()
    {
        return $this->hasMany(AtkStockTransaction::class, 'item_id')
            ->where('division_id', $this->division_id);
    }

    /**
     * Get the max_stock_limit from the setting
     */
    public function getMaxStockLimitAttribute(): int
    {
        $setting = $this->getSetting();

        return $setting ? $setting->max_limit : 0;
    }

    /**
     * Check if the stock has reached the maximum limit
     */
    public function hasReachedMaxLimit(): bool
    {
        $setting = $this->getSetting();
        $maxLimit = $setting ? $setting->max_limit : 0;

        return $this->current_stock >= $maxLimit;
    }

    /**
     * Calculate the total value of current stock
     */
    public function getTotalStockValue(): float
    {
        return $this->current_stock * $this->moving_average_cost;
    }

    /**
     * Get the import action for this model
     */
    public static function getImportAction(): \Filament\Actions\Action
    {
        return \App\Filament\Actions\ImportStockAction::make();
    }
}
