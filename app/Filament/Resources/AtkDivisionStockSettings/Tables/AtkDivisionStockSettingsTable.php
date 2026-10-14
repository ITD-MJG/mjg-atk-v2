<?php

namespace App\Filament\Resources\AtkDivisionStockSettings\Tables;

use App\Models\AtkDivisionStockSetting;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
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

class AtkDivisionStockSettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $query->with(['division', 'item', 'item.category']);
                $user = auth()->user();

                if ($user->hasRole('Super Admin') || $user->hasRole('Admin') || $user->isGA()) {
                    // Super Admin, Admin, or GA division users can see all records
                } else {
                    $query->whereIn('division_id', $user->divisions->pluck('id'));
                }
            })
            ->columns([
                TextColumn::make('item.name')
                    ->label('Item')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('item.category.name')
                    ->label('Category')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('division.name')
                    ->label('Division')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('max_limit')
                    ->label('Max Stock Limit')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('division')
                    ->relationship('division', 'name')
                    ->label('Division')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('category')
                    ->relationship('item.category', 'name')
                    ->label('Item Category')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->successNotificationTitle('ATK Division Stock Setting updated')
                    ->hidden(fn () => auth()->user()->hasRole('Admin')),
                DeleteAction::make()
                    ->successNotificationTitle('ATK Division Stock Setting deleted')
                    ->hidden(fn () => auth()->user()->hasRole('Admin')),
            ], position: RecordActionsPosition::BeforeColumns)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->successNotificationTitle('ATK Division Stock Settings deleted')
                        ->hidden(fn () => auth()->user()->hasRole('Admin')),
                    BulkAction::make('set_max_limit')
                        ->label('Set Max')
                        ->icon('heroicon-o-adjustments-horizontal')
                        ->form([
                            TextInput::make('max_limit')
                                ->label('Maximum Limit')
                                ->numeric()
                                ->required()
                                ->minValue(0),
                        ])
                        ->action(function (array $data, $records) {
                            foreach ($records as $record) {
                                $record->update(['max_limit' => $data['max_limit']]);
                            }

                            Notification::make()
                                ->title('Batas maksimal berhasil diperbarui untuk item terpilih')
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->headerActions([
                Action::make('set_global_max_limit')
                    ->label('Set Global Max')
                    ->icon('heroicon-o-globe-alt')
                    ->form([
                        TextInput::make('max_limit')
                            ->label('Maximum Limit')
                            ->numeric()
                            ->required()
                            ->minValue(0),
                    ])
                    ->action(function (array $data) {
                        $user = auth()->user();

                        // Update all stock settings for the current division
                        // If user is Super Admin, Admin, or from GA division, update all records
                        if ($user->hasRole('Super Admin') || $user->hasRole('Admin') || $user->isGA()) {
                            AtkDivisionStockSetting::update(['max_limit' => $data['max_limit']]);
                        } else {
                            AtkDivisionStockSetting::whereIn('division_id', $user->divisions->pluck('id'))
                                ->update(['max_limit' => $data['max_limit']]);
                        }

                        Notification::make()
                            ->title('Batas maksimal global berhasil diperbarui')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
