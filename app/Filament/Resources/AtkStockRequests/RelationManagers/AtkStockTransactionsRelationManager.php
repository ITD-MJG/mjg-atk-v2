<?php

namespace App\Filament\Resources\AtkStockRequests\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AtkStockTransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'atkStockTransactions';

    protected static ?string $title = 'Riwayat Transaksi Stok';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn ($query) => $query->with(['item', 'division']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Tanggal')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('item.name')
                    ->label('Barang')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tipe')
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
                    ->label('Jumlah')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('unit_cost')
                    ->label('Harga Satuan')
                    ->money('IDR', locale: 'id')
                    ->sortable(),
                TextColumn::make('total_cost')
                    ->label('Total')
                    ->money('IDR', locale: 'id')
                    ->sortable(),
                TextColumn::make('mac_snapshot')
                    ->label('MAC (Snapshot)')
                    ->money('IDR', locale: 'id')
                    ->sortable(),
                TextColumn::make('balance_snapshot')
                    ->label('Saldo Setelah')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('notes')
                    ->label('Catatan')
                    ->limit(40)
                    ->tooltip(fn (?string $state): ?string => $state),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                //
            ])
            ->recordActions([
                //
            ])
            ->toolbarActions([
                //
            ])
            ->defaultSort('created_at', 'desc');
    }
}
