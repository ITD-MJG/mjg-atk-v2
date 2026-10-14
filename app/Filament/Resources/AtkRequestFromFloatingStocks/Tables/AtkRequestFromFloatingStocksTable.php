<?php

namespace App\Filament\Resources\AtkRequestFromFloatingStocks\Tables;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\ResubmitAction;
use App\Filament\Resources\AtkRequestFromFloatingStocks\Schemas\AtkRequestFromFloatingStockForm;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AtkRequestFromFloatingStocksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(
                fn (Builder $query) => $query
                    ->with(['requester', 'division', 'approval', 'approvalHistory'])
                    ->when(! auth()->user()->isSuperAdmin(), fn ($q) => $q->whereIn('division_id', auth()->user()->divisions->pluck('id')))
                    ->orderByDesc('created_at'),
            )
            ->columns([
                TextColumn::make('request_number')
                    ->label('Request Number')
                    ->searchable(),
                TextColumn::make('requester.name')
                    ->label('Requester')
                    ->searchable(),
                TextColumn::make('division.name')
                    ->label('Division')
                    ->searchable(),
                TextColumn::make('approval_status')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(fn ($record) => $record->approval_status)
                    ->formatStateUsing(fn ($state) => ucfirst($state))
                    ->color(
                        fn (string $state): string => match (true) {
                            str_contains(strtolower($state), 'approved') => 'success',
                            str_contains(strtolower($state), 'rejected') => 'danger',
                            default => 'warning',
                        },
                    ),
                TextColumn::make('created_at')
                    ->label('Request Date')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->modalWidth(Width::SevenExtraLarge)
                    ->mutateFormDataUsing(function (array $data) {
                        $data['division_id'] = $data['division_id'] ?? auth()->user()->divisions->first()?->id;

                        return $data;
                    })
                    ->authorize(static function ($record) {
                        $user = auth()->user();

                        return $user && $user->id === $record->requester_id && $record->approval_status === 'pending';
                    }),
                ApprovalAction::makeApprove()->successNotification(
                    Notification::make()
                        ->title('Permintaan disetujui')
                        ->success(),
                ),
                ApprovalAction::makeReject()->successNotification(
                    Notification::make()
                        ->title('Permintaan ditolak')
                        ->success(),
                ),
                ResubmitAction::make()
                    ->mountUsing(fn (Schema $schema, $record) => $schema->fill($record->toArray()))
                    ->form(fn (Schema $schema) => AtkRequestFromFloatingStockForm::configure($schema))
                    ->successNotificationTitle('Permintaan dikirim ulang'),
            ], position: RecordActionsPosition::BeforeColumns)
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
