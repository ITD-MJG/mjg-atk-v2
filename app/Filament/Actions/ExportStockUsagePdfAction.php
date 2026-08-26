<?php

namespace App\Filament\Actions;

use App\Exports\AtkStockUsagePdfExport;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class ExportStockUsagePdfAction
{
    /**
     * Per-record "Export PDF" action for a single AtkStockUsage.
     */
    public static function make(string $name = 'export_pdf'): Action
    {
        return Action::make($name)
            ->label('Export PDF')
            ->icon(fn () => Heroicon::ArrowDownTray)
            ->color('danger')
            ->action(function (Model $record) {
                return AtkStockUsagePdfExport::download([$record->getKey()]);
            });
    }
}
