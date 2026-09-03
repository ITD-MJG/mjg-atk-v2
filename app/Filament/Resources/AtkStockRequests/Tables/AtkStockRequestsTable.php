<?php

namespace App\Filament\Resources\AtkStockRequests\Tables;

use App\Enums\AtkStockRequestStatus;
use App\Exports\AtkStockRequestExport;
use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\ResubmitAction;
use App\Filament\Resources\AtkStockRequests\Schemas\AtkStockRequestForm;
use App\Models\AtkStockRequest;
use App\Services\ApprovalProcessingService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

class AtkStockRequestsTable
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
                TextColumn::make('status')
                    ->label('Status Request')
                    ->badge()
                    ->formatStateUsing(fn (AtkStockRequestStatus $state): string => $state->getLabel())
                    ->color(fn (AtkStockRequestStatus $state): string => $state->getColor()),
                TextColumn::make('approval_status')
                    ->label('Status Approval')
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
                TextColumn::make('fulfillment_status')
                    ->label('Status Pemenuhan')
                    ->badge()
                    ->formatStateUsing(fn (\App\Enums\FulfillmentStatus $state): string => $state->getLabel())
                    ->color(fn (\App\Enums\FulfillmentStatus $state): string => $state->getColor())
                    ->visible(fn () => auth()->user()->can('view atk-stock-request')),
                TextColumn::make('approved_by.name')
                    ->label('Approved By')
                    ->getStateUsing(fn ($record) => $record->approved_by?->name)
                    ->searchable(),
                TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime(),
            ])
            ->filters([
                SelectFilter::make('division_id')
                    ->label('Division')
                    ->options(fn () => auth()->user()->isGA() || auth()->user()->isSuperAdmin() ? \App\Models\UserDivision::pluck('name', 'id') : auth()->user()->divisions->pluck('name', 'id'))
                    ->visible(fn () => auth()->user()->isGA() || auth()->user()->isSuperAdmin() || auth()->user()->divisions()->count() > 1),
                SelectFilter::make('status')
                    ->options(AtkStockRequestStatus::class),
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
                                return $query->whereHas('approvalHistory', function ($q) use ($value) {
                                    $q->where('id', function ($sub) {
                                        $sub->select('id')
                                            ->from('approval_histories')
                                            ->whereColumn('approvable_id', 'atk_stock_requests.id')
                                            ->where('approvable_type', AtkStockRequest::class)
                                            ->orderByDesc('performed_at')
                                            ->limit(1);
                                    })->where('action', $value);
                                });
                            }
                        );
                    }),
                SelectFilter::make('fulfillment_status')
                    ->label('Fulfillment Status')
                    ->options([
                        'pending' => 'Pending',
                        'partially_fulfilled' => 'Partially Fulfilled',
                        'fulfilled' => 'Fulfilled',
                    ])
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
                EditAction::make()
                    ->modalWidth(Width::SevenExtraLarge)
                    ->authorize(function ($record) {
                        $user = auth()->user();

                        // Must be the requester
                        if ($user->id !== $record->requester_id) {
                            return false;
                        }

                        // If status is Draft, can edit
                        if ($record->status === AtkStockRequestStatus::Draft) {
                            return true;
                        }

                        // If status is Published, check if rejected with allow_resubmission = true
                        $lastRejection = \App\Models\ApprovalHistory::where('approvable_type', AtkStockRequest::class)
                            ->where('approvable_id', $record->id)
                            ->where('action', 'rejected')
                            ->orderBy('performed_at', 'desc')
                            ->first();

                        if (! $lastRejection) {
                            return false;
                        }

                        $step = $lastRejection->step;

                        return $step && $step->allow_resubmission;
                    })
                    ->mutateFormDataUsing(function (array $data, $record, array $arguments) {
                        $data['division_id'] = $data['division_id'] ?? auth()->user()->divisions->first()?->id;

                        if ($arguments['draft'] ?? false) {
                            $data['status'] = AtkStockRequestStatus::Draft;
                        } elseif ($record->status === AtkStockRequestStatus::Draft) {
                            $data['status'] = AtkStockRequestStatus::Published;
                        }

                        return $data;
                    })
                    ->after(function (AtkStockRequest $record) {
                        if ($record->status === AtkStockRequestStatus::Published && ! $record->approval) {
                            app(ApprovalProcessingService::class)->createApproval($record, AtkStockRequest::class);
                        }
                    })
                    ->extraModalFooterActions(fn (EditAction $action): array => [
                        $action->makeModalSubmitAction('publish')
                            ->label('Publish')
                            ->color('success')
                            ->visible(fn ($record) => $record->status === AtkStockRequestStatus::Draft)
                            ->mutateFormDataUsing(function (array $data, $record) {
                                $data['status'] = AtkStockRequestStatus::Published;

                                return $data;
                            })
                            ->after(function (AtkStockRequest $record) {
                                if (! $record->approval) {
                                    app(ApprovalProcessingService::class)->createApproval($record, AtkStockRequest::class);
                                }
                            })
                            ->requiresConfirmation(),
                        $action->makeModalSubmitAction('save_as_draft', arguments: ['draft' => true])
                            ->label('Save as Draft')
                            ->color('gray')
                            ->visible(fn ($record) => $record->status === AtkStockRequestStatus::Published),
                    ])
                    ->successNotificationTitle('Permintaan stok ATK berhasil diperbarui'),
                Action::make('publish')
                    ->label('Publish')
                    ->icon(Heroicon::ArrowUpTray)
                    ->color('success')
                    ->visible(function ($record) {
                        if ($record->status !== AtkStockRequestStatus::Draft) {
                            return false;
                        }

                        $user = auth()->user();

                        // Only Super Admin or the requester may publish a draft
                        return $user->isSuperAdmin() || $user->id === $record->requester_id;
                    })
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        $record->update(['status' => AtkStockRequestStatus::Published]);
                        if (! $record->approval) {
                            app(ApprovalProcessingService::class)->createApproval($record, AtkStockRequest::class);
                        }

                        Notification::make()
                            ->title('Permintaan stok ATK berhasil dipublikasikan')
                            ->success()
                            ->send();
                    }),
                Action::make('unpublish')
                    ->label('Unpublish')
                    ->icon(Heroicon::ArrowDownTray)
                    ->color('gray')
                    ->visible(function ($record) {
                        if (! ($record->status === AtkStockRequestStatus::Published
                            && $record->approval_status === 'pending')) {
                            return false;
                        }

                        $user = auth()->user();

                        // Only Super Admin or the requester may unpublish
                        return $user->isSuperAdmin() || $user->id === $record->requester_id;
                    })
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        $record->update(['status' => AtkStockRequestStatus::Draft]);

                        Notification::make()
                            ->title('Permintaan stok ATK berhasil ditarik menjadi draft')
                            ->success()
                            ->send();
                    }),
                Action::make('export')
                    ->label('Export')
                    ->icon(Heroicon::ArrowDownTray)
                    ->color('success')
                    ->action(fn ($record) => Excel::download(
                        new AtkStockRequestExport($record->id),
                        'atk_stock_request_'.$record->request_number.'.xlsx'
                    )),
                ApprovalAction::makeApprove()->successNotification(
                    Notification::make()
                        ->title('Permintaan stok ATK berhasil disetujui')
                        ->success(),
                ),
                ApprovalAction::makeReject()->successNotification(
                    Notification::make()
                        ->title('Permintaan stok ATK berhasil ditolak')
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
                        fn (Schema $schema) => AtkStockRequestForm::configure(
                            $schema,
                        ),
                    )
                    ->successNotificationTitle('Permintaan stok ATK berhasil dikirim ulang'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->successNotificationTitle('Permintaan stok ATK berhasil dihapus'),
                    BulkAction::make('export')
                        ->label('Export Selected')
                        ->icon(Heroicon::ArrowDownTray)
                        ->color('success')
                        ->action(fn (Collection $records) => Excel::download(
                            new AtkStockRequestExport($records->pluck('id')->toArray()),
                            'atk_stock_requests_'.now()->format('Y-m-d_H-i-s').'.xlsx'
                        )),
                ]),
            ]);
    }
}
