<?php

declare(strict_types=1);

namespace Tests\Unit\Concerns;

use App\Services\NetworkSwitch\IosOutputParser;

/**
 * Shared setUp() for the NetworkSwitch IOS parser test cluster
 * (IosOutputParserTest, IosOutputParserDhcpTest, CiscoBulkCommandTest),
 * which all instantiate a bare IosOutputParser with no dependencies.
 */
trait CreatesIosOutputParser
{
    private IosOutputParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new IosOutputParser;
    }
}
