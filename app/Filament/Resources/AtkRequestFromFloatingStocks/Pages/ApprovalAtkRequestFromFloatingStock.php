<?php

namespace App\Filament\Resources\AtkRequestFromFloatingStocks\Pages;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Resources\AtkRequestFromFloatingStocks\AtkRequestFromFloatingStockResource;
use App\Models\Approval;
use App\Models\ApprovalFlowStep;
use App\Models\AtkRequestFromFloatingStock;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ApprovalAtkRequestFromFloatingStock extends ListRecords
{
    protected static string $resource = AtkRequestFromFloatingStockResource::class;

    protected static ?string $title = 'Permintaan Stok Umum';

    protected static ?string $navigationLabel = 'Permintaan Stok Umum';

    public static function getNavigationGroup(): ?string
    {
        return __('filament.navigation.group.atk');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::DocumentCheck;

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
            ->where('approvable_type', (new AtkRequestFromFloatingStock)->getMorphClass())
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
                    ->label('Tanggal Dibuat')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
            ])
            ->actions([
                ApprovalAction::makeApprove()->successNotification(
                    Notification::make()
                        ->title('Permintaan stok umum berhasil disetujui!')
                        ->success(),
                ),
                ApprovalAction::makeReject()->successNotification(
                    Notification::make()
                        ->title('Permintaan stok umum berhasil ditolak!')
                        ->success(),
                ),
            ], position: RecordActionsPosition::BeforeColumns);
    }

    protected function getQuery(): Builder
    {
        $user = Auth::user();
        if (! $user) {
            return AtkRequestFromFloatingStock::query()->whereRaw('0=1');
        }

        // Get all pending or partially approved AtkRequestFromFloatingStock records
        $query = AtkRequestFromFloatingStock::query()
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

        // We need to check EACH request because the division-based logic for 'Division Head'
        // depends on the requester's division when the step's division_id is null.
        foreach (AtkRequestFromFloatingStock::whereHas('approval', function ($q) {
            $q->where('status', 'pending')
                ->orWhere('status', 'partially_approved');
        })->get() as $request) {
            if ($approvalService->canUserApprove($request, $user)) {
                $approvableIds[] = $request->id;
            }
        }

        return $query->whereIn('id', $approvableIds);
    }
}
