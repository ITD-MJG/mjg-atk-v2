<?php

namespace App\Filament\Resources\AtkFloatingStocks\Tables;

use App\Filament\Actions\BulkTransferFloatingStockAction;
use App\Models\UserDivision;
use App\Services\FloatingStockService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AtkFloatingStocksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('item.name')
                    ->label('Item')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('current_stock')
                    ->label('Stock')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('moving_average_cost')
                    ->label('MAC')
                    ->money('IDR', locale: 'id')
                    ->sortable(),
                TextColumn::make('total_value')
                    ->label('Total Value')
                    ->money('IDR', locale: 'id')
                    ->state(fn ($record) => $record->getTotalStockValue())
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('transfer')
                    ->label('Transfer to Division')
                    ->icon('heroicon-o-truck')
                    ->color('success')
                    ->form([
                        Select::make('division_id')
                            ->label('Target Division')
                            ->options(fn () => UserDivision::all()->pluck('name', 'id'))
                            ->required()
                            ->searchable(),
                        TextInput::make('quantity')
                            ->label('Transfer Quantity')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(fn ($record) => $record?->current_stock ?? 1000)
                            ->default(fn ($record) => $record?->current_stock),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(3),
                    ])
                    ->action(function ($record, array $data) {
                        try {
                            $record->distributeToDivision(
                                (int) $data['division_id'],
                                (int) $data['quantity'],
                                $data['notes'] ?? null
                            );

                            Notification::make()
                                ->title('Transfer Berhasil')
                                ->body("Berhasil mentransfer {$data['quantity']} unit ke divisi yang dipilih.")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Transfer Gagal')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('adjust')
                    ->label('Adjust Stock')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->form([
                        TextInput::make('quantity')
                            ->label('Adjustment Quantity')
                            ->numeric()
                            ->required()
                            ->helperText('Use positive for addition, negative for reduction.'),
                        TextInput::make('unit_cost')
                            ->label('Unit Cost (for additions)')
                            ->numeric()
                            ->prefix('Rp')
                            ->default(fn ($record) => $record->moving_average_cost),
                    ])
                    ->action(function ($record, array $data, FloatingStockService $service) {
                        $service->recordTransaction(
                            $record->item_id,
                            'adjustment',
                            (int) $data['quantity'],
                            (int) $data['unit_cost']
                        );
                    }),
                EditAction::make()
                    ->successNotificationTitle('Stok Umum ATK berhasil diperbarui')
                    ->hidden(fn () => auth()->user()->hasRole('Admin')),
            ], position: RecordActionsPosition::BeforeColumns)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkTransferFloatingStockAction::make(),
                    DeleteBulkAction::make()
                        ->successNotificationTitle('Stok Umum ATK berhasil dihapus')
                        ->hidden(fn () => auth()->user()->hasRole('Admin')),
                ]),
            ]);
    }
}
