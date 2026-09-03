<?php

namespace App\Filament\Resources\AtkTransferStocks\Tables;

use App\Filament\Actions\ApprovalAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AtkTransferStocksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                if (! auth()->user()->isSuperAdmin()) {
                    $query->whereIn('requesting_division_id', auth()->user()->divisions->pluck('id'));
                }
                $query->orderByDesc('created_at');
            })
            ->columns([
                TextColumn::make('transfer_number')
                    ->label('Nomor Transfer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('requester.name')
                    ->label('Pemohon')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('requestingDivision.name')
                    ->label('Divisi Pemohon')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sourceDivision.name')
                    ->label('Divisi Sumber')
                    ->placeholder('Belum dipilih'),
                TextColumn::make('approval_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(function ($record) {
                        $approval = $record->approval;
                        if (! $approval) {
                            return 'Pending';
                        }

                        // Get the latest approval step approval
                        $latestApproval = $approval
                            ->approvalStepApprovals()
                            ->with(['user.divisions', 'step.division'])
                            ->latest('approved_at')
                            ->first();

                        if ($latestApproval) {
                            $status = ucfirst($latestApproval->status);
                            $user = $latestApproval->user;
                            $step = $latestApproval->step;
                            $division = $step?->division ?? $user?->divisions->first();

                            if ($user && $division) {
                                // Get division's initial and user's first role name
                                $divisionInitial = $division->initial ?? 'N/A';
                                $roleNames = $user->getRoleNames();
                                $role = $roleNames->first() ?? 'N/A';

                                return "{$status} by {$divisionInitial} {$role}";
                            } else {
                                return $status;
                            }
                        }

                        return $approval->status
                            ? ucfirst($approval->status)
                            : 'Pending';
                    })
                    ->color(
                        fn (string $state): string => match (true) {
                            str_contains($state, 'approved') => 'success',
                            str_contains($state, 'rejected') => 'danger',
                            default => 'warning',
                        },
                    )
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Tanggal Dibuat')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Tanggal Diupdate')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status Approval')
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
                SelectFilter::make('requesting_division_id')
                    ->label('Divisi Pemohon')
                    ->relationship('requestingDivision', 'name'),
                Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('created_from'),
                        \Filament\Forms\Components\DatePicker::make('created_until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make()
                    ->successNotificationTitle('Transfer stok ATK berhasil diperbarui')
                    ->modalWidth(Width::SevenExtraLarge)
                    ->visible(function ($record) {
                        $user = \Illuminate\Support\Facades\Auth::user();
                        if (! $user) {
                            return false;
                        }
                        $isRequester = $user->id == $record->requester_id;
                        $isGA = $user->isGA();
                        $hasRole = $user->hasRole('Admin') || $user->hasRole('Super Admin');

                        // Only allow editing if the request hasn't been approved yet and the user is authorized
                        $canEdit = false;
                        $approval = $record->approval;
                        if ($approval && $approval->status === 'pending') {
                            // Can edit if user is the requester, GA division user, or has admin role
                            $canEdit = $isRequester || $isGA || $hasRole;
                        }

                        return $canEdit;
                    }),
                ApprovalAction::makeApprove()->successNotification(
                    Notification::make()
                        ->title('Permintaan transfer stok berhasil disetujui')
                        ->success(),
                ),
                ApprovalAction::makeReject()->successNotification(
                    Notification::make()
                        ->title('Permintaan transfer stok berhasil ditolak')
                        ->success(),
                ),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->successNotificationTitle('Transfer stok ATK berhasil dihapus'),
                ]),
            ]);
    }
}
