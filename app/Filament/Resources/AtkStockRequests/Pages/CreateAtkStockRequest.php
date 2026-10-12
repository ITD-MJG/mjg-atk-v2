<?php

namespace App\Filament\Resources\AtkStockRequests\Pages;

use App\Enums\AtkStockRequestStatus;
use App\Filament\Resources\AtkStockRequests\AtkStockRequestResource;
use App\Models\AtkStockRequest;
use App\Services\ApprovalProcessingService;
use App\Services\StockRequestService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateAtkStockRequest extends CreateRecord
{
    protected static string $resource = AtkStockRequestResource::class;

    public function create(bool $another = false): void
    {
        $data = $this->form->getState();

        $requests = app(StockRequestService::class)->createStockRequestsForDivisions(auth()->user(), [
            'division_ids' => $data['division_ids'],
            'status' => AtkStockRequestStatus::Draft,
            'notes' => $data['notes'] ?? null,
            'items' => array_values($data['atkStockRequestItems'] ?? []),
        ]);

        foreach ($requests as $request) {
            app(ApprovalProcessingService::class)->createApproval($request, AtkStockRequest::class);
        }

        $divisions = \App\Models\UserDivision::whereIn('id', collect($requests)->pluck('division_id'))
            ->pluck('name')
            ->implode(', ');

        Notification::make()
            ->title('Draft dibuat')
            ->body(count($requests).' permintaan untuk: '.$divisions.'.')
            ->success()
            ->send();

        $this->redirect(AtkStockRequestResource::getUrl('index'));
    }
}
