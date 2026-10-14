<?php

namespace App\Filament\Resources\AtkFulfillments\Tables;

use App\Enums\FulfillmentStatus;
use App\Models\AtkFulfillment;
use App\Models\AtkStockRequestItem;
use App\Services\FulfillmentService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AtkFulfillmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(
                fn (Builder $query) => $query
                    ->with(['requester', 'division', 'approval', 'atkStockRequestItems.item'])
                    ->orderByDesc('created_at'),
            )
            ->columns([
                TextColumn::make('request_number')
                    ->label('Request Number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('requester.name')
                    ->label('Requester')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('division.name')
                    ->label('Division')
                    ->searchable()
                    ->sortable()
                    ->visible(fn () => auth()->user()->isGA() || auth()->user()->isSuperAdmin() || auth()->user()->divisions()->count() > 1),
                TextColumn::make('fulfillment_status')
                    ->label('Status Pemenuhan')
                    ->badge()
                    ->formatStateUsing(fn (\App\Enums\FulfillmentStatus $state): string => $state->getLabel())
                    ->color(fn (\App\Enums\FulfillmentStatus $state): string => $state->getColor()),
                TextColumn::make('created_at')
                    ->label('Request Date')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('division_id')
                    ->label('Division')
                    ->options(fn () => auth()->user()->isGA() || auth()->user()->isSuperAdmin() ? \App\Models\UserDivision::pluck('name', 'id') : auth()->user()->divisions->pluck('name', 'id'))
                    ->visible(fn () => auth()->user()->isGA() || auth()->user()->isSuperAdmin() || auth()->user()->divisions()->count() > 1),
                SelectFilter::make('fulfillment_status')
                    ->label('Fulfillment Status')
                    ->options(\App\Enums\FulfillmentStatus::class)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['value'],
                            function (Builder $query, $value): Builder {
                                if ($value === 'fulfilled') {
                                    return $query->whereDoesntHave('atkStockRequestItems', function ($q) {
                                        $q->whereRaw('received_quantity < quantity');
                                    });
                                } elseif ($value === 'partially_fulfilled') {
                                    return $query->whereHas('atkStockRequestItems', function ($q) {
                                        $q->where('received_quantity', '>', 0);
                                    })->whereHas('atkStockRequestItems', function ($q) {
                                        $q->whereRaw('received_quantity < quantity');
                                    });
                                } elseif ($value === 'pending') {
                                    return $query->whereDoesntHave('atkStockRequestItems', function ($q) {
                                        $q->where('received_quantity', '>', 0);
                                    });
                                }

                                return $query;
                            }
                        );
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('fulfill')
                    ->label('Fulfill')
                    ->icon(Heroicon::ArchiveBoxArrowDown)
                    ->color('success')
                    ->visible(fn (AtkFulfillment $record): bool => $record->approval?->status === 'approved' &&
                        $record->fulfillment_status !== FulfillmentStatus::Fulfilled &&
                        auth()->user()->can('edit atk-fulfillment')
                    )
                    ->modalWidth(Width::SevenExtraLarge)
                    ->form(fn (AtkFulfillment $record) => [
                        Repeater::make('items')
                            ->label('Items')
                            ->schema([
                                \Filament\Forms\Components\Hidden::make('item_id'),
                                TextInput::make('item_name')
                                    ->label('Item')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('requested')
                                    ->label('Requested')
                                    ->numeric()
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('received')
                                    ->label('Received')
                                    ->numeric()
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('qty')
                                    ->label('Qty to Receive')
                                    ->numeric()
                                    ->required()
                                    ->minValue(1)
                                    ->maxValue(function (callable $get) use ($record) {
                                        $item = $record->atkStockRequestItems->firstWhere('id', $get('item_id'));

                                        return $item ? $item->remaining_quantity : 0;
                                    }),
                                TextInput::make('remaining')
                                    ->label('Remaining')
                                    ->numeric()
                                    ->disabled()
                                    ->dehydrated(false),
                            ])
                            ->columns(5)
                            ->default(fn () => $record->atkStockRequestItems
                                ->filter(fn (AtkStockRequestItem $item) => ! $item->isFullyReceived())
                                ->map(fn (AtkStockRequestItem $item) => [
                                    'item_id' => $item->id,
                                    'item_name' => $item->item?->name,
                                    'requested' => $item->quantity,
                                    'received' => $item->received_quantity,
                                    'remaining' => $item->remaining_quantity,
                                    'qty' => $item->remaining_quantity,
                                ])
                                ->values()
                                ->toArray()),
                        TextInput::make('notes')
                            ->label('Catatan')
                            ->placeholder('Catatan penerimaan stok (opsional)'),
                    ])
                    ->action(function (AtkFulfillment $record, array $data): void {
                        $fulfillmentService = app(FulfillmentService::class);
                        $successCount = 0;
                        $totalQuantity = 0;

                        try {
                            foreach ($data['items'] as $itemData) {
                                $item = $record->atkStockRequestItems->firstWhere('id', $itemData['item_id']);
                                if (! $item || $item->isFullyReceived() || $itemData['qty'] <= 0) {
                                    continue;
                                }

                                $fulfillmentService->receiveItem($item, $itemData['qty'], $data['notes'] ?? null);
                                $totalQuantity += $itemData['qty'];
                                $successCount++;
                            }

                            if ($successCount > 0) {
                                $fulfillmentService->notifyRequester($record, $totalQuantity, null, $data['notes'] ?? null);
                                Notification::make()
                                    ->title("$successCount Item Berhasil Disimpan")
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Tidak Ada Item yang Diproses')
                                    ->warning()
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Gagal Menyimpan Stok')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ], position: RecordActionsPosition::BeforeColumns)
            ->toolbarActions([
                // No bulk actions needed for now
            ]);
    }
}
