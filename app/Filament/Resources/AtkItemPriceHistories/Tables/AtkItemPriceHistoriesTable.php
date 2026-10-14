<?php

namespace App\Filament\Resources\AtkItemPriceHistories\Tables;

use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

class AtkItemPriceHistoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('item.name')
                    ->label('Item')
                    ->sortable()
                    ->searchable(),
                \Filament\Tables\Columns\TextColumn::make('old_price')
                    ->label('Old Price')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => $state ? 'Rp '.number_format($state, 0, ',', '.') : 'N/A'),
                \Filament\Tables\Columns\TextColumn::make('new_price')
                    ->label('New Price')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => 'Rp '.number_format($state, 0, ',', '.')),
                \Filament\Tables\Columns\TextColumn::make('effective_date')
                    ->label('Effective Date')
                    ->date()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('user.name')
                    ->label('Changed By')
                    ->sortable()
                    ->searchable(),
                \Filament\Tables\Columns\TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
            ], position: RecordActionsPosition::BeforeColumns)
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make()
                        ->successNotificationTitle('ATK Item Price Histories deleted')
                        ->hidden(fn () => auth()->user()->hasRole('Admin')),
                ]),
            ]);
    }
}
