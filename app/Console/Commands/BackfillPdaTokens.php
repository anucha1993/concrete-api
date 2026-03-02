<?php

namespace App\Console\Commands;

use App\Models\PdaToken;
use App\Models\StockDeduction;
use Illuminate\Console\Command;

class BackfillPdaTokens extends Command
{
    protected $signature = 'app:backfill-pda-tokens';
    protected $description = 'Create PdaToken records for existing stock deductions that have pda_token but no matching pda_tokens row';

    public function handle(): void
    {
        $deductions = StockDeduction::whereNotNull('pda_token')
            ->whereIn('status', ['PENDING', 'IN_PROGRESS', 'COMPLETED'])
            ->get();

        $this->info("Found {$deductions->count()} deductions with pda_token");

        $created = 0;
        foreach ($deductions as $d) {
            $exists = PdaToken::where('token', $d->pda_token)->exists();
            if (!$exists) {
                PdaToken::create([
                    'token'      => $d->pda_token,
                    'name'       => 'ตัดสต๊อก ' . $d->code,
                    'created_by' => $d->created_by,
                    'expires_at' => now()->addHours(24),
                ]);
                $created++;
                $this->line("  Created PdaToken for {$d->code}");
            } else {
                $this->line("  {$d->code} already has PdaToken — skipped");
            }
        }

        $this->info("Done. Created {$created} PdaToken records.");
    }
}
