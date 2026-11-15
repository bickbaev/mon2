<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use Illuminate\Console\Command;

class CloseDueCampaigns extends Command
{
    protected $signature = 'campaigns:close-due';

    protected $description = 'Закрыть сборы с наступившей датой закрытия';

    public function handle(): int
    {
        Campaign::query()->whereNull('closed_at')->whereDate('closes_at', '<=', today())
            ->update(['closed_at' => now()]);

        return self::SUCCESS;
    }
}
