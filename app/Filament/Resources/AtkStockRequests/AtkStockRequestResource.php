<?php

namespace App\Filament\Resources\AtkStockRequests;

use App\Filament\Resources\AtkStockRequests\Pages\ApprovalAtkStockRequest;
use App\Filament\Resources\AtkStockRequests\Pages\CreateAtkStockRequest;
use App\Filament\Resources\AtkStockRequests\Pages\EditAtkStockRequest;
use App\Filament\Resources\AtkStockRequests\Pages\ListAtkStockRequests;
use App\Filament\Resources\AtkStockRequests\Pages\ViewAtkStockRequest;
use App\Filament\Resources\AtkStockRequests\Schemas\AtkStockRequestForm;
use App\Filament\Resources\AtkStockRequests\Schemas\AtkStockRequestInfolist;
use App\Filament\Resources\AtkStockRequests\Tables\AtkStockRequestsTable;
use App\Models\AtkStockRequest;
use BackedEnum;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AtkStockRequestResource extends Resource
{
    protected static ?string $model = AtkStockRequest::class;

    protected static ?string $navigationLabel = 'Permintaan ATK';

    protected static ?string $slug = 'atk/stock-requests';

    protected static ?string $modelLabel = 'Permintaan ATK';

    protected static ?string $pluralModelLabel = 'Permintaan ATK';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArrowDownTray;

    public static function getNavigationGroup(): ?string
    {
        return __('filament.navigation.group.atk');
    }

    protected static ?string $recordTitleAttribute = 'request_number';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()
            ->forUser(auth()->user());
    }

    public static function getNavigationItems(): array
    {
        return [
            NavigationItem::make(static::getNavigationLabel())
                ->group(static::getNavigationGroup())
                ->icon(static::getNavigationIcon())
                ->isActiveWhen(fn () => ! request()->routeIs(static::getRouteBaseName().'.approval') && request()->routeIs(static::getRouteBaseName().'.*'))
                ->sort(static::getNavigationSort())
                ->url(static::getNavigationUrl()),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return AtkStockRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AtkStockRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AtkStockRequestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\AtkStockRequests\RelationManagers\AtkStockRequestItemsRelationManager::class,
            \App\Filament\Resources\AtkStockRequests\RelationManagers\AtkStockTransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAtkStockRequests::route('/'),
            'create' => CreateAtkStockRequest::route('/create'),
            'edit' => EditAtkStockRequest::route('/{record}/edit'),
            'view' => ViewAtkStockRequest::route('/view/{record}'),
            'approval' => ApprovalAtkStockRequest::route('/approval'),
        ];
    }
}
