<?php

namespace App\Filament\Resources\AtkStockTransactions\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AtkStockTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(
                fn (Builder $query) => $query
                    ->when(! auth()->user()->isSuperAdmin(), fn ($q) => $q->whereIn('division_id', auth()->user()->divisions->pluck('id')))
                    ->orderByDesc('created_at'))
            ->columns([
                TextColumn::make('id')
                    ->label('Transaction ID')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('division.name')
                    ->label('Division')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('item.name')
                    ->label('Item')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'request' => 'success',
                        'usage' => 'danger',
                        'adjustment' => 'warning',
                        'transfer' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('quantity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('unit_cost')
                    ->money('IDR')
                    ->sortable(),
                TextColumn::make('total_cost')
                    ->money('IDR')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                ViewAction::make(),
            ], position: RecordActionsPosition::BeforeColumns)
            ->bulkActions([
                //
            ]);
    }
}
