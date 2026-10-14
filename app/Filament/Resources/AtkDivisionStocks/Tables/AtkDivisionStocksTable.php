<?php

namespace App\Filament\Resources\AtkDivisionStocks\Tables;

use App\Filament\Actions\BulkMoveToFloatingAction;
use App\Filament\Resources\AtkDivisionStocks\AtkDivisionStockResource;
use App\Models\AtkDivisionStockSetting;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AtkDivisionStocksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(
                fn (Builder $query) => $query->orderByDesc('current_stock'))
            ->columns([
                TextColumn::make('item.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('division.name')
                    ->visible(fn () => auth()->user()->isGA() || auth()->user()->isSuperAdmin() || auth()->user()->divisions()->count() > 1),
                TextColumn::make('current_stock')->numeric()->sortable(),
                TextColumn::make('moving_average_cost')
                    ->label('Average Cost')
                    ->numeric()
                    ->sortable()
                    ->money('IDR', locale: 'id'),
                TextColumn::make('max_limit')
                    ->label('Max Stock Limit')
                    ->numeric()
                    ->getStateUsing(function ($record) {
                        $setting = AtkDivisionStockSetting::where('division_id', $record->division_id)
                            ->where('item_id', $record->item_id)
                            ->first();

                        return $setting ? $setting->max_limit : 'N/A';
                    }),
                TextColumn::make('stock_status')
                    ->label('Stock Status')
                    ->getStateUsing(function ($record) {
                        $setting = AtkDivisionStockSetting::where('division_id', $record->division_id)
                            ->where('item_id', $record->item_id)
                            ->first();

                        if (! $setting) {
                            return 'No setting';
                        }

                        if ($record->current_stock > $setting->max_limit) {
                            return 'Over limit';
                        } elseif ($record->current_stock == $setting->max_limit) {
                            return 'At limit';
                        } elseif ($record->current_stock < $setting->max_limit) {
                            return 'Within limit';
                        } else {
                            return 'Empty';
                        }
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Over limit' => 'danger',
                        'At limit' => 'warning',
                        'Within limit' => 'success',
                        'Empty' => 'danger',
                        'No setting' => 'gray',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('stock_availability')
                    ->label('Stock Availability')
                    ->options([
                        'empty' => 'Empty (0)',
                        'not_empty' => 'Not Empty (>0)',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['value'],
                            fn (Builder $query, $value) => match ($value) {
                                'empty' => $query->where('current_stock', 0),
                                'not_empty' => $query->where('current_stock', '>', 0),
                                default => $query,
                            },
                        );
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->successRedirectUrl(fn () => AtkDivisionStockResource::getUrl('index', ['tab' => request('tab')]))
                    ->successNotificationTitle('Stok Divisi ATK berhasil diperbarui')
                    ->hidden(fn () => auth()->user()->hasRole('Admin')),
                Action::make('move_to_floating')
                    ->label('Move to Floating Stock')
                    ->icon('heroicon-o-arrow-right-circle')
                    ->color('warning')
                    ->visible(fn () => auth()->user()->isGA())
                    ->form([
                        TextInput::make('quantity')
                            ->label('Quantity to Move')
                            ->numeric()
                            ->required()
                            ->maxValue(fn ($record) => $record->current_stock),
                    ])
                    ->action(function ($record, array $data) {
                        try {
                            $record->moveToFloating((int) $data['quantity']);

                            Notification::make()
                                ->title('Stok berhasil dipindahkan ke stok umum')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Error moving stock')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ], position: RecordActionsPosition::BeforeColumns)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkMoveToFloatingAction::make(),
                    DeleteBulkAction::make()
                        ->successNotificationTitle('Stok Divisi ATK berhasil dihapus')
                        ->hidden(fn () => auth()->user()->hasRole('Admin')),
                ]),
            ]);
    }
}
