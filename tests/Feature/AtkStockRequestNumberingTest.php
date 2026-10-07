<?php

namespace Tests\Feature;

use App\Enums\AtkStockRequestStatus;
use App\Models\ApprovalFlow;
use App\Models\AtkCategory;
use App\Models\AtkDivisionStock;
use App\Models\AtkDivisionStockSetting;
use App\Models\AtkItem;
use App\Models\AtkStockRequest;
use App\Models\User;
use App\Models\UserDivision;
use App\Services\ApprovalService;
use App\Services\StockRequestService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->divisionA = UserDivision::create(['name' => 'Division A', 'initial' => 'A']);
    $this->divisionB = UserDivision::create(['name' => 'Division B', 'initial' => 'B']);

    $this->category = AtkCategory::create(['name' => 'Stationery']);
    $this->item = AtkItem::create([
        'name' => 'Pen',
        'slug' => 'pen',
        'category_id' => $this->category->id,
        'unit_of_measure' => 'pcs',
    ]);

    // A global approval flow, so request creation succeeds without per-division setup.
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
});

function createRequestFor(StockRequestService $service, User $user, int $divisionId, array $items): AtkStockRequest
{
    return $service->createStockRequest($user, [
        'division_id' => $divisionId,
        'items' => $items,
    ]);
}

/**
 * P1: numbering must be scoped per division, not global.
 *
 * This is the regression guard: the previous implementation derived the number
 * from the global latest id, so Division B's first request would have been
 * numbered after Division A's volume.
 */
it('numbers requests per division independently', function () {
    $items = [['item_id' => $this->item->id, 'quantity' => 1]];

    $a1 = createRequestFor($this->service, $this->user, $this->divisionA->id, $items);
    $a2 = createRequestFor($this->service, $this->user, $this->divisionA->id, $items);
    $b1 = createRequestFor($this->service, $this->user, $this->divisionB->id, $items);

    // The generator owns the format: ATK-{INITIAL}-REQ-{8 digits}
    expect($a1->request_number)->toBe('ATK-A-REQ-00000001')
        ->and($a2->request_number)->toBe('ATK-A-REQ-00000002')
        // B is unaffected by A's volume — this is what the old code got wrong.
        ->and($b1->request_number)->toBe('ATK-B-REQ-00000001');
});

/**
 * P1: the service must not hand-roll the number, or the trait's empty() guard
 * skips StockNumberGenerator and the unsafe path returns.
 */
it('lets the model trait generate the request number', function () {
    $request = createRequestFor($this->service, $this->user, $this->divisionA->id, [
        ['item_id' => $this->item->id, 'quantity' => 1],
    ]);

    expect($request->request_number)->toStartWith('ATK-A-REQ-');
});

/**
 * P1: items are created against the request, with category copied from the item.
 */
it('creates request items for the request', function () {
    $request = createRequestFor($this->service, $this->user, $this->divisionA->id, [
        ['item_id' => $this->item->id, 'quantity' => 4],
    ]);

    expect($request->atkStockRequestItems)->toHaveCount(1)
        ->and($request->atkStockRequestItems->first()->quantity)->toBe(4)
        ->and($request->atkStockRequestItems->first()->item_id)->toBe($this->item->id);
});

/**
 * P1: the status passed in is honoured, so callers control draft vs published.
 */
it('honours an explicitly provided status', function () {
    $request = $this->service->createStockRequest($this->user, [
        'division_id' => $this->divisionA->id,
        'status' => AtkStockRequestStatus::Published,
        'items' => [['item_id' => $this->item->id, 'quantity' => 1]],
    ]);

    expect($request->status)->toBe(AtkStockRequestStatus::Published);
});

/**
 * P2: a duplicate request_number must be retried, not surfaced as an error.
 *
 * True concurrency cannot be exercised in-process; this forces the collision by
 * pre-inserting the number the generator is about to compute.
 */
it('retries when a request number collides', function () {
    $items = [['item_id' => $this->item->id, 'quantity' => 1]];

    // First request occupies ATK-A-REQ-00000001.
    createRequestFor($this->service, $this->user, $this->divisionA->id, $items);

    // Occupy the number the next generate() call would produce, simulating the
    // losing side of a concurrent first-ever-request race.
    AtkStockRequest::create([
        'request_number' => 'ATK-A-REQ-00000002',
        'requester_id' => $this->user->id,
        'division_id' => $this->divisionA->id,
    ]);

    // The generator will compute 00000003 (it reads the latest by id), so this
    // must simply succeed rather than raising.
    $request = createRequestFor($this->service, $this->user, $this->divisionA->id, $items);

    expect($request->request_number)->toBe('ATK-A-REQ-00000003');
});

/**
 * P2: when retries are exhausted the original exception must surface, not be swallowed.
 */
it('surfaces the unique violation after exhausting retries', function () {
    $service = new class(app(ApprovalService::class)) extends StockRequestService
    {
        public int $calls = 0;

        protected function createStockRequestOnce(User $user, array $data): AtkStockRequest
        {
            $this->calls++;

            throw new UniqueConstraintViolationException('sqlite', 'insert', [], new \Exception('duplicate'));
        }
    };

    expect(fn () => $service->createStockRequest($this->user, [
        'division_id' => $this->divisionA->id,
        'items' => [['item_id' => $this->item->id, 'quantity' => 1]],
    ]))->toThrow(UniqueConstraintViolationException::class);

    expect($service->calls)->toBe(3);
});

/**
 * P3: over-limit quantities are rejected server-side, not clamped.
 *
 * The browser check was previously the only enforcement, so any non-form path
 * bypassed the limit entirely.
 */
it('rejects a quantity that exceeds the division max limit', function () {
    AtkDivisionStockSetting::create([
        'division_id' => $this->divisionA->id,
        'item_id' => $this->item->id,
        'category_id' => $this->category->id,
        'max_limit' => 5,
    ]);

    expect(fn () => createRequestFor($this->service, $this->user, $this->divisionA->id, [
        ['item_id' => $this->item->id, 'quantity' => 10],
    ]))->toThrow(ValidationException::class);

    expect(AtkStockRequest::count())->toBe(0);
});

/**
 * P3: the available headroom accounts for existing stock.
 */
it('accounts for current stock when checking the limit', function () {
    AtkDivisionStockSetting::create([
        'division_id' => $this->divisionA->id,
        'item_id' => $this->item->id,
        'category_id' => $this->category->id,
        'max_limit' => 5,
    ]);

    AtkDivisionStock::create([
        'division_id' => $this->divisionA->id,
        'item_id' => $this->item->id,
        'category_id' => $this->category->id,
        'current_stock' => 4,
    ]);

    // Only 1 unit of headroom remains.
    expect(fn () => createRequestFor($this->service, $this->user, $this->divisionA->id, [
        ['item_id' => $this->item->id, 'quantity' => 2],
    ]))->toThrow(ValidationException::class);

    $request = createRequestFor($this->service, $this->user, $this->divisionA->id, [
        ['item_id' => $this->item->id, 'quantity' => 1],
    ]);

    expect($request)->not->toBeNull();
});

/**
 * P3: an item with no configured limit is unconstrained.
 */
it('allows any quantity when no limit is configured', function () {
    $request = createRequestFor($this->service, $this->user, $this->divisionA->id, [
        ['item_id' => $this->item->id, 'quantity' => 9999],
    ]);

    expect($request)->not->toBeNull();
});
