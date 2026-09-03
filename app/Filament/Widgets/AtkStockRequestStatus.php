<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AtkStockRequests\AtkStockRequestResource;
use App\Models\AtkStockRequest;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class AtkStockRequestStatus extends StatsOverviewWidget
{
    protected ?string $heading = 'Permintaan ATK';

    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $user = Auth::user();

        // Initialize counts
        $pendingCount = 0;
        $onProgressCount = 0;
        $approvedCount = 0;
        $fulfilledCount = 0;
        $pendingFulfillmentCount = 0;

        if ($user) {
            $divisionIds = $user->isSuperAdmin() ? null : $user->divisions->pluck('id');

            // Count pending requests: latest approval history action is 'submitted' (submitted, no approver action yet)
            $pendingCount = AtkStockRequest::when($divisionIds, fn ($q) => $q->whereIn('division_id', $divisionIds))
                ->where('status', \App\Enums\AtkStockRequestStatus::Published)
                ->whereLatestApprovalAction('submitted')
                ->count();

            // Count approved requests: latest approval history action is 'approved' (flow complete)
            $approvedCount = AtkStockRequest::when($divisionIds, fn ($q) => $q->whereIn('division_id', $divisionIds))
                ->where('status', \App\Enums\AtkStockRequestStatus::Published)
                ->whereLatestApprovalAction('approved')
                ->count();

            // Count in-progress requests: latest approval history action is 'pending'
            // (at least one step approved, flow still waiting on the next approver)
            $onProgressCount = AtkStockRequest::when($divisionIds, fn ($q) => $q->whereIn('division_id', $divisionIds))
                ->where('status', \App\Enums\AtkStockRequestStatus::Published)
                ->whereLatestApprovalAction('pending')
                ->count();

            // Count fulfilled requests (fully received)
            $fulfilledCount = AtkStockRequest::when($divisionIds, fn ($q) => $q->whereIn('division_id', $divisionIds))
                ->where('status', \App\Enums\AtkStockRequestStatus::Published)
                ->whereHas('approval', fn ($q) => $q->where('status', 'approved'))
                ->get()
                ->filter(fn ($r) => $r->fulfillment_status === \App\Enums\FulfillmentStatus::Fulfilled)
                ->count();

            // Count pending fulfillment (approved but not yet fully received)
            $pendingFulfillmentCount = AtkStockRequest::when($divisionIds, fn ($q) => $q->whereIn('division_id', $divisionIds))
                ->where('status', \App\Enums\AtkStockRequestStatus::Published)
                ->whereHas('approval', fn ($q) => $q->where('status', 'approved'))
                ->get()
                ->filter(fn ($r) => $r->fulfillment_status !== \App\Enums\FulfillmentStatus::Fulfilled)
                ->count();
        }

        return [
            Stat::make(__('Approval: Pending'), $pendingCount)
                ->description(__('Waiting for approval'))
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning')
                ->url(AtkStockRequestResource::getUrl('index', ['tableFilters[approval_status][value]' => 'pending'])),

            Stat::make(__('Approval: In Progress'), $onProgressCount)
                ->description(__('Under review'))
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('info')
                ->url(AtkStockRequestResource::getUrl('index', ['tableFilters[approval_status][value]' => 'partially_approved'])),

            Stat::make(__('Fulfillment: Pending'), $pendingFulfillmentCount)
                ->description(__('Approved but not fully received'))
                ->descriptionIcon('heroicon-m-archive-box')
                ->color('primary')
                ->url(AtkStockRequestResource::getUrl('index', ['tableFilters[approval_status][value]' => 'approved', 'tableFilters[fulfillment_status][value]' => 'pending'])),

            Stat::make(__('Fulfillment: Completed'), $fulfilledCount)
                ->description(__('Fully received'))
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success')
                ->url(AtkStockRequestResource::getUrl('index', ['tableFilters[approval_status][value]' => 'approved', 'tableFilters[fulfillment_status][value]' => 'fulfilled'])),
        ];
    }
}
