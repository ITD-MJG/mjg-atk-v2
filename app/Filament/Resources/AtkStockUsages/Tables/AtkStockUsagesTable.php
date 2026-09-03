<?php

namespace App\Filament\Resources\AtkStockUsages\Tables;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkExportStockUsagePdfAction;
use App\Filament\Actions\ExportStockUsagePdfAction;
use App\Filament\Actions\ResubmitAction;
use App\Filament\Resources\AtkStockUsages\Schemas\AtkStockUsageForm;
use App\Models\AtkStockUsage;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AtkStockUsagesTable
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
                    ->searchable()
                    ->visible(fn () => auth()->user()->isGA() || auth()->user()->isSuperAdmin() || auth()->user()->divisions()->count() > 1),
                TextColumn::make('approval_status')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(fn ($record) => $record->approval_status)
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'submitted' => 'Pending',
                        'pending' => 'On Progress',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        'cancelled' => 'Cancelled',
                        default => ucfirst((string) $state),
                    })
                    ->color(
                        fn (string $state): string => match (true) {
                            str_contains(strtolower($state), 'approved') => 'success',
                            str_contains(strtolower($state), 'rejected') => 'danger',
                            default => 'warning',
                        },
                    ),
                TextColumn::make('approved_by.name')
                    ->label('Approved By')
                    ->getStateUsing(fn ($record) => $record->approved_by?->name)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('approvalHistory', function ($q) use ($search) {
                            $q->where('action', 'approved')
                                ->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
                        });
                    }),
            ])
            ->filters([
                SelectFilter::make('division_id')
                    ->label('Division')
                    ->options(fn () => auth()->user()->isGA() || auth()->user()->isSuperAdmin() ? \App\Models\UserDivision::pluck('name', 'id') : auth()->user()->divisions->pluck('name', 'id'))
                    ->visible(fn () => auth()->user()->isGA() || auth()->user()->isSuperAdmin() || auth()->user()->divisions()->count() > 1),
                SelectFilter::make('approval_status')
                    ->label('Approval Status')
                    ->options([
                        'pending' => 'Pending',
                        'partially_approved' => 'On Progress',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['value'],
                            function (Builder $query, $value): Builder {
                                // Map user-facing statuses to the actions ApprovalProcessingService
                                // actually writes to approval_histories: 'submitted' (awaiting first
                                // approval), 'pending' (step approved, flow still in progress).
                                $action = match ($value) {
                                    'pending' => 'submitted',
                                    'partially_approved' => 'pending',
                                    default => $value,
                                };

                                return $query->whereLatestApprovalAction($action);
                            }
                        );
                    }),
            ])
            ->recordActions([
                ExportStockUsagePdfAction::make(),
                ViewAction::make(),
                EditAction::make()
                    ->successNotificationTitle('Penggunaan stok ATK berhasil diperbarui')
                    ->mutateFormDataUsing(function (array $data) {
                        $data['division_id'] = $data['division_id'] ?? auth()->user()->divisions->first()?->id;

                        return $data;
                    })
                    ->authorize(static function ($record) {
                        $user = auth()->user();

                        return $user && $user->id === $record->requester_id;
                    }),
                ApprovalAction::makeApprove()->successNotification(
                    Notification::make()
                        ->title('Penggunaan stok ATK berhasil disetujui')
                        ->success(),
                ),
                ApprovalAction::makeReject()->successNotification(
                    Notification::make()
                        ->title('Penggunaan stok ATK berhasil ditolak')
                        ->success(),
                ),
                ResubmitAction::make()
                    // Use mountUsing() to fill the form with the record's attributes
                    ->mountUsing(
                        fn (Schema $schema, $record) => $schema->fill(
                            $record->toArray(),
                        ),
                    )
                    ->form(
                        fn (Schema $schema) => AtkStockUsageForm::configure(
                            $schema,
                        ),
                    ),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkExportStockUsagePdfAction::make(),
                    DeleteBulkAction::make()
                        ->successNotificationTitle('Penggunaan stok ATK berhasil dihapus'),
                ]),
            ]);
    }
}
