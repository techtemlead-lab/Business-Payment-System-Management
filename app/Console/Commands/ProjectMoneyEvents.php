<?php

namespace App\Console\Commands;

use App\Domain\Finance\EventLogProjector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ProjectMoneyEvents extends Command
{
    protected $signature = 'treasury:project-events {--rebuild : Empty the event log and fill it again from the records}';

    protected $description = 'Bring the Treasury event log up to date from the existing money records';

    public function handle(EventLogProjector $projector): int
    {
        $result = Cache::lock('money-events', 300)->block(60, fn (): int => $this->option('rebuild') ? $projector->rebuild() : $projector->catchUp());
        $this->info('Event log rows added: '.$result);

        return self::SUCCESS;
    }
}
