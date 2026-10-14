<?php

namespace App\Filament\Resources\AtkBudgetings\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AtkBudgetingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(
                fn (Builder $query) => $query
                    ->when(! auth()->user()->isSuperAdmin(), fn ($q) => $q->whereIn('division_id', auth()->user()->divisions->pluck('id')))
                    ->orderBy('fiscal_year', 'desc')
                    ->orderBy('created_at', 'desc'))
            ->columns([
                TextColumn::make('division.name')
                    ->label('Division')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('budget_amount')
                    ->label('Total Budget')
                    ->money('IDR')
                    ->sortable(),
                TextColumn::make('used_amount')
                    ->label('Used Amount')
                    ->money('IDR')
                    ->sortable(),
                TextColumn::make('remaining_amount')
                    ->label('Remaining Amount')
                    ->money('IDR')
                    ->sortable(),
                TextColumn::make('fiscal_year')
                    ->label('Fiscal Year')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('budget_utilization')
                    ->label('Utilization %')
                    ->getStateUsing(function ($record) {
                        if ($record->budget_amount == 0) {
                            return '0%';
                        }
                        $utilization = ($record->used_amount / $record->budget_amount) * 100;

                        return number_format($utilization, 2).'%';
                    })
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                EditAction::make()
                    ->successNotificationTitle('ATK Budgeting updated'),
                ViewAction::make(),
            ], position: RecordActionsPosition::BeforeColumns)
            ->bulkActions([
                //
            ]);
    }
}
