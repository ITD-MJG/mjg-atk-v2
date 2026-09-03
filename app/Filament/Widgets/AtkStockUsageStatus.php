<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AtkStockUsages\AtkStockUsageResource;
use App\Models\AtkStockUsage;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class AtkStockUsageStatus extends StatsOverviewWidget
{
    protected ?string $heading = 'Pengeluaran ATK';

    protected static ?int $sort = 3;

    protected function getStats(): array
    {
        $user = Auth::user();

        // Initialize counts
        $pendingCount = 0;
        $onProgressCount = 0;
        $approvedCount = 0;

        if ($user) {
            $divisionIds = $user->isSuperAdmin() ? null : $user->divisions->pluck('id');

            // Count records whose latest approval history action is 'submitted' (submitted, no approver action yet)
            $pendingCount = AtkStockUsage::when($divisionIds, fn ($q) => $q->whereIn('division_id', $divisionIds))
                ->whereLatestApprovalAction('submitted')
                ->count();

            // Count records whose latest approval history action is 'pending' (in progress)
            $onProgressCount = AtkStockUsage::when($divisionIds, fn ($q) => $q->whereIn('division_id', $divisionIds))
                ->whereLatestApprovalAction('pending')
                ->count();

            // Count records whose latest approval history action is 'approved'
            $approvedCount = AtkStockUsage::when($divisionIds, fn ($q) => $q->whereIn('division_id', $divisionIds))
                ->whereLatestApprovalAction('approved')
                ->count();
        }

        return [
            Stat::make(__('Pending Requests'), $pendingCount)
                ->description(__('Waiting for approval'))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('warning')
                ->url(AtkStockUsageResource::getUrl('index', ['tableFilters[approval_status][value]' => 'pending'])),

            Stat::make(__('On Progress'), $onProgressCount)
                ->description(__('Partially approved'))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('info')
                ->url(AtkStockUsageResource::getUrl('index', ['tableFilters[approval_status][value]' => 'partially_approved'])),

            Stat::make(__('Approved'), $approvedCount)
                ->description(__('Successfully approved'))
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success')
                ->url(AtkStockUsageResource::getUrl('index', ['tableFilters[approval_status][value]' => 'approved'])),
        ];
    }
}
