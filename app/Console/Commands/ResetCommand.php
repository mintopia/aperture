<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ResetAperture;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;

class ResetCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'aperture:reset';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset the firewall access and users';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $confirmed = confirm('Are you sure you want to reset Aperture?');
        if (! $confirmed) {
            $this->output->writeln('Exiting');

            return self::SUCCESS;
        }

        dispatch_sync(new ResetAperture);

        $this->output->writeln('Finished');

        return self::SUCCESS;
    }
}
