<?php

namespace App\Jobs;

use App\Models\IpAddress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IpAddressAction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(protected IpAddress $ip, protected string $method)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->ip->{$this->method}();
    }
}
