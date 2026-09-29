<?php

namespace App\Services\Inventory;

use App\Models\Inventory\InventoryDispatch;
use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\InventoryProduction;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Final = Opening + Received (GRN, accepted) + Produced - Consumed - Wastage - Dispatched.
// "Received" is read from the GRN ledger (bucket 'available', same SKU text)
// and never written here, so the vendor -> GRN flow stays exactly as it is.
class InventoryService
{
    /**
     * @param  Collection<int, InventoryItem>  $items
     * @return array<int, array{opening: float, received: float, produced: float, consumed: float, wastage: float, dispatched: float, final: float}>
     */
    public function balances(Collection $items): array
    {
        if ($items->isEmpty()) {
            return [];
        }

        $received = LedgerEntry::query()
            ->where('bucket', 'available')
            ->whereIn('sku', $items->pluck('sku'))
            ->groupBy('sku')
            ->selectRaw('sku, SUM(qty) as total')
            ->pluck('total', 'sku');

        $moved = InventoryMovement::query()
            ->whereIn('item_id', $items->pluck('id'))
            ->groupBy('item_id', 'type')
            ->selectRaw('item_id, type, SUM(qty) as total')
            ->get()
            ->groupBy('item_id');

        $balances = [];
        foreach ($items as $item) {
            $byType = ($moved->get($item->id) ?? collect())->pluck('total', 'type');
            $row = [
                'opening' => (float) $item->opening_qty,
                'received' => (float) ($received[$item->sku] ?? 0),
                'produced' => (float) ($byType['produced'] ?? 0),
                'consumed' => (float) ($byType['consumed'] ?? 0),
                'wastage' => (float) ($byType['wastage'] ?? 0),
                'dispatched' => (float) ($byType['dispatched'] ?? 0),
            ];
            $row['final'] = round($row['opening'] + $row['received'] + $row['produced'] - $row['consumed'] - $row['wastage'] - $row['dispatched'], 3);
            $balances[$item->id] = $row;
        }

        return $balances;
    }

    public function available(InventoryItem $item): float
    {
        return $this->balances(collect([$item]))[$item->id]['final'];
    }

    /**
     * "Create Bundle": consume RM/PM per the FG recipe (+ any wastage) and add FG.
     *
     * @param  array<int, float|string|null>  $wastage  component item id => extra qty wasted
     */
    public function produce(InventoryItem $fg, float $qty, array $wastage, ?string $notes, User $user): InventoryProduction
    {
        if ($fg->item_type !== 'fg') {
            throw ValidationException::withMessages(['fgItemId' => 'Only a Finished Good can be produced.']);
        }
        if ($qty <= 0) {
            throw ValidationException::withMessages(['qty' => 'Quantity to produce must be more than 0.']);
        }

        return DB::transaction(function () use ($fg, $qty, $wastage, $notes, $user) {
            $lines = $fg->bomLines()->with('component')->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['fgItemId' => "Add a recipe (RM/PM per 1 {$fg->unit}) to {$fg->displayName()} first."]);
            }

            // Lock the component rows so two people can't use the same stock at once.
            InventoryItem::whereIn('id', $lines->pluck('component_item_id'))->lockForUpdate()->get();
            $balances = $this->balances($lines->pluck('component'));

            $errors = [];
            foreach ($lines as $line) {
                $need = round((float) $line->qty_per_unit * $qty, 3);
                $waste = round(max(0, (float) ($wastage[$line->component_item_id] ?? 0)), 3);
                $have = $balances[$line->component_item_id]['final'];
                if ($need + $waste > $have + 0.0005) {
                    $errors["wastage.{$line->component_item_id}"] = "Not enough {$line->component->displayName()}: need ".($need + $waste)." {$line->component->unit}, only {$have} in stock.";
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            $production = InventoryProduction::create([
                'number' => 'TMP-'.Str::uuid(), 'fg_item_id' => $fg->id, 'qty_produced' => $qty,
                'notes' => $notes, 'created_by' => $user->id,
            ]);
            $production->update(['number' => sprintf('PRD-%05d', $production->id)]);

            foreach ($lines as $line) {
                $this->move($line->component_item_id, 'consumed', round((float) $line->qty_per_unit * $qty, 3), $user, production: $production);
                $waste = round(max(0, (float) ($wastage[$line->component_item_id] ?? 0)), 3);
                if ($waste > 0) {
                    $this->move($line->component_item_id, 'wastage', $waste, $user, production: $production);
                }
            }
            $this->move($fg->id, 'produced', $qty, $user, production: $production);

            return $production;
        });
    }

    public function dispatch(InventoryItem $item, float $qty, string $customer, ?string $reference, ?string $notes, User $user): InventoryDispatch
    {
        if (! in_array($item->item_type, InventoryItem::SELLABLE_TYPES, true)) {
            throw ValidationException::withMessages(['itemId' => 'Only Finished Goods and Trading Goods can be dispatched.']);
        }
        if ($qty <= 0) {
            throw ValidationException::withMessages(['qty' => 'Quantity to dispatch must be more than 0.']);
        }

        return DB::transaction(function () use ($item, $qty, $customer, $reference, $notes, $user) {
            InventoryItem::whereKey($item->id)->lockForUpdate()->first();
            $have = $this->available($item);
            if ($qty > $have + 0.0005) {
                throw ValidationException::withMessages(['qty' => "Only {$have} {$item->unit} of {$item->displayName()} in stock."]);
            }

            $dispatch = InventoryDispatch::create([
                'number' => 'TMP-'.Str::uuid(), 'item_id' => $item->id, 'qty' => $qty,
                'customer_name' => $customer, 'reference' => $reference, 'notes' => $notes, 'created_by' => $user->id,
            ]);
            $dispatch->update(['number' => sprintf('DSP-%05d', $dispatch->id)]);
            $this->move($item->id, 'dispatched', $qty, $user, dispatch: $dispatch);

            return $dispatch;
        });
    }

    private function move(int $itemId, string $type, float $qty, User $user, ?InventoryProduction $production = null, ?InventoryDispatch $dispatch = null): void
    {
        InventoryMovement::create([
            'item_id' => $itemId, 'type' => $type, 'qty' => $qty,
            'production_id' => $production?->id, 'dispatch_id' => $dispatch?->id, 'created_by' => $user->id,
        ]);
    }
}
