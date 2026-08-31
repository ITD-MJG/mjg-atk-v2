<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AtkRequestFromFloatingStocks\AtkRequestFromFloatingStockResource;
use App\Filament\Resources\AtkStockRequests\AtkStockRequestResource;
use App\Filament\Resources\AtkStockUsages\AtkStockUsageResource;
use Filament\Widgets\Widget;

class QuickCreate extends Widget
{
    protected string $view = 'filament.widgets.quick-create';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user && (
            $user->can('create atk-stock-request') ||
            $user->can('create atk-stock-usage') ||
            $user->can('create atk-request-from-floating-stock')
        );
    }

    public function getActions(): array
    {
        $user = auth()->user();

        $actions = [];

        if ($user->can('create atk-stock-request')) {
            $actions[] = [
                'label' => 'Permintaan ATK',
                'url' => AtkStockRequestResource::getUrl('create'),
                'icon' => 'heroicon-o-arrow-down-tray',
                'color' => 'primary',
            ];
        }

        if ($user->can('create atk-stock-usage')) {
            $actions[] = [
                'label' => 'Pengeluaran ATK',
                'url' => AtkStockUsageResource::getUrl('create'),
                'icon' => 'heroicon-o-arrow-up-tray',
                'color' => 'warning',
            ];
        }

        if ($user->can('create atk-request-from-floating-stock')) {
            $actions[] = [
                'label' => 'Minta Stok Umum',
                'url' => AtkRequestFromFloatingStockResource::getUrl('create'),
                'icon' => 'heroicon-o-arrow-top-right-on-square',
                'color' => 'success',
            ];
        }

        return $actions;
    }
}
