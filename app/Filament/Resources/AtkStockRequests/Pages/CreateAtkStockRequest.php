<?php

namespace App\Filament\Resources\AtkStockRequests\Pages;

use App\Filament\Resources\AtkStockRequests\AtkStockRequestResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAtkStockRequest extends CreateRecord
{
    protected static string $resource = AtkStockRequestResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['requester_id'] = auth()->id();

        return $data;
    }
}
