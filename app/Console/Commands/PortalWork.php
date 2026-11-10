<?php

namespace App\Console\Commands;

use App\Portal\Jobs;
use Illuminate\Console\Command;

/* Runs what is due in the portal's own queue: Smart Intake readings, e-mails and events to retry. */
class PortalWork extends Command
{
    protected $signature = 'portal:work';

    protected $description = 'Run the background work that is due';

    public function handle(): int
    {
        $this->info(Jobs::drain(200).' job(s) run.');

        return self::SUCCESS;
    }
}
