<?php

namespace App\Filament\Resources\AtkItems\Tables;

use App\Models\AtkDivisionStock;
use App\Models\UserDivision;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AtkItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('category.name')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('unit_of_measure')
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make()
                    ->successNotificationTitle('Item ATK berhasil diperbarui')
                    ->hidden(fn () => auth()->user()->hasRole('Admin'))
                    ->using(function ($record, array $data) {
                        $selectedDivisions = $data['divisions'] ?? [];
                        unset($data['divisions']);

                        $record->update($data);

                        // If no divisions selected, add to all divisions
                        if (empty($selectedDivisions)) {
                            $userDivisions = UserDivision::all();
                        } else {
                            $userDivisions = UserDivision::whereIn('id', $selectedDivisions)->get();
                        }

                        foreach ($userDivisions as $userDivision) {
                            // Check if stock record already exists for this user division
                            $existingStock = AtkDivisionStock::where([
                                'item_id' => $record->id,
                                'division_id' => $userDivision->id,
                            ])->first();

                            if (! $existingStock) {
                                AtkDivisionStock::create([
                                    'item_id' => $record->id,
                                    'category_id' => $record->category_id,
                                    'division_id' => $userDivision->id,
                                    'current_stock' => 0,
                                ]);
                            }
                        }

                        return $record;
                    }),
                DeleteAction::make()
                    ->successNotificationTitle('Item ATK berhasil dihapus')
                    ->hidden(fn () => auth()->user()->hasRole('Admin')),
            ], position: RecordActionsPosition::BeforeColumns)
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->successNotificationTitle('Item ATK berhasil dihapus')
                        ->hidden(fn () => auth()->user()->hasRole('Admin')),
                ]),
            ]);
    }
}
