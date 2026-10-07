<?php

namespace Tests\Feature;

use App\Enums\AtkStockRequestStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\AtkCategory;
use App\Models\AtkDivisionStock;
use App\Models\AtkDivisionStockSetting;
use App\Models\AtkItem;
use App\Models\AtkStockRequest;
use App\Models\AtkStockRequestItem;
use App\Models\User;
use App\Models\UserDivision;
use App\Services\ApprovalService;
use App\Services\StockRequestService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->divisionA = UserDivision::create(['name' => 'Division A', 'initial' => 'A']);
    $this->divisionB = UserDivision::create(['name' => 'Division B', 'initial' => 'B']);
    $this->divisionC = UserDivision::create(['name' => 'Division C', 'initial' => 'C']);

    $this->category = AtkCategory::create(['name' => 'Stationery']);
    $this->pen = AtkItem::create([
        'name' => 'Pen', 'slug' => 'pen',
        'category_id' => $this->category->id, 'unit_of_measure' => 'pcs',
    ]);
    $this->book = AtkItem::create([
        'name' => 'Book', 'slug' => 'book',
        'category_id' => $this->category->id, 'unit_of_measure' => 'pcs',
    ]);

    ApprovalFlow::create([
        'name' => 'Global ATK Request Flow',
        'model_type' => AtkStockRequest::class,
        'is_active' => true,
        'division_ids' => [],
    ]);

    $this->user = User::create([
        'name' => 'Requester',
        'email' => 'requester@example.com',
        'password' => bcrypt('password'),
        'initial' => 'RQ',
    ]);
    $this->user->divisions()->attach([$this->divisionA->id, $this->divisionB->id]);

    $this->actingAs($this->user);

    $this->service = app(StockRequestService::class);

    $this->items = [
        ['item_id' => $this->pen->id, 'quantity' => 2],
        ['item_id' => $this->book->id, 'quantity' => 3],
    ];
});

/**
 * Core fan-out: one request per division, with items copied not shared.
 */
it('creates one request per selected division with its own items', function () {
    $requests = $this->service->createStockRequestsForDivisions($this->user, [
        'division_ids' => [$this->divisionA->id, $this->divisionB->id],
        'items' => $this->items,
    ]);

    expect($requests)->toHaveCount(2)
        ->and(AtkStockRequest::count())->toBe(2)
        // Items are copied, so 2 requests x 2 items = 4 item rows.
        ->and(AtkStockRequestItem::count())->toBe(4);

    $byDivision = collect($requests)->keyBy('division_id');

    expect($byDivision)->toHaveKeys([$this->divisionA->id, $this->divisionB->id]);

    foreach ($byDivision as $request) {
        expect($request->atkStockRequestItems)->toHaveCount(2)
            ->and($request->atkStockRequestItems->pluck('item_id')->sort()->values()->all())
            ->toBe(collect([$this->pen->id, $this->book->id])->sort()->values()->all());
    }
});

/**
 * Each request is independently single-division, which is what keeps
 * DivisionScoped, approval routing, and fulfillment working unchanged.
 */
it('numbers each request with its own division initial', function () {
    $requests = $this->service->createStockRequestsForDivisions($this->user, [
        'division_ids' => [$this->divisionA->id, $this->divisionB->id],
        'items' => $this->items,
    ]);

    $numbers = collect($requests)->pluck('request_number')->sort()->values()->all();

    expect($numbers)->toBe(['ATK-A-REQ-00000001', 'ATK-B-REQ-00000001']);
});

/**
 * Duplicate selections must not create duplicate requests.
 */
it('treats a duplicated division selection as one request', function () {
    $requests = $this->service->createStockRequestsForDivisions($this->user, [
        'division_ids' => [$this->divisionA->id, $this->divisionA->id, $this->divisionB->id],
        'items' => $this->items,
    ]);

    expect($requests)->toHaveCount(2)
        ->and(AtkStockRequest::count())->toBe(2);
});

/**
 * All-or-nothing: a failure partway through must leave nothing behind.
 */
it('rolls back every request when one division fails', function () {
    $approval = \Mockery::mock(ApprovalService::class);
    $approval->shouldReceive('createApproval')
        ->andReturnUsing(function () {
            static $calls = 0;
            $calls++;

            // Fail on the second division's approval.
            if ($calls === 2) {
                throw new \RuntimeException('approval failed');
            }

            return new Approval;
        });

    $service = new StockRequestService($approval);

    expect(fn () => $service->createStockRequestsForDivisions($this->user, [
        'division_ids' => [$this->divisionA->id, $this->divisionB->id],
        'items' => $this->items,
    ]))->toThrow(\RuntimeException::class);

    expect(AtkStockRequest::count())->toBe(0)
        ->and(AtkStockRequestItem::count())->toBe(0);
});

/**
 * The form's option list is presentation, not authorization. Writes need their
 * own check because DivisionScoped only guards reads.
 */
it('refuses to create a request for a division the user is not assigned to', function () {
    expect(fn () => $this->service->createStockRequestsForDivisions($this->user, [
        // division C is not assigned to this user.
        'division_ids' => [$this->divisionA->id, $this->divisionC->id],
        'items' => $this->items,
    ]))->toThrow(ValidationException::class);

    expect(AtkStockRequest::count())->toBe(0);
});

/**
 * A super admin may create for any division.
 */
it('allows a super admin to create for any division', function () {
    $this->seed(RoleSeeder::class);

    $admin = User::create([
        'name' => 'Super Admin',
        'email' => 'sa@example.com',
        'password' => bcrypt('password'),
        'initial' => 'SA',
    ]);
    $admin->assignRole('Super Admin');

    $this->actingAs($admin);

    $requests = $this->service->createStockRequestsForDivisions($admin, [
        'division_ids' => [$this->divisionC->id],
        'items' => $this->items,
    ]);

    expect($requests)->toHaveCount(1)
        ->and($requests[0]->division_id)->toBe($this->divisionC->id);
});

/**
 * Limits are per division, so one over-limit division rejects the whole submission
 * before anything is written.
 */
it('rejects the whole submission when one division exceeds its limit', function () {
    AtkDivisionStockSetting::create([
        'division_id' => $this->divisionA->id,
        'item_id' => $this->pen->id,
        'category_id' => $this->category->id,
        'max_limit' => 1, // requested quantity is 2
    ]);

    expect(fn () => $this->service->createStockRequestsForDivisions($this->user, [
        'division_ids' => [$this->divisionA->id, $this->divisionB->id],
        'items' => $this->items,
    ]))->toThrow(ValidationException::class);

    // Validation runs before the transaction, so neither division is written.
    expect(AtkStockRequest::count())->toBe(0)
        ->and(AtkStockRequestItem::count())->toBe(0);
});

/**
 * The limit belongs to one division only; an unlimited sibling must not be
 * dragged down by it, and vice versa.
 */
it('applies limits per division', function () {
    AtkDivisionStockSetting::create([
        'division_id' => $this->divisionA->id,
        'item_id' => $this->pen->id,
        'category_id' => $this->category->id,
        'max_limit' => 10,
    ]);

    AtkDivisionStock::create([
        'division_id' => $this->divisionA->id,
        'item_id' => $this->pen->id,
        'category_id' => $this->category->id,
        'current_stock' => 9,
    ]);

    // A has 1 headroom for pen and is requested 2 -> rejected.
    expect(fn () => $this->service->createStockRequestsForDivisions($this->user, [
        'division_ids' => [$this->divisionA->id],
        'items' => $this->items,
    ]))->toThrow(ValidationException::class);

    // B has no setting at all -> unconstrained.
    $requests = $this->service->createStockRequestsForDivisions($this->user, [
        'division_ids' => [$this->divisionB->id],
        'items' => $this->items,
    ]);

    expect($requests)->toHaveCount(1);
});

/**
 * The status flows through to every created request.
 */
it('applies the status to every created request', function () {
    $requests = $this->service->createStockRequestsForDivisions($this->user, [
        'division_ids' => [$this->divisionA->id, $this->divisionB->id],
        'status' => AtkStockRequestStatus::Published,
        'items' => $this->items,
    ]);

    foreach ($requests as $request) {
        expect($request->status)->toBe(AtkStockRequestStatus::Published);
    }
});
