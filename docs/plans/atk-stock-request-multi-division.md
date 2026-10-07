# Plan: Multi-Division ATK Stock Request (Design 3)

Bug fixes first, in priority order, each independently mergeable. Then the fan-out, with the
quantity-limit trap eliminated by construction rather than patched.

## Goal

A user assigned to multiple divisions submits **one form** and gets **N single-division
`AtkStockRequest` records** — one per selected division, each with its own copy of the items and its own
approval flow.

No schema change. Every created request stays single-division, so authorization, approval resolution,
fulfillment, and stock update all keep working unchanged.

## Why Design 3

Verified consumers of `AtkStockRequest.division_id`:

| Consumer | Location | Uses single division for |
|---|---|---|
| `DivisionScoped::scopeForUser` | `app/Traits/DivisionScoped.php:24` | row visibility (`whereIn`) |
| `ApprovalProcessingService::createApproval` | `app/Services/ApprovalProcessingService.php:212` | flow selection (`whereJsonContains('division_ids', …)`) |
| `ApprovalFlowStep` | `app/Models/ApprovalFlowStep.php:61` | per-step routing |
| `FulfillmentService` | `app/Services/FulfillmentService.php:60` | target division stock |
| `AtkDivisionStock::atkStockRequests` | `app/Models/AtkDivisionStock.php:90` | relation |
| `AtkStockRequestItem::divisionStock()` | `app/Models/AtkStockRequestItem.php:53` | subquery into `atk_stock_requests` |
| Table / widget / export filters | `AtkStockRequestsTable.php`, `AtkStockRequestStatus.php` | filtering |
| `StockUpdateService::updateStockByRequestType` | `app/Services/StockUpdateService.php:437` | `firstOrCreate(['division_id' => …, 'item_id' => …])` |

Rejected:

- **Design 1 (parent + children):** new table, parent model, parent approval aggregation, parent-aware
  widgets. The parent is a reporting concept, not a domain one. Aggregating partial approval states
  across children is genuinely hard.
- **Design 2 (move `division_id` to items):** breaks all eight consumers at once. A cross-division request
  has no single division to filter on, so `scopeForUser` **leaks rows** to unrelated divisions; approval
  flow selection has nothing to match. Fixing that means per-item authorization and per-item approval
  state — a rewrite of the approval engine, for no user-visible gain.

---

# Phase 1 — Bugs, priority order

Each bug is its own commit. P1 and P2 are the ones that can corrupt data; P3–P5 are clarity and
robustness. All are fixes to the **current single-division flow**, so they are valuable even if the
fan-out never ships.

## P1 — `request_number` generation is unsafe and shadows the safe implementation

**Severity: high (data corruption / failed inserts).**
`app/Services/StockRequestService.php:30-36`

```php
$selectedDivision = \App\Models\UserDivision::find($data['division_id']);
$divisionInitial = $selectedDivision ? $selectedDivision->initial : 'ATK';

$lastRequest = AtkStockRequest::orderBy('id', 'desc')->first();
$nextId = $lastRequest ? $lastRequest->id + 1 : 1;
$requestNumber = $divisionInitial.'-REQ-'.str_pad($nextId, 8, '0', STR_PAD_LEFT);
```

Four defects:

1. **Shadows the safe path.** `StockRequestModelTrait::booted()` (`app/Traits/StockRequestModelTrait.php:18`)
   generates a number only when `empty($model->request_number)`. Because the service fills it first, the
   trait never runs and `StockNumberGenerator` is dead code.
2. **Not scoped per division.** `orderBy('id','desc')` across *all* divisions, so `IT-REQ-00000042` is
   driven by unrelated divisions' volume.
3. **Not locked.** No `lockForUpdate`. Two concurrent creates read the same `$lastRequest->id`, compute
   the same number, and the loser violates `atk_stock_requests.request_number` (declared `->unique()` at
   `2025_10_06_043446:16`). The wrapping `DB::transaction` does **not** help — it serializes on lock
   acquisition, and there is no lock.
4. **Inconsistent format.** Service emits `{INITIAL}-REQ-…`; the generator emits `ATK-{INITIAL}-REQ-…`.
   Rows already exist in both formats.

The correct implementation already exists:
`StockNumberGenerator::generateOfficeStationeryRequestNumber()` — `DB::transaction` +
`orderByDesc('id')->lockForUpdate()`, scoped `where('division_id', $divisionId)`.

**Fix — delete the manual block; let the trait own it:**

```php
public function createStockRequest(User $user, array $data): AtkStockRequest
{
    return DB::transaction(function () use ($user, $data) {
        $stockRequest = new AtkStockRequest([
            'requester_id' => $user->id,
            'division_id' => $data['division_id'],
            'status' => $data['status'] ?? AtkStockRequestStatus::Draft,
            'notes' => $data['notes'] ?? null,
        ]);
        $stockRequest->save(); // request_number from StockRequestModelTrait

        foreach ($data['items'] as $itemData) {
            $stockRequest->atkStockRequestItems()->create([
                'item_id' => $itemData['item_id'],
                'category_id' => $itemData['category_id'] ?? null,
                'quantity' => $itemData['quantity'],
            ]);
        }

        $this->approvalService->createApproval($stockRequest, 'AtkStockRequest');

        return $stockRequest;
    });
}
```

The `$selectedDivision` / `$divisionInitial` lookups become unnecessary.

**Do not "fix" this by dropping the unique constraint.** That constraint is the last line of defence
keeping duplicate request numbers out of the approval flow.

## P2 — Empty-table race: `lockForUpdate` on zero rows locks nothing

**Severity: high (failed inserts under concurrency).**
`app/Helpers/StockNumberGenerator.php:26-31`

When a division has no prior request, `orderByDesc('id')->lockForUpdate()->first()` returns `null` and
locks no row. Two concurrent first-ever requests for that division both compute `…-00000001`; the loser
raises `UniqueConstraintViolationException`, which is unhandled and surfaces as a 500.

**Fix (preferred):** wrap creation in Laravel's retry, extending the mechanism already used for
deadlocks.

```php
return DB::transaction(fn () => $this->createOne($user, $data), attempts: 3);
```

Note `attempts` only retries on deadlock by default. For the unique violation, either catch
`UniqueConstraintViolationException` in a `retry(3, fn () => …)` wrapper, or add a per-division counter
row so an empty request table still has a lockable anchor.

**Verification caveat:** this is a concurrency bug and a single-threaded test cannot prove it fixed. Use
a forced-collision test (pre-insert the number the generator is about to compute) and say plainly that
true concurrency was not exercised.

## P3 — Max-limit enforcement exists only in the browser

**Severity: medium (already a live bug, independent of this feature).**
`app/Filament/Resources/AtkStockRequests/Schemas/AtkStockRequestForm.php` — five sites read
`$get('../../division_id')` to compare quantity against `AtkDivisionStockSetting.max_limit`.

The only enforcement is the client-side `afterStateUpdated` handler, which **silently rewrites the user's
input**:

```php
if ($state > $availableSpace) {
    $set('quantity', $availableSpace); // user's number silently replaced
    Notification::make()->title('Quantity exceeds maximum limit')…->warning()->send();
}
```

A user typing 10 gets 4 with a transient toast. Nothing on the server re-checks, so any non-form path
(crafted request, seeder, future API) bypasses the limit entirely.

**Existing precedent to follow:** `StockTransactionService.php:39-48` enforces `max_limit` server-side by
clamping `$newBalance` to the limit. There is no equivalent for stock requests.

**Fix:** add server-side validation in the service — reject (do not silently clamp) when
`quantity > max_limit - current_stock`, naming the division and item. Keep the form's helper text as UX,
but stop mutating the user's quantity; surface it as a validation error.

This bug is a **prerequisite** for the fan-out (see Trap below), so fix it before P4/P5.

## P4 — Three create paths produce inconsistent rows

**Severity: medium (drift, confusing data).**

| Path | Sets |
|---|---|
| `ListAtkStockRequests` create modal (`:23-38`) | `division_id`, `requester_id`; leaves `status` to the DB default `draft` |
| `ListAtkStockRequests` publish footer (`:40-52`) | above + `status = Published`, then `createApproval` |
| `CreateAtkStockRequest::mutateFormDataBeforeCreate` (`:14-18`) | `requester_id`, `division_id`; no `status`, no `request_type` |
| `AtkStockRequestsTable` edit modal (`:187-197`) | `division_id`, and `status` derived from `$arguments['draft']` / prior status |

**Fix:** centralize defaults in the service so every caller gets identical rows, then assert parity in a
test.

### Correction: `request_type` is vestigial — stop setting it

Earlier I flagged `request_type` as a blocker because three values appear in the codebase
(`'addition'` in the migration default and seeder, `'Office Stationery'` in the factory, `'regular'` in
`AtkStockRequestDivisionTest`). Verified: **nothing reads it for `AtkStockRequest`.**

- No form field exists for it (`rg request_type app/Filament/Resources/AtkStockRequests/` → no matches).
- `StockUpdateService::handleStockUpdates:38` branches on `isset($model->request_type)` and calls
  `updateStockByRequestType`, which **early-returns for `AtkStockRequest`** at `:391-401`
  ("Skipping automatic stock update ... for manual fulfillment model").
- The `switch ($modelClass)` case for `AtkStockRequest` at `:47-49` is therefore **unreachable** — the
  `isset` branch always wins. `updateStockForAddition` is dead code.

So `request_type` is a column nothing consumes. **Stop inventing values for it:** omit it from the
service and let the DB default `'addition'` apply. Do not "unify" it to a new value — that would churn
existing rows for no behaviour change. If the mixed values in the factory/test bother you, fixing them is
a cosmetic follow-up, not part of this plan.

While here, note for the record: because **both** dispatch paths skip `AtkStockRequest`, division stock
for stock requests is updated **only** by `FulfillmentService` (manual partial fulfillment). That is
intended, confirmed by the "manual fulfilment" comments — but it means the `switch` branch and
`updateStockForAddition` are misleading dead code worth deleting in a separate cleanup.

## P5 — `divisions->first()?->id` silently picks an arbitrary division

**Severity: low now, blocking for the fan-out (P6).**
Four sites: `CreateAtkStockRequest:16`, `ListAtkStockRequests:28` and `:38`, `AtkStockRequestsTable:187`.

`User::divisions` is a many-to-many with no guaranteed order, so `first()` is arbitrary. For a user with
no divisions it yields `null`, and `division_id` is `constrained('user_divisions')` — an FK violation,
not a graceful failure.

**Fix:** require an explicit selection; validate and fail loudly instead of defaulting. This becomes
mandatory for the fan-out, where a scalar has no meaning anyway.

---

# Phase 2 — Design 3 fan-out

## P6 — Form: single `Select` → `CheckboxList` on create, scalar `Select` on edit

`app/Filament/Resources/AtkStockRequests/Schemas/AtkStockRequestForm.php:34-49`

Add a **separate** field name so the scalar stays meaningful where it is still needed:

```php
CheckboxList::make('division_ids')
    ->label('Divisi')
    ->options(fn () => auth()->user()->isSuperAdmin()
        ? UserDivision::all()->pluck('name', 'id')
        : auth()->user()->divisions->pluck('name', 'id'))
    ->required()
    ->minItems(1)
    ->columns(2)
    ->visible(fn (string $operation) => $operation === 'create'),
```

and keep `division_id` for edit/view as a scalar, formatted from the record on edit.

Semantics to preserve deliberately:

- `->hidden()` does **not** clear a value on submit; `->dehydrated(false)` does. The current
  `->hidden(fn …count() <= 1)` guard relies on the hidden-but-dehydrated `division_id`. Do not carry that
  pattern over silently — the old guard is exactly what P5 removes.
- Non-admin users must not be able to submit a division they are not assigned to. The `options()` list is
  presentation, **not** authorization (see P7).

## P7 — Service: fan-out method with authorization and per-division validation

Add to `app/Services/StockRequestService.php`:

```php
/**
 * Create one single-division request per selected division, atomically.
 *
 * @return array<int, AtkStockRequest>
 */
public function createStockRequestsForDivisions(User $user, array $data): array
{
    $divisionIds = array_values(array_unique($data['division_ids']));

    $this->authorizeDivisions($user, $divisionIds);   // writes are NOT covered by DivisionScoped
    $this->validateLimits($divisionIds, $data['items']); // P3, now per division

    return DB::transaction(function () use ($user, $divisionIds, $data) {
        return array_map(fn (int $divisionId) => $this->createStockRequest($user, [
            'division_id' => $divisionId,
            'status' => $data['status'] ?? null,
            'notes' => $data['notes'] ?? null,
            'items' => $data['items'],
        ]), $divisionIds);
    });
}
```

Properties that carry the correctness argument:

- **One outer transaction** → all-or-nothing; no half-submitted multi-division request.
- **`array_unique`** → duplicate selections cannot create duplicate requests.
- **Items copied, not shared** → one `atk_stock_request_items` row set per request, so each division's
  quantities are independent. Correct: each division has its own stock, budget, and approval flow.
- **Validation before the transaction** → limits are checked for every division up front, so a rejection
  cannot leave partial writes even if a driver lacks savepoints.
- **`authorizeDivisions` is load-bearing.** `DivisionScoped` protects reads; there is no write-side
  equivalent. Without this, a crafted `division_ids` payload creates requests in divisions the user
  cannot see.

**Nested transactions note:** `createStockRequest` opens its own `DB::transaction`. Nested, this becomes a
savepoint (Laravel) — correct, since the inner generator also opens a transaction. If the target driver
does not support savepoints, hoist the body into a private `createOne()` that does not open a
transaction. Confirm the driver before relying on savepoints.

## P8 — Wire the create paths

- **`ListAtkStockRequests` create modal:** route through `createStockRequestsForDivisions`. A plain
  `CreateAction` with default behaviour creates exactly one record, so hook `using()` and return the
  primary record (or redirect to the list).
- **`publish` footer action:** same fan-out, then `status = Published` on every created request and
  `createApproval` per request — N requests means N approvals.
- **`CreateAtkStockRequest` page:** `CreateRecord` creates exactly one record; overriding it to fan out
  fights the component. **Recommend** keeping the multi-select on the list-page modal only and leaving
  this page single-division, or removing it from navigation. One create path, less divergence.
- **`AtkStockRequestsTable` edit modal:** keeps the **scalar** `division_id`. Editing an existing request
  must not re-split it — that would orphan its approval and fulfillment history.

## P9 — Result feedback

- Notification stating how many requests were created and for which divisions; if publish was used,
  state that N approvals started.
- Redirect to the list (which already filters by the user's divisions), so all N are visible.
- Confirm the table's division filter is not pinned to `divisions->first()` (P5 removes that default).

---

## The trap, and how the design eliminates it

**The trap.** Five sites in `AtkStockRequestForm` compute
`AtkDivisionStockSetting::where('division_id', $get('../../division_id'))` and
`AtkDivisionStock::where('division_id', $get('../../division_id'))`. With multi-select, `division_id`
does not exist, so:

- the helper text degrades to "No limit / Unlimited";
- the `afterStateUpdated` cap **silently stops firing**, so quantity is never checked against anything;
- the only max-limit enforcement in the whole stock-request flow disappears.

Because that browser check is currently the *sole* enforcement (P3), multi-select would not just weaken
validation — it would remove it. A user could request 10,000 units against a limit of 50 and every layer
would accept it.

**Elimination, not mitigation.** Two changes in P3/P7 make the trap structurally impossible:

1. **Move enforcement to the server, per division, before any write** (`validateLimits`, P7). The check
   no longer depends on a single form-scoped `division_id`, so it cannot be bypassed by multi-select,
   a crafted payload, a seeder, or a future API. This is the authoritative check that should have existed
   all along.
2. **Reject instead of clamp** (P3). `StockTransactionService` clamps because it reconciles a balance;
   a *request* is an assertion about intent, so silently reducing it misrepresents the user. Reject with a
   message naming the offending division and item.

The per-division loop in P7 also means the limit is evaluated **per division**, which is the only correct
semantics: division A's `max_limit` says nothing about division B's. A single scalar check was always
wrong for a multi-division user — the trap merely made that visible.

**Design rule going forward:** no validation may depend on a form-scoped scalar for a field that becomes
a collection. The five `$get('../../division_id')` sites become presentation-only (helper text against a
user-chosen preview division) or are removed; none may be the enforcement point.

---

## Tests

Pest v4, `tests/Feature`, `RefreshDatabase`, seeding `RoleSeeder` + `PermissionSeeder`, and
`Filament::setCurrentPanel(Filament::getPanel('dashboard'))` — matching `AtkStockRequestDivisionTest`.

### P1 / P2

1. **Per-division sequential numbering.** Two divisions; 2 requests for A, then 1 for B. Assert A is
   `…00000001`, `…00000002` and B is `…00000001` — B unaffected by A's volume.
   **This fails on current code** — it is the regression guard for P1.
2. **Generator actually used.** Assert the `ATK-{INITIAL}-REQ-` shape, proving the service no longer
   hand-rolls the number.
3. **Forced collision retries.** Pre-insert the number the generator will compute, then create; assert no
   `UniqueConstraintViolationException` escapes. Guard for P2.
4. **Concurrency claim stated honestly.** A single-threaded test cannot prove P2 fixed; record what was
   and was not verified.

### P3

5. **Server rejects over-limit, does not clamp.** `max_limit = 5`, stock 0, quantity 10 → validation
   error; assert quantity is **unchanged** (not rewritten to 5) and no request persisted.
6. **Unlimited division still passes.** No `AtkDivisionStockSetting` row → large quantity accepted.

### P4

7. **Row parity across create paths.** Create via the modal, via `CreateAtkStockRequest`, and via the edit
   modal; assert identical `status` and that `request_type` matches the DB default.

### P5

8. **No arbitrary default.** A user with multiple divisions and no explicit selection gets a validation
   error, not `divisions->first()`.
9. **No-division user.** Assert a loud, catchable failure rather than an FK violation.

### P6–P9

10. **N divisions → N requests, items copied.** Divisions A+B, 2 items → exactly 2 requests, each with 2
    items whose `item_id`/`quantity` match; **4 item rows total**.
11. **Atomic rollback.** Force failure on the second division (mock `ApprovalService::createApproval` to
    throw); assert **zero** requests and zero items persist.
12. **Duplicate selection idempotent.** `division_ids = [A, A, B]` → 2 requests.
13. **Authorization on the write path.** Non-admin submits a division they are not assigned to → rejected,
    no row created. Highest-value security test; `DivisionScoped` does not cover writes.
14. **Single-division user unchanged.** One request, correct division, existing behaviour intact.
15. **Per-division limits in the fan-out.** A limited (5) and B unlimited, quantity 10 → rejected naming
    A; **no request created for either division** (proves validation precedes the transaction).
16. **Existing suites green.** `AtkStockRequestDivisionTest`, `AtkStockRequestStatusTest`,
    `AtkStockRequestApprovalProgressTest`, `AtkStockRequestFulfillmentTest`,
    `AtkStockRequestTransactionsRelationManagerTest`, `AtkStockRequestExportTest`,
    `AtkFulfillmentResourceTest`, `StockRequestEmailTest`, `UserMultiDivisionTest`,
    `ApprovalServiceTest`, `ApprovalPinningTest`.

    `AtkStockRequestDivisionTest` creates a request with a scalar `division_id` and
    `request_type => 'regular'`. It should pass untouched — that it does is evidence the single-division
    path survived. If it needs edits, the fan-out has leaked into the single-division case.

---

## Sequencing

| Step | Content | Tests | Mergeable alone |
|---|---|---|---|
| 1 | **P1 + P2** — number generation (delete manual block) + collision retry | 1–4 | yes |
| 2 | **P3** — server-side per-division limit enforcement, reject not clamp | 5–6 | yes |
| 3 | **P4 + P5** — centralize defaults, remove arbitrary `first()` | 7–9 | yes |
| 4 | **P6 + P7 + P8** — multi-select, fan-out, wiring | 10–15 | no |
| 5 | **P9** — feedback + any test adjustments | 16 | no |

Steps 1–3 fix live bugs and must be able to ship without steps 4–5. Step 2 before step 4 is
non-negotiable: it is what makes the trap impossible rather than merely patched.

## Verification

Focused first, then full; report actual output.

```bash
php artisan test --filter=AtkStockRequest
php artisan test --filter=StockRequest
php artisan test --filter=AtkFulfillment
composer test
vendor/bin/pint --dirty
```

Re-index CodeGraph once after the change set (`.codegraph/` is present).

## Remaining decisions

1. **Create surface** — list-page modal only (recommended; `CreateRecord` creates exactly one record), or
   also `CreateAtkStockRequest`?
2. **Retry vs counter row** for P2.
3. **P3 rejection UX** — hard validation error, or clamp-with-error as `StockTransactionService` does?
   (Recommend rejection: a request states intent; silently reducing it misrepresents the user.)
4. **Dead-code cleanup** — delete the unreachable `AtkStockRequest` branch in `handleStockUpdates` and
   `updateStockForAddition` now, or as a separate follow-up? (Recommend separate, to keep these commits
   behavioural.)
