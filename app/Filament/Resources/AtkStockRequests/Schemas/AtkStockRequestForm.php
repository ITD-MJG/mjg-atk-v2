<?php

namespace App\Filament\Resources\AtkStockRequests\Schemas;

use App\Enums\AtkStockRequestStatus;
use App\Models\ApprovalHistory;
use App\Models\AtkCategory;
use App\Models\AtkDivisionStock;
use App\Models\AtkDivisionStockSetting;
use App\Models\AtkItem;
use App\Models\UserDivision;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class AtkStockRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('General Information')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                CheckboxList::make('division_ids')
                                    ->label('Divisi')
                                    ->options(function () {
                                        if (auth()->user()->isSuperAdmin()) {
                                            return UserDivision::all()->pluck('name', 'id');
                                        }

                                        return auth()->user()->divisions->pluck('name', 'id');
                                    })
                                    ->required()
                                    ->minItems(1)
                                    ->columns(2)
                                    ->visible(fn (string $operation) => $operation === 'create')
                                    ->dehydrated(fn (string $operation) => $operation === 'create')
                                    ->helperText('Satu permintaan akan dibuat untuk setiap divisi yang dipilih.'),
                                Select::make('division_id')
                                    ->label('Divisi')
                                    ->options(function () {
                                        if (auth()->user()->isSuperAdmin()) {
                                            return UserDivision::all()->pluck('name', 'id');
                                        }

                                        return auth()->user()->divisions->pluck('name', 'id');
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->live()
                                    ->visible(fn (string $operation) => $operation !== 'create')
                                    ->dehydrated(fn (string $operation) => $operation !== 'create'),
                                TextInput::make('request_number')
                                    ->label('Nomor Permintaan')
                                    ->placeholder('Auto-generated')
                                    ->disabled()
                                    ->dehydrated(false),
                            ]),
                    ]),
                Section::make('Rejection Details')
                    ->visible(function ($get, $record) {
                        // Show this section only when there's a rejection
                        if (! $record) {
                            return false;
                        }

                        // Check if there are any rejection records in ApprovalHistory
                        $hasRejection = ApprovalHistory::where('approvable_type', 'App\Models\AtkStockRequest')
                            ->where('approvable_id', $record->id)
                            ->where('action', 'rejected')
                            ->exists();

                        return $hasRejection;
                    })
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('rejection_reason')
                                    ->label('Rejection Reason')
                                    ->readOnly()
                                    ->dehydrated(false) // Don't include this in form data
                                    ->formatStateUsing(function ($record) {
                                        if (! $record) {
                                            return null;
                                        }

                                        // Get the most recent rejection reason from ApprovalHistory
                                        $rejection = ApprovalHistory::where('approvable_type', 'App\Models\AtkStockRequest')
                                            ->where('approvable_id', $record->id)
                                            ->where('action', 'rejected')
                                            ->latest('performed_at')
                                            ->first();

                                        return $rejection ? $rejection->rejection_reason : null;
                                    }),
                                TextInput::make('rejector_name')
                                    ->label('Rejected By')
                                    ->readOnly()
                                    ->dehydrated(false) // Don't include this in form data
                                    ->formatStateUsing(function ($record) {
                                        if (! $record) {
                                            return null;
                                        }

                                        // Get the most recent rejection's user from ApprovalHistory
                                        $rejection = ApprovalHistory::where('approvable_type', 'App\Models\AtkStockRequest')
                                            ->where('approvable_id', $record->id)
                                            ->where('action', 'rejected')
                                            ->latest('performed_at')
                                            ->first();

                                        return $rejection ? $rejection->user->name : null;
                                    }),
                            ]),
                    ]),
                Section::make('Stock Request Items')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('atkStockRequestItems')
                            ->relationship()
                            ->cloneable()
                            ->extraItemActions([
                                Action::make('add_new_after')
                                    ->icon('heroicon-m-plus')
                                    ->color('primary')
                                    ->action(function (array $arguments, Repeater $component) {
                                        $state = $component->getState();
                                        $currentKey = $arguments['item'];

                                        $newKey = uniqid('item_');
                                        // Pre-populate with empty values for proper binding
                                        $newItem = [
                                            'item_category_id' => null,
                                            'item_id' => null,
                                            'category_id' => null,
                                            'quantity' => null,
                                            'notes' => null,
                                        ];

                                        // Insert at correct position
                                        $keys = array_keys($state);
                                        $currentIndex = array_search($currentKey, $keys);

                                        $newState = array_slice($state, 0, $currentIndex + 1, true) +
                                                [$newKey => $newItem] +
                                                array_slice($state, $currentIndex + 1, null, true);

                                        $component->state($newState);
                                    }),
                            ])
                            ->schema([
                                Select::make('category_id')
                                    ->label('Category')
                                    ->options(AtkCategory::all()->pluck('name', 'id'))
                                    ->searchable()
                                    ->reactive()
                                    ->preload()
                                    ->live()
                                    ->afterStateUpdated(function (callable $set, callable $get, $state) {
                                        // Reset item selection when category changes
                                        $set('item_id', null);
                                    }),
                                Select::make('item_id')
                                    ->label('Item')
                                    ->options(function (callable $get) {
                                        $categoryId = $get('category_id');
                                        if (! $categoryId) {
                                            return AtkItem::all()->pluck('name', 'id');
                                        }

                                        return AtkItem::where('category_id', $categoryId)
                                            ->pluck('name', 'id');
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (callable $set, callable $get, $state) {
                                        // Automatically set the category_id when item is selected
                                        if ($state) {
                                            $item = AtkItem::find($state);
                                            if ($item) {
                                                $set('category_id', $item->category_id);
                                            }
                                        }

                                        // Update current_mac when item_id changes
                                        if ($state) {
                                            $stock = AtkDivisionStock::where('division_id', $get('../../division_id'))
                                                ->where('item_id', $state)
                                                ->first();

                                            if ($stock) {
                                                $set('current_mac', 'Rp '.number_format($stock->moving_average_cost, 0, ',', '.'));
                                            } else {
                                                $set('current_mac', 'Rp 0');
                                            }
                                        } else {
                                            // If item_id is cleared, reset current_mac
                                            $set('current_mac', '');
                                        }

                                        // Update item_price when item_id changes
                                        if ($state) {
                                            $item = AtkItem::find($state);
                                            // Get the active price with the latest effective_date
                                            $priceModel = $item ? $item->activePrice()->first() : null;
                                            $price = $priceModel ? $priceModel->unit_price : 0;
                                            $set('item_price', 'Rp '.number_format($price, 0, ',', '.'));

                                            // Update new_mac_estimate when item_id changes
                                            $quantity = $get('quantity') ?? 0;
                                            if ($quantity > 0) {
                                                // Get current stock data
                                                $stock = AtkDivisionStock::where('division_id', $get('../../division_id'))
                                                    ->where('item_id', $state)
                                                    ->first();

                                                $currentStock = $stock ? $stock->current_stock : 0;
                                                $currentMac = $stock ? $stock->moving_average_cost : 0;

                                                // Calculate new MAC using the formula:
                                                // New MAC = ((Old Stock × Old MAC) + (Incoming Stock × Incoming Unit Cost)) / (Old Stock + Incoming Stock)
                                                $totalValue = ($currentStock * $currentMac) + ($quantity * $price);
                                                $totalQuantity = $currentStock + $quantity;

                                                if ($totalQuantity == 0) {
                                                    $newMac = 0;
                                                } else {
                                                    $newMac = $totalValue / $totalQuantity;
                                                }
                                                $set('new_mac_estimate', 'Rp '.number_format((int) round($newMac), 0, ',', '.'));
                                            } else {
                                                // If quantity is 0, just show current MAC
                                                $stock = AtkDivisionStock::where('division_id', $get('../../division_id'))
                                                    ->where('item_id', $state)
                                                    ->first();

                                                if ($stock) {
                                                    $set('new_mac_estimate', 'Rp '.number_format($stock->moving_average_cost, 0, ',', '.'));
                                                } else {
                                                    $set('new_mac_estimate', 'Rp 0');
                                                }
                                            }
                                        } else {
                                            // If item_id is cleared, clear dependent fields
                                            $set('item_price', '');
                                            $set('new_mac_estimate', '');
                                        }
                                    }),
                                TextInput::make('quantity')
                                    ->label('Quantity Requested')
                                    ->required()
                                    ->numeric()
                                    ->minValue(1)
                                    ->suffix(fn (callable $get) => AtkItem::find($get('item_id'))?->unit_of_measure)
                                    ->helperText(function (callable $get) {
                                        $itemId = $get('item_id');
                                        if (! $itemId) {
                                            return '';
                                        }

                                        $setting = AtkDivisionStockSetting::where('division_id', $get('../../division_id'))
                                            ->where('item_id', $itemId)
                                            ->first();

                                        $stock = AtkDivisionStock::where('division_id', $get('../../division_id'))
                                            ->where('item_id', $itemId)
                                            ->first();

                                        $currentStock = $stock ? $stock->current_stock : 0;
                                        $maxLimit = $setting ? $setting->max_limit : 'No limit';
                                        $availableSpace = $setting ? ($maxLimit - $currentStock) : 'Unlimited';

                                        return new HtmlString("
                                            <div class='flex flex-wrap gap-x-4 gap-y-1 text-xs'>
                                                <div class='bg-gray-100 px-2 py-0.5 rounded'><span class='text-gray-500'>Current:</span> <span class='font-medium'>{$currentStock}</span></div>
                                                <div class='bg-gray-100 px-2 py-0.5 rounded'><span class='text-gray-500'>Max:</span> <span class='font-medium'>{$maxLimit}</span></div>
                                                <div class='bg-green-50 px-2 py-0.5 rounded border border-green-100'><span class='text-green-600'>Available:</span> <span class='font-bold text-green-700'>{$availableSpace}</span></div>
                                            </div>
                                        ");
                                    })
                                    ->live()
                                    ->afterStateUpdated(function (callable $get, callable $set, $state) {
                                        $itemId = $get('item_id');

                                        // Over-limit quantities are reported by this field's validation
                                        // rule and re-checked server-side in StockRequestService. The value
                                        // is never rewritten here, so the user always sees their own input.

                                        // Update new_mac_estimate when quantity changes
                                        if ($itemId && $state) {
                                            $item = AtkItem::find($itemId);
                                            $priceModel = $item ? $item->activePrice()->first() : null;
                                            $itemPrice = $priceModel ? $priceModel->unit_price : 0;

                                            // Get current stock data
                                            $stock = AtkDivisionStock::where('division_id', $get('../../division_id'))
                                                ->where('item_id', $itemId)
                                                ->first();

                                            $currentStock = $stock ? $stock->current_stock : 0;
                                            $currentMac = $stock ? $stock->moving_average_cost : 0;

                                            // Calculate new MAC using the formula:
                                            // New MAC = ((Old Stock × Old MAC) + (Incoming Stock × Incoming Unit Cost)) / (Old Stock + Incoming Stock)
                                            $totalValue = ($currentStock * $currentMac) + ($state * $itemPrice);
                                            $totalQuantity = $currentStock + $state;

                                            if ($totalQuantity == 0) {
                                                $newMac = 0;
                                            } else {
                                                $newMac = $totalValue / $totalQuantity;
                                            }
                                            $set('new_mac_estimate', 'Rp '.number_format((int) round($newMac), 0, ',', '.'));
                                        } elseif (! $state) {
                                            // If quantity is 0, get current MAC (if item exists)
                                            if ($itemId) {
                                                $stock = AtkDivisionStock::where('division_id', $get('../../division_id'))
                                                    ->where('item_id', $itemId)
                                                    ->first();

                                                if ($stock) {
                                                    $set('new_mac_estimate', 'Rp '.number_format($stock->moving_average_cost, 0, ',', '.'));
                                                } else {
                                                    $set('new_mac_estimate', 'Rp 0');
                                                }
                                            } else {
                                                $set('new_mac_estimate', ''); // If no item either, clear field
                                            }
                                        }
                                    })
                                    ->rules([
                                        function () {
                                            return function (string $attribute, $value, \Closure $fail, $livewire) {
                                                // Extract the repeater index from the attribute name
                                                // e.g., "data.items.0.quantity" -> index 0
                                                preg_match('/atkStockRequestItems\.(\d+)\.quantity/', $attribute, $matches);
                                                $index = $matches[1] ?? null;

                                                if ($index === null) {
                                                    return;
                                                }

                                                // Get the item_id for this repeater item
                                                $itemId = data_get($livewire, "data.atkStockRequestItems.{$index}.item_id");

                                                if (! $itemId || ! $value) {
                                                    return;
                                                }

                                                // Validate against every selected division on create;
                                                // the request is single-division on edit.
                                                $divisionIds = $get('../../division_ids')
                                                    ?: array_filter([$get('../../division_id')]);

                                                if (empty($divisionIds)) {
                                                    return;
                                                }

                                                foreach ($divisionIds as $divisionId) {
                                                    $setting = AtkDivisionStockSetting::where('division_id', $divisionId)
                                                        ->where('item_id', $itemId)
                                                        ->first();

                                                    if (! $setting) {
                                                        continue; // No limit configured for this division/item.
                                                    }

                                                    $stock = AtkDivisionStock::where('division_id', $divisionId)
                                                        ->where('item_id', $itemId)
                                                        ->first();

                                                    $currentStock = $stock ? $stock->current_stock : 0;
                                                    $availableSpace = $setting->max_limit - $currentStock;

                                                    if ($value > $availableSpace) {
                                                        $divisionName = UserDivision::find($divisionId)?->name ?? $divisionId;
                                                        $fail("Quantity requested ({$value}) exceeds maximum available quantity ({$availableSpace}) for {$divisionName}.");
                                                    }
                                                }
                                            };
                                        },
                                    ]),

                                TextInput::make('current_mac')
                                    ->label('Current Average')
                                    ->readOnly()
                                    ->dehydrated(false)
                                    ->live()
                                    ->hidden()
                                    ->formatStateUsing(function (callable $get) {
                                        $itemId = $get('item_id');
                                        if (! $itemId) {
                                            return '';
                                        }

                                        $stock = AtkDivisionStock::where('division_id', $get('../../division_id'))
                                            ->where('item_id', $itemId)
                                            ->first();

                                        if (! $stock) {
                                            return 'Rp 0';
                                        }

                                        $mac = $stock->moving_average_cost ?? 0;

                                        return 'Rp '.number_format($mac, 0, ',', '.');
                                        // return $mac;
                                    })
                                    ->extraInputAttributes(['class' => 'bg-gray-50']),

                                TextInput::make('item_price')
                                    ->label('Item Price')
                                    ->readOnly()
                                    ->dehydrated(false)
                                    ->hidden()
                                    ->default('')
                                    ->extraInputAttributes(['class' => 'bg-blue-50']),

                                TextInput::make('new_mac_estimate')
                                    ->label('New Average')
                                    ->readOnly()
                                    ->dehydrated(false)
                                    ->hidden()
                                    ->default('')
                                    ->extraInputAttributes(['class' => 'bg-green-50']),
                            ])
                            ->columns(3)
                            ->minItems(1)
                            ->addActionLabel('Add Item')
                            ->reorderableWithButtons()
                            ->collapsible(),
                    ]),
                Hidden::make('status')
                    ->default(AtkStockRequestStatus::Draft),
            ]);
    }
}
