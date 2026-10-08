<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ResetAperture;
use App\Models\AuditLog;
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
    protected $description = 'Remove all IP and MAC addresses, their associations, non-admin users and the audit trail';

    public function handle(): int
    {
        $confirmed = confirm('Are you sure you want to reset Aperture?');
        if (! $confirmed) {
            $this->output->writeln('Exiting');

            return self::SUCCESS;
        }

        $auditLog = AuditLog::record(action: 'portal.reset', process: 'console');

        dispatch_sync(new ResetAperture($auditLog->id));

        $this->output->writeln('Finished');

        return self::SUCCESS;
    }
}
