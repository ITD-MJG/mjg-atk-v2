<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AtkTransferStocks\AtkTransferStockResource;
use App\Models\AtkTransferStock;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class AtkTransferStockStatus extends StatsOverviewWidget
{
    protected ?string $heading = 'Transfer ATK';

    protected static ?int $sort = 4;

    protected function getStats(): array
    {
        $user = Auth::user();

        // Initialize counts
        $pendingCount = 0;
        $onProgressCount = 0;
        $approvedCount = 0;

        if ($user) {
            $divisionIds = $user->isSuperAdmin() ? null : $user->divisions->pluck('id');

            // Count pending requests: latest approval history action is 'submitted' (submitted, no approver action yet)
            $pendingCount = AtkTransferStock::when($divisionIds, function ($query) use ($divisionIds) {
                // Either requesting or source division matches user's division
                $query->whereIn('requesting_division_id', $divisionIds)
                    ->orWhereIn('source_division_id', $divisionIds);
            })
                ->whereLatestApprovalAction('submitted')
                ->count();

            // Count approved requests: latest approval history action is 'approved'
            $approvedCount = AtkTransferStock::when($divisionIds, function ($query) use ($divisionIds) {
                // Either requesting or source division matches user's division
                $query->whereIn('requesting_division_id', $divisionIds)
                    ->orWhereIn('source_division_id', $divisionIds);
            })
                ->whereLatestApprovalAction('approved')
                ->count();

            // Count in-progress requests: latest approval history action is 'pending' (step approved, flow ongoing)
            $onProgressCount = AtkTransferStock::when($divisionIds, function ($query) use ($divisionIds) {
                // Either requesting or source division matches user's division
                $query->whereIn('requesting_division_id', $divisionIds)
                    ->orWhereIn('source_division_id', $divisionIds);
            })
                ->whereLatestApprovalAction('pending')
                ->count();
        }

        return [
            Stat::make(__('Pending Transfers'), $pendingCount)
                ->description(__('Waiting for approval'))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('warning')
                ->url(AtkTransferStockResource::getUrl('index', ['tableFilters[status][value]' => 'pending'])),

            Stat::make(__('On Progress'), $onProgressCount)
                ->description(__('Partially approved'))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('info')
                ->url(AtkTransferStockResource::getUrl('index', ['tableFilters[status][value]' => 'partially_approved'])),

            Stat::make(__('Approved'), $approvedCount)
                ->description(__('Successfully approved'))
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success')
                ->url(AtkTransferStockResource::getUrl('index', ['tableFilters[status][value]' => 'approved'])),
        ];
    }
}
