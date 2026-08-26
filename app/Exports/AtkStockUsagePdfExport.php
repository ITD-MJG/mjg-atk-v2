<?php

namespace App\Exports;

use App\Models\AtkStockUsage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;

class AtkStockUsagePdfExport
{
    /**
     * Generate and download a PDF of the given stock usages.
     *
     * @param  array|int  $usageIds  single id or array of ids
     */
    public static function download(array|int $usageIds)
    {
        if (is_int($usageIds)) {
            $usageIds = [$usageIds];
        }

        $usages = static::resolveUsages($usageIds);

        $pdf = Pdf::loadView('exports.atk-stock-usages-pdf', ['usages' => $usages]);

        $filename = 'atk_stock_usages_'.now()->format('Y-m-d_H-i-s').'.pdf';

        // Use streamDownload instead of $pdf->download(): the latter returns a
        // BinaryFileResponse whose raw PDF bytes cannot be JSON-serialized when
        // the action runs inside a Livewire request, causing
        // "Malformed UTF-8 characters, possibly incorrectly encoded"
        // (see barryvdh/laravel-dompdf#1009).
        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->stream();
        }, $filename);
    }

    /**
     * Load usages with the eager loads needed by the PDF view.
     */
    protected static function resolveUsages(array $ids): Collection
    {
        return AtkStockUsage::query()
            ->with([
                'requester',
                'division',
                'approval',
                'approvalHistory',
                'atkStockUsageItems.item',
                'atkStockUsageItems.category',
            ])
            ->whereIn('id', $ids)
            ->orderByDesc('created_at')
            ->get();
    }
}
