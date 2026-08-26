<?php

namespace App\Filament\Resources\AtkStockUsages\Pages;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\ExportStockUsagePdfAction;
use App\Filament\Actions\ResubmitAction;
use App\Filament\Resources\AtkStockUsages\AtkStockUsageResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAtkStockUsage extends ViewRecord
{
    protected static string $resource = AtkStockUsageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ExportStockUsagePdfAction::make(),
            EditAction::make()
                ->successNotificationTitle('ATK stock usage updated'),
            ApprovalAction::makeApprove(),
            ApprovalAction::makeReject(),
            ResubmitAction::make(),
        ];
    }
}
