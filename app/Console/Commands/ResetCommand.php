<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ResetAperture;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;

class ResetCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'aperture:reset';

    /**
     * @var string
     */
    protected $description = 'Remove all IP and MAC addresses, their associations, and non-admin users';

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
