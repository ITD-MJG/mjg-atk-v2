<?php

namespace App\Filament\Resources\AtkTransferStocks\Pages;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Resources\AtkTransferStocks\AtkTransferStockResource;
use App\Models\Approval;
use App\Models\ApprovalFlowStep;
use App\Models\AtkTransferStock;
use App\Services\ApprovalService;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ApprovalAtkTransferStock extends ListRecords
{
    protected static string $resource = AtkTransferStockResource::class;

    protected static ?string $slug = 'atk/transfer-stocks/approval';

    protected static ?string $navigationLabel = 'Transfer Stok ATK';

    public static function getNavigationGroup(): ?string
    {
        return __('filament.navigation.group.request_approval');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArrowsRightLeft;

    protected static ?string $title = 'Transfer Stok ATK';

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
            ->where('approvable_type', (new AtkTransferStock)->getMorphClass()) // Ensure it's for ATK Transfer Stocks
            ->count();

        return (string) $count;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $this->getQuery())
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
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                ApprovalAction::makeApprove(),
                ApprovalAction::makeReject(),
            ], position: RecordActionsPosition::BeforeColumns)
            ->bulkActions([
                BulkActionGroup::make([
                    //
                ]),
            ]);
    }

    protected function getQuery(): Builder
    {
        // Get the current user
        $user = Auth::user();
        if (! $user) {
            return AtkTransferStock::query()->whereRaw('0=1'); // Return empty query if no user
        }

        // Get all pending or partially approved AtkTransferStock records
        $query = AtkTransferStock::query()
            ->whereHas('approval', function ($query) {
                $query->where('status', 'pending')
                    ->orWhere('status', 'partially_approved');
            })
            ->with([
                'requester',
                'requestingDivision',
                'sourceDivision',
                'approval',
            ]);

        // Filter to only show records that the current user can approve
        $approvableIds = [];

        foreach (AtkTransferStock::whereHas('approval', function ($q) {
            $q->where('status', 'pending')
                ->orWhere('status', 'partially_approved');
        })->get() as $transferStock) {
            $approvalService = app(ApprovalService::class);
            if ($approvalService->canUserApproveTransferStock($transferStock, $user)) {
                $approvableIds[] = $transferStock->id;
            }
        }

        return $query->whereIn('id', $approvableIds);
    }
}
