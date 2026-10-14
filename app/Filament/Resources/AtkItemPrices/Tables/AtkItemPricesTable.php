<?php

namespace App\Filament\Resources\AtkItemPrices\Tables;

use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AtkItemPricesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $query->latest('created_at');
            })
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('item.name')
                    ->label('Item')
                    ->sortable()
                    ->searchable(),
                \Filament\Tables\Columns\TextColumn::make('category.name')
                    ->label('Category')
                    ->sortable()
                    ->searchable(),
                \Filament\Tables\Columns\TextColumn::make('unit_price')
                    ->label('Unit Price')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => 'Rp '.number_format($state, 0, ',', '.')),
                \Filament\Tables\Columns\TextColumn::make('effective_date')
                    ->label('Effective Date')
                    ->date()
                    ->sortable(),
                \Filament\Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('is_active')
                    ->options([
                        1 => 'Active',
                        0 => 'Inactive',
                    ])
                    ->label('Status'),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\EditAction::make()
                    ->successNotificationTitle('ATK Item Price updated')
                    ->hidden(fn () => auth()->user()->hasRole('Admin')),
            ], position: RecordActionsPosition::BeforeColumns)
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make()
                        ->successNotificationTitle('ATK Item Prices deleted')
                        ->hidden(fn () => auth()->user()->hasRole('Admin')),
                ]),
            ]);
    }
}
