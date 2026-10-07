<?php

namespace App\Filament\Resources\AtkStockRequests\Pages;

use App\Enums\AtkStockRequestStatus;
use App\Filament\Resources\AtkStockRequests\AtkStockRequestResource;
use App\Models\AtkStockRequest;
use App\Models\UserDivision;
use App\Services\ApprovalProcessingService;
use App\Services\StockRequestService;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListAtkStockRequests extends ListRecords
{
    protected static string $resource = AtkStockRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make('create_draft')
                ->label('Buat Draft')
                ->modalHeading('Buat Draft Permintaan Stok ATK')
                ->createAnother(false)
                ->using(function (array $data) {
                    return $this->createRequests($data, AtkStockRequestStatus::Draft);
                })
                ->extraModalFooterActions(fn (CreateAction $action): array => [
                    $action->makeModalSubmitAction('publish')
                        ->label('Publish')
                        ->color('success')
                        ->requiresConfirmation()
                        ->using(function (array $data) {
                            $requests = $this->createRequests($data, AtkStockRequestStatus::Published);

                            foreach ($requests as $request) {
                                app(ApprovalProcessingService::class)->createApproval($request, AtkStockRequest::class);
                            }

                            return $requests[0] ?? null;
                        }),
                ])
                ->visible(fn () => auth()->user()->can('create atk-stock-request'))
                ->modalWidth(Width::SevenExtraLarge),
        ];
    }

    /**
     * Fan out one request per selected division.
     *
     * @return array<int, AtkStockRequest>
     */
    protected function createRequests(array $data, AtkStockRequestStatus $status): array
    {
        $requests = app(StockRequestService::class)->createStockRequestsForDivisions(auth()->user(), [
            'division_ids' => $data['division_ids'],
            'status' => $status,
            'notes' => $data['notes'] ?? null,
            'items' => array_values($data['atkStockRequestItems'] ?? []),
        ]);

        $divisions = UserDivision::whereIn('id', collect($requests)->pluck('division_id'))
            ->pluck('name')
            ->implode(', ');

        Notification::make()
            ->title($status === AtkStockRequestStatus::Published ? 'Permintaan dipublikasikan' : 'Draft dibuat')
            ->body($requests === []
                ? 'Tidak ada permintaan yang dibuat.'
                : count($requests).' permintaan untuk: '.$divisions.'.')
            ->success()
            ->send();

        return $requests;
    }
}
