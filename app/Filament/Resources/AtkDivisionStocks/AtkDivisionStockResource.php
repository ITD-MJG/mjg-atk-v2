<?php

namespace App\Filament\Resources\AtkDivisionStocks;

use App\Filament\Resources\AtkDivisionStocks\Pages\ListAtkDivisionStocks;
use App\Filament\Resources\AtkDivisionStocks\Pages\ViewAtkDivisionStock;
use App\Filament\Resources\AtkDivisionStocks\RelationManagers\AtkStockRequestsRelationManager;
use App\Filament\Resources\AtkDivisionStocks\RelationManagers\AtkStockTransactionsRelationManager;
use App\Filament\Resources\AtkDivisionStocks\Schemas\AtkDivisionStockForm;
use App\Filament\Resources\AtkDivisionStocks\Schemas\AtkDivisionStockInfolist;
use App\Filament\Resources\AtkDivisionStocks\Tables\AtkDivisionStocksTable;
use App\Models\AtkDivisionStock;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AtkDivisionStockResource extends Resource
{
    protected static ?string $model = AtkDivisionStock::class;

    protected static ?string $navigationLabel = 'Stok Inventaris';

    protected static ?string $slug = 'atk/inventory-stocks';

    protected static ?string $modelLabel = 'Stok Inventaris ATK';

    protected static ?string $pluralModelLabel = 'Stok Inventaris ATK';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::PaperClip;

    public static function getNavigationGroup(): ?string
    {
        return __('filament.navigation.group.atk');
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()
            ->forUser(auth()->user());
    }

    public static function form(Schema $form): Schema
    {
        return AtkDivisionStockForm::configure($form);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AtkDivisionStockInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AtkDivisionStocksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AtkStockTransactionsRelationManager::class,
            RelationManagers\IncomingFloatingStockRequestsRelationManager::class,
            AtkStockRequestsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAtkDivisionStocks::route('/'),
            'view' => ViewAtkDivisionStock::route('/view/{record}'),
        ];
    }
}
