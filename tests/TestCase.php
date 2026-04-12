<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /** @var resource|null */
    protected static $mockServerProcess;

    protected static bool $mockServerStarted = false;

    public static function startMockOpnSenseServer(): void
    {
        if (self::$mockServerStarted) {
            return;
        }

        $serverScript = __DIR__.'/mock-opnsense-server.php';
        if (! file_exists($serverScript)) {
            return;
        }

        // Check if server is already running
        $connection = @fsockopen('127.0.0.1', 19199, $errno, $errstr, 1);
        if ($connection) {
            fclose($connection);
            self::$mockServerStarted = true;

            return;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        self::$mockServerProcess = proc_open(
            'php -S 127.0.0.1:19199 '.$serverScript,
            $descriptors,
            $pipes
        );

        // Wait for server to start
        $maxAttempts = 20;
        for ($i = 0; $i < $maxAttempts; $i++) {
            usleep(100000);
            $connection = @fsockopen('127.0.0.1', 19199, $errno, $errstr, 1);
            if ($connection) {
                fclose($connection);
                self::$mockServerStarted = true;

                return;
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        static::startMockOpnSenseServer();
    }
}
