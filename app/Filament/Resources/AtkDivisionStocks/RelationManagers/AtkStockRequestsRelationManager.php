<?php

namespace App\Filament\Resources\AtkDivisionStocks\RelationManagers;

use App\Filament\Resources\AtkStockRequests\AtkStockRequestResource;
use App\Models\AtkStockRequest;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AtkStockRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'atkStockRequests';

    protected static ?string $title = 'Permintaan Stok ATK';

    public function form(Schema $schema): Schema
    {
        return $schema;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('request_number')
            ->modifyQueryUsing(fn ($query) => $query->with(['requester', 'division', 'approval', 'approvalHistory', 'atkStockRequestItems']))
            ->columns([
                TextColumn::make('request_number')
                    ->label('Nomor Permintaan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('requester.name')
                    ->label('Pemohon')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label('Jumlah')
                    ->numeric()
                    ->getStateUsing(fn (AtkStockRequest $record, RelationManager $livewire): int => (int) $record->atkStockRequestItems
                        ->where('item_id', $livewire->getOwnerRecord()->item_id)
                        ->sum('quantity')),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state->getLabel())
                    ->color(fn ($state): string => $state->getColor())
                    ->sortable(),
                TextColumn::make('approval_status')
                    ->label('Persetujuan')
                    ->badge()
                    ->getStateUsing(fn (AtkStockRequest $record): string => $record->approval_status)
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->color(
                        fn (string $state): string => match (true) {
                            str_contains($state, 'approved') => 'success',
                            str_contains($state, 'rejected') => 'danger',
                            str_contains($state, 'pending') => 'warning',
                            default => 'gray',
                        },
                    ),
                TextColumn::make('fulfillment_status')
                    ->label('Pemenuhan')
                    ->badge()
                    ->getStateUsing(fn (AtkStockRequest $record): string => $record->fulfillment_status->value)
                    ->formatStateUsing(fn (AtkStockRequest $record): string => $record->fulfillment_status->getLabel())
                    ->color(fn (AtkStockRequest $record): string => $record->fulfillment_status->getColor()),
                TextColumn::make('created_at')
                    ->label('Tanggal Permintaan')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                // Read-only
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (AtkStockRequest $record): string => AtkStockRequestResource::getUrl('view', [
                        'record' => $record,
                    ])),
            ])
            ->toolbarActions([
                // Read-only
            ])
            ->defaultSort('created_at', 'desc');
    }
}
