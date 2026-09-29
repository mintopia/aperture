<?php

declare(strict_types=1);

namespace Tests\Unit\Concerns;

use App\Services\NetworkSwitch\IosOutputParser;

trait CreatesIosOutputParser
{
    private IosOutputParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new IosOutputParser;
    }
}
