<?php

namespace Tests\Feature;

use App\Filament\Resources\AtkStockRequests\RelationManagers\AtkStockTransactionsRelationManager;
use App\Models\AtkCategory;
use App\Models\AtkItem;
use App\Models\AtkStockRequest;
use App\Models\AtkStockRequestItem;
use App\Models\AtkStockTransaction;
use App\Models\AtkStockUsage;
use App\Models\User;
use App\Models\UserDivision;
use App\Services\FulfillmentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RoleSeeder::class);
    $this->seed(\Database\Seeders\UserDivisionSeeder::class);
    $this->seed(\Database\Seeders\ApprovalFlowSeeder::class);

    Filament::setCurrentPanel(Filament::getPanel('dashboard'));

    $this->division = UserDivision::where('initial', 'ITD')->first();
    $this->user = User::factory()->create();
    $this->user->assignRole('Admin');
    $this->user->divisions()->attach($this->division);

    $this->category = AtkCategory::create(['name' => 'Stationery', 'slug' => 'atk']);
    $this->item = AtkItem::create([
        'name' => 'Test Item',
        'slug' => 'test-item',
        'category_id' => $this->category->id,
        'unit_of_measure' => 'pcs',
    ]);

    $this->request = AtkStockRequest::create([
        'request_number' => 'REQ-TRX-001',
        'requester_id' => $this->user->id,
        'division_id' => $this->division->id,
        'status' => \App\Enums\AtkStockRequestStatus::Published,
    ]);

    $this->requestItem = $this->request->atkStockRequestItems()->create([
        'item_id' => $this->item->id,
        'category_id' => $this->category->id,
        'quantity' => 10,
    ]);

    $this->request->approval->update(['status' => 'approved']);

    $this->actingAs($this->user);
});

it('exposes transactions recorded against the request items', function () {
    app(FulfillmentService::class)->receiveItem($this->requestItem, 4, 'Catatan uji');

    $transactions = $this->request->atkStockTransactions()->get();

    expect($transactions)->toHaveCount(1);
    expect($transactions->first()->quantity)->toBe(4);
    expect($transactions->first()->type)->toBe('request');
    expect($transactions->first()->trx_src_type)->toBe(AtkStockRequestItem::class);
    expect($transactions->first()->trx_src_id)->toBe($this->requestItem->id);
});

it('does not leak transactions from other requests or sources', function () {
    // Another request in the same division, fulfilled too.
    $otherRequest = AtkStockRequest::create([
        'request_number' => 'REQ-TRX-002',
        'requester_id' => $this->user->id,
        'division_id' => $this->division->id,
        'status' => \App\Enums\AtkStockRequestStatus::Published,
    ]);
    $otherRequestItem = $otherRequest->atkStockRequestItems()->create([
        'item_id' => $this->item->id,
        'category_id' => $this->category->id,
        'quantity' => 10,
    ]);
    $otherRequest->approval->update(['status' => 'approved']);

    app(FulfillmentService::class)->receiveItem($this->requestItem, 4);
    app(FulfillmentService::class)->receiveItem($otherRequestItem, 7);

    // A usage transaction, sourced from a completely different model.
    $usage = AtkStockUsage::create([
        'requester_id' => $this->user->id,
        'division_id' => $this->division->id,
    ]);
    AtkStockTransaction::create([
        'division_id' => $this->division->id,
        'item_id' => $this->item->id,
        'type' => 'usage',
        'quantity' => 2,
        'unit_cost' => 0,
        'total_cost' => 0,
        'mac_snapshot' => 0,
        'balance_snapshot' => 0,
        'trx_src_type' => AtkStockUsage::class,
        'trx_src_id' => $usage->id,
    ]);

    $transactions = $this->request->atkStockTransactions()->get();

    expect($transactions)->toHaveCount(1);
    expect($transactions->pluck('quantity')->all())->toBe([4]);
    expect($transactions->pluck('trx_src_id')->all())->toBe([$this->requestItem->id]);
});

it('renders the transactions relation manager on the view page', function () {
    app(FulfillmentService::class)->receiveItem($this->requestItem, 4, 'Catatan uji');

    $transaction = $this->request->atkStockTransactions()->first();

    Livewire::test(AtkStockTransactionsRelationManager::class, [
        'ownerRecord' => $this->request,
        'pageClass' => \App\Filament\Resources\AtkStockRequests\Pages\ViewAtkStockRequest::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$transaction])
        ->assertCanSeeTableRecords([$transaction], inOrder: true)
        ->assertSee($this->item->name);
});

it('renders the relation manager without transactions', function () {
    Livewire::test(AtkStockTransactionsRelationManager::class, [
        'ownerRecord' => $this->request,
        'pageClass' => \App\Filament\Resources\AtkStockRequests\Pages\ViewAtkStockRequest::class,
    ])
        ->assertSuccessful()
        ->assertCanNotSeeTableRecords(AtkStockTransaction::all());
});
