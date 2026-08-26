<?php

namespace App\Filament\Resources\AtkStockUsages\Pages;

use App\Filament\Resources\AtkStockUsages\AtkStockUsageResource;
use App\Models\AtkStockUsage;
use App\Services\ApprovalProcessingService;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListAtkStockUsages extends ListRecords
{
    protected static string $resource = AtkStockUsageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateFormDataUsing(function (array $data) {
                    $data['division_id'] = $data['division_id'] ?? auth()->user()->divisions->first()?->id;
                    $data['requester_id'] = auth()->user()->id;

                    // Calculate potential_cost from the atkStockUsageItems
                    $potentialCost = 0;

                    if (isset($data['atkStockUsageItems']) && is_array($data['atkStockUsageItems'])) {
                        foreach ($data['atkStockUsageItems'] as $item) {
                            if (isset($item['quantity']) && isset($item['moving_average_cost'])) {
                                $potentialCost += (int) $item['quantity'] * (int) $item['moving_average_cost'];
                            }
                        }
                    }

                    $data['potential_cost'] = $potentialCost;

                    return $data;
                })
                ->after(function (AtkStockUsage $record) {
                    app(ApprovalProcessingService::class)->createApproval($record, AtkStockUsage::class);
                })
                ->visible(fn () => auth()->user()->hasRole('Admin'))
                ->modalWidth(Width::SevenExtraLarge)
                ->successNotification(
                    Notification::make()
                        ->title('Penggunaan stok ATK berhasil dibuat')
                        ->success()
                ),
        ];
    }
}
