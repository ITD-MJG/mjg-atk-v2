<?php

namespace App\Filament\Resources\AtkCategories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AtkCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                EditAction::make()
                    ->successNotificationTitle('Kategori ATK berhasil diperbarui')
                    ->hidden(fn () => auth()->user()->hasRole('Admin')),
                DeleteAction::make()
                    ->successNotificationTitle('Kategori ATK berhasil dihapus')
                    ->hidden(fn () => auth()->user()->hasRole('Admin')),
            ], position: RecordActionsPosition::BeforeColumns)
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->successNotificationTitle('Kategori ATK berhasil dihapus')
                        ->hidden(fn () => auth()->user()->hasRole('Admin')),
                ]),
            ]);
    }
}
