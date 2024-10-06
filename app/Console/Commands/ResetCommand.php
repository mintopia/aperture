<?php

namespace App\Console\Commands;

use App\Models\IpAddress;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
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
    public function handle()
    {
        $confirmed = confirm('Are you sure you want to reset Aperture?');
        if (!$confirmed) {
            $this->output->writeln("Exiting");
            return 0;
        }

        // IPs
        IpAddress::query()->chunk(100, function (Collection $ips) {
            foreach ($ips as $ip) {
                /**
                 * @var $ip IpAddress
                 */
                if ($ip->limited) {
                    $this->output->writeln("{$ip} Unlimiting");
                    $ip->unlimit();
                }
                $ip->deny();
                $ip->delete();
                $this->output->writeln("{$ip} Deleted");
            }
        });

        // Delete Users
        $ids = User::query()->whereHas('roles', function ($query) {
            $query->whereCode('admin');
        })->pluck('id');

        User::query()->whereNotIn('id', $ids)->chunk(100, function(Collection $users) {
            foreach ($users as $user) {
                $this->output->writeln("{$user} Deleted ");
                $user->delete();
            }
        });

        $this->output->writeln("Finished");
    }
}
