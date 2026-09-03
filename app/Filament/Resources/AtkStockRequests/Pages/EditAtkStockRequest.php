<?php

namespace App\Filament\Resources\AtkStockRequests\Pages;

use App\Enums\AtkStockRequestStatus;
use App\Filament\Resources\AtkStockRequests\AtkStockRequestResource;
use App\Services\ApprovalProcessingService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditAtkStockRequest extends EditRecord
{
    protected static string $resource = AtkStockRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            Action::make('publish')
                ->label('Publish')
                ->icon(Heroicon::ArrowUpTray)
                ->color('success')
                ->visible(function () {
                    $record = $this->record;
                    if ($record->status !== AtkStockRequestStatus::Draft) {
                        return false;
                    }

                    $user = auth()->user();

                    // Only Super Admin or the requester may publish a draft
                    return $user->isSuperAdmin() || $user->id === $record->requester_id;
                })
                ->requiresConfirmation()
                ->action(function () {
                    $this->record->update(['status' => AtkStockRequestStatus::Published]);
                    if (! $this->record->approval) {
                        app(ApprovalProcessingService::class)->createApproval($this->record, AtkStockRequest::class);
                    }

                    Notification::make()
                        ->title('Permintaan stok ATK berhasil dipublikasikan')
                        ->success()
                        ->send();

                    $this->redirect($this->getResource()::getUrl('index'));
                }),
            Action::make('unpublish')
                ->label('Unpublish')
                ->icon(Heroicon::ArrowDownTray)
                ->color('gray')
                ->visible(function () {
                    if (! ($this->record->status === AtkStockRequestStatus::Published
                        && $this->record->approval_status === 'pending')) {
                        return false;
                    }

                    $user = auth()->user();

                    // Only Super Admin or the requester may unpublish
                    return $user->isSuperAdmin() || $user->id === $this->record->requester_id;
                })
                ->requiresConfirmation()
                ->action(function () {
                    $this->record->update(['status' => AtkStockRequestStatus::Draft]);

                    Notification::make()
                        ->title('Permintaan stok ATK berhasil ditarik menjadi draft')
                        ->success()
                        ->send();

                    $this->redirect($this->getResource()::getUrl('index'));
                }),
            DeleteAction::make(),
        ];
    }
}
