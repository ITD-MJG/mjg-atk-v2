<?php

namespace App\Filament\Resources\AtkStockRequests\Pages;

use App\Enums\AtkStockRequestStatus;
use App\Filament\Actions\ApprovalAction;
use App\Filament\Resources\AtkStockRequests\AtkStockRequestResource;
use App\Models\Approval;
use App\Models\ApprovalFlowStep;
use App\Models\AtkStockRequest;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
class ApprovalAtkStockRequest extends ListRecords
{
    protected static string $resource = AtkStockRequestResource::class;

    protected static ?string $slug = 'atk/stock-requests/approval';

    protected static ?string $navigationLabel = 'Permintaan ATK';

    public static function getNavigationGroup(): ?string
    {
        return __('filament.navigation.group.atk');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::DocumentCheck;

    protected static ?string $title = 'Permintaan ATK';

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        // Find approval flow steps that match the user's role and division
        $matchingStepIds = ApprovalFlowStep::whereHas('role', function ($query) use ($user) {
            $query->whereIn('id', $user->roles->pluck('id'));
        })
            ->where(function ($query) use ($user) {
                // Step is available for the user's own division OR for specific division
                $query
                    ->whereNull('division_id')
                    ->orWhereIn('division_id', $user->divisions->pluck('id'));
            })
            ->pluck('id');

        // Get count of approval records that are pending/partially_approved and have steps matching the user's permissions
        $count = Approval::whereIn('current_step', $matchingStepIds)
            ->where(function ($query) {
                $query->where('status', 'pending')
                    ->orWhere('status', 'partially_approved');
            })
            ->where('approvable_type', (new AtkStockRequest)->getMorphClass()) // Ensure it's for ATK Stock Requests
            ->whereHasMorph('approvable', [AtkStockRequest::class], function ($query) {
                $query->where('status', AtkStockRequestStatus::Published);
            })
            ->count();

        return (string) $count;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $this->getQuery())
            ->columns([
                TextColumn::make('request_number')
                    ->label('Nomor Permintaan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('requester.name')
                    ->label('Pemohon')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('division.name')
                    ->label('Divisi')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('approval.status')
                    ->label('Status Approval')
                    ->badge()
                    ->color(
                        fn (string $state): string => match ($state) {
                            'approved' => 'success',
                            'rejected' => 'danger',
                            'pending' => 'warning',
                            default => 'gray',
                        },
                    ),
                TextColumn::make('created_at')
                    ->label('Tanggal Dibuat')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
            ])
            ->actions([
                ApprovalAction::makeApprove()->successNotification(
                    Notification::make()
                        ->title('Permintaan ATK berhasil disetujui!')
                        ->success(),
                ),
                ApprovalAction::makeReject()->successNotification(
                    Notification::make()
                        ->title('Permintaan ATK berhasil ditolak!')
                        ->success(),
                ),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('approve')
                        ->label('Setujui Terpilih')
                        ->icon(Heroicon::CheckCircle)
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Setujui Permintaan Terpilih')
                        ->modalDescription('Permintaan terpilih yang dapat Anda setujui akan diproses. Lanjutkan?')
                        ->action(function (Collection $records) {
                            $user = auth()->user();
                            $approved = 0;

                            foreach ($records as $record) {
                                if (! $this->canApprove($record, $user)) {
                                    continue;
                                }

                                $this->processApproval($record, $user, 'approve', 'Permintaan disetujui');
                                $approved++;
                            }

                            Notification::make()
                                ->title("{$approved} permintaan ATK berhasil disetujui!")
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('reject')
                        ->label('Tolak Terpilih')
                        ->icon(Heroicon::XCircle)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Tolak Permintaan Terpilih')
                        ->modalDescription('Permintaan terpilih yang dapat Anda proses akan ditolak. Lanjutkan?')
                        ->form([
                            Textarea::make('rejection_notes')
                                ->label('Alasan Penolakan')
                                ->placeholder('Berikan alasan penolakan permintaan ini...'),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $user = auth()->user();
                            $rejected = 0;

                            foreach ($records as $record) {
                                if (! $this->canApprove($record, $user)) {
                                    continue;
                                }

                                $this->processApproval($record, $user, 'reject', $data['rejection_notes'] ?? null);
                                $rejected++;
                            }

                            Notification::make()
                                ->title("{$rejected} permintaan ATK berhasil ditolak!")
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    protected function getQuery(): Builder
    {
        // Get the current user
        $user = Auth::user();
        if (! $user) {
            return AtkStockRequest::query()->whereRaw('0=1'); // Return empty query if no user
        }

        // Get all pending or partially approved AtkStockRequest records that are published
        $query = AtkStockRequest::query()
            ->where('status', AtkStockRequestStatus::Published)
            ->whereHas('approval', function ($query) {
                $query->where('status', 'pending')
                    ->orWhere('status', 'partially_approved');
            })
            ->with([
                'requester',
                'division',
                'approval',
            ]);

        // Filter to only show records that the current user can approve
        $approvalService = app(\App\Services\ApprovalService::class);
        $approvableIds = [];

        foreach (AtkStockRequest::where('status', AtkStockRequestStatus::Published)
            ->whereHas('approval', function ($q) {
                $q->where('status', 'pending')
                    ->orWhere('status', 'partially_approved');
            })->get() as $stockRequest) {
            if ($approvalService->canUserApproveStockRequest($stockRequest, $user)) {
                $approvableIds[] = $stockRequest->id;
            }
        }

        return $query->whereIn('id', $approvableIds);
    }

    protected function canApprove(AtkStockRequest $record, $user): bool
    {
        return app(\App\Services\ApprovalService::class)->canUserApproveStockRequest($record, $user);
    }

    protected function processApproval(AtkStockRequest $record, $user, string $action, ?string $notes): void
    {
        $validationService = app(\App\Services\ApprovalValidationService::class);
        $historyService = app(\App\Services\ApprovalHistoryService::class);
        $stockUpdateService = app(\App\Services\StockUpdateService::class);
        $processingService = new \App\Services\ApprovalProcessingService($validationService, $historyService, $stockUpdateService);
        $approvalService = new \App\Services\ApprovalService($validationService, $processingService, $historyService, $stockUpdateService);

        $approval = $record->approval;

        if ($approval) {
            $approvalService->processApprovalStep($approval, $user, $action, $notes);
            $approvalService->syncApprovalStatus($record);
        }
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
