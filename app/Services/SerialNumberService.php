<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\SerialCounter;
use Illuminate\Support\Facades\DB;

class SerialNumberService
{
    /**
     * Generate unique serial numbers for a given category.
     *
     * Format: {CATEGORY_CODE}-{YYMM}-{XXXXXXXX}
     * e.g.  E-2602-00000001
     *
     * Uses SELECT FOR UPDATE to prevent concurrent collisions.
     *
     * @param int $categoryId
     * @param int $count  How many serials to generate
     * @return string[]   Array of serial numbers
     */
    public function generate(int $categoryId, int $count = 1): array
    {
        return DB::transaction(function () use ($categoryId, $count) {
            $category = Category::findOrFail($categoryId);

            // Build prefix: e.g. "E-2602" (category code + YYMM)
            $catCode = $category->code ?: strtoupper(substr($category->name, 0, 3));
            $yymm    = now()->format('ym'); // 26 + 02 = 2602
            $prefix  = "{$catCode}-{$yymm}";

            // Lock the counter row (or create it)
            $counter = SerialCounter::where('category_id', $categoryId)
                ->where('prefix', $prefix)
                ->lockForUpdate()
                ->first();

            if (!$counter) {
                $counter = SerialCounter::create([
                    'category_id' => $categoryId,
                    'prefix'      => $prefix,
                    'last_number' => 0,
                ]);

                // Re-lock after insert
                $counter = SerialCounter::where('id', $counter->id)
                    ->lockForUpdate()
                    ->first();
            }

            $serials   = [];

            // Self-heal: some inventories may have been inserted bypassing this counter
            // (e.g. stock-count IMPORT sets serials with no PO). Start from the true max
            // used serial so newly generated ones never collide with existing rows.
            $maxSerial = Inventory::where('serial_number', 'like', $prefix . '-%')->max('serial_number');
            $maxUsed   = $maxSerial ? (int) substr($maxSerial, strlen($prefix) + 1) : 0;

            $startFrom = max($counter->last_number, $maxUsed) + 1;

            for ($i = 0; $i < $count; $i++) {
                $num       = $startFrom + $i;
                $serials[] = sprintf('%s-%08d', $prefix, $num);
            }

            // Atomic update
            $counter->update(['last_number' => $startFrom + $count - 1]);

            return $serials;
        });
    }

    /**
     * Generate a production order number.
     * Format: PO-YYMM-XXXX
     */
    public function generateOrderNumber(): string
    {
        return DB::transaction(function () {
            $yymm   = now()->format('ym');
            $prefix = "PO-{$yymm}";

            // Use category_id = NULL as sentinel for PO counters
            $counter = SerialCounter::whereNull('category_id')
                ->where('prefix', $prefix)
                ->lockForUpdate()
                ->first();

            if (!$counter) {
                $counter = SerialCounter::create([
                    'category_id' => null,
                    'prefix'      => $prefix,
                    'last_number' => 0,
                ]);
                $counter = SerialCounter::where('id', $counter->id)
                    ->lockForUpdate()
                    ->first();
            }

            $next = $counter->last_number + 1;
            $counter->update(['last_number' => $next]);

            return sprintf('%s-%04d', $prefix, $next);
        });
    }
}
