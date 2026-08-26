<?php

namespace App\Filament\Actions;

use App\Exports\AtkStockUsagePdfExport;
use Filament\Actions\BulkAction;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

class BulkExportStockUsagePdfAction
{
    /**
     * Bulk "Export PDF" action that downloads the selected AtkStockUsages.
     */
    public static function make(string $name = 'bulk_export_pdf'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Export PDF')
            ->icon(fn () => Heroicon::ArrowDownTray)
            ->color('danger')
            ->action(function (Collection $records) {
                return AtkStockUsagePdfExport::download(
                    $records->pluck('id')->all(),
                );
            });
    }
}
