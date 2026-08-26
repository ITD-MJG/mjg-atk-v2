<?php

namespace App\Filament\Resources\AtkStockUsages\Pages;

use App\Exports\AtkStockUsagePdfExport;
use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\ResubmitAction;
use App\Filament\Resources\AtkStockUsages\AtkStockUsageResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewAtkStockUsage extends ViewRecord
{
    protected static string $resource = AtkStockUsageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportPdf')
                ->label('Export PDF')
                ->icon(Heroicon::ArrowDownTray)
                ->color('danger')
                ->action(fn () => AtkStockUsagePdfExport::download([$this->record->id])),
            EditAction::make()
                ->successNotificationTitle('ATK stock usage updated'),
            ApprovalAction::makeApprove(),
            ApprovalAction::makeReject(),
            ResubmitAction::make(),
        ];
    }
}
