<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SshProxy;

use App\Services\SshProxy\CommandExecutor;
use Mockery;
use phpseclib3\Net\SSH2;
use Tests\TestCase;

class CommandExecutorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_executes_unconditional_command(): void
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('setTimeout')->with(5);
        $ssh->shouldReceive('read')->once()->andReturn('switch>');
        $ssh->shouldReceive('write')->once()->with("show version\n");
        $ssh->shouldReceive('setTimeout')->with(30);
        $ssh->shouldReceive('read')->once()->andReturn('Cisco IOS version 15.2');

        $executor = new CommandExecutor(5, 30);
        $result = $executor->execute($ssh, [
            ['command' => 'show version'],
        ]);

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->output);
        $this->assertSame('show version', $result->output[0]->command);
        $this->assertSame('Cisco IOS version 15.2', $result->output[0]->output);
    }

    public function test_skips_command_when_if_regex_does_not_match(): void
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('setTimeout');
        $ssh->shouldReceive('read')->once()->andReturn('switch#');
        $ssh->shouldNotReceive('write');

        $executor = new CommandExecutor(5, 30);
        $result = $executor->execute($ssh, [
            ['command' => 'enable', 'if' => '/^.*>$/'],
        ]);

        $this->assertTrue($result->success);
        $this->assertCount(0, $result->output);
    }

    public function test_skips_command_when_if_substring_does_not_match(): void
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('setTimeout');
        $ssh->shouldReceive('read')->once()->andReturn('switch>');
        $ssh->shouldNotReceive('write');

        $executor = new CommandExecutor(5, 30);
        $result = $executor->execute($ssh, [
            ['command' => 'mypassword', 'if' => 'Password:'],
        ]);

        $this->assertTrue($result->success);
        $this->assertCount(0, $result->output);
    }

    public function test_executes_command_when_if_regex_matches(): void
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('setTimeout');
        $ssh->shouldReceive('read')->once()->andReturn('switch>');
        $ssh->shouldReceive('write')->once()->with("enable\n");
        $ssh->shouldReceive('read')->once()->andReturn('Password:');

        $executor = new CommandExecutor(5, 30);
        $result = $executor->execute($ssh, [
            ['command' => 'enable', 'if' => '/^.*>$/'],
        ]);

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->output);
        $this->assertSame('enable', $result->output[0]->command);
    }

    public function test_executes_command_when_if_substring_matches(): void
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('setTimeout');
        $ssh->shouldReceive('read')->once()->andReturn('Password:');
        $ssh->shouldReceive('write')->once()->with("mypassword\n");
        $ssh->shouldReceive('read')->once()->andReturn('switch#');

        $executor = new CommandExecutor(5, 30);
        $result = $executor->execute($ssh, [
            ['command' => 'mypassword', 'if' => 'Password:'],
        ]);

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->output);
        $this->assertSame('mypassword', $result->output[0]->command);
    }

    public function test_reads_until_expect_regex_pattern(): void
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('setTimeout');
        // Initial read
        $ssh->shouldReceive('read')->once()->andReturn('switch>');
        // Write command
        $ssh->shouldReceive('write')->once()->with("enable\n");
        // readUntilExpect reads chunks
        $ssh->shouldReceive('read')->once()->andReturn("Password:\nswitch#");

        $executor = new CommandExecutor(5, 30);
        $result = $executor->execute($ssh, [
            ['command' => 'enable', 'expect' => '/^.*#$/'],
        ]);

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->output);
        $this->assertStringContainsString('switch#', $result->output[0]->output);
    }

    public function test_reads_until_expect_substring_pattern(): void
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('setTimeout');
        $ssh->shouldReceive('read')->once()->andReturn('switch>');
        $ssh->shouldReceive('write')->once()->with("enable\n");
        $ssh->shouldReceive('read')->once()->andReturn('Enter Password:');

        $executor = new CommandExecutor(5, 30);
        $result = $executor->execute($ssh, [
            ['command' => 'enable', 'expect' => 'Password:'],
        ]);

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->output);
        $this->assertStringContainsString('Password:', $result->output[0]->output);
    }

    public function test_returns_error_on_command_timeout(): void
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('setTimeout');
        $ssh->shouldReceive('read')->once()->andReturn('switch>');
        $ssh->shouldReceive('write')->once()->with("enable\n");
        // readUntilExpect: keep returning non-matching data
        $ssh->shouldReceive('read')->andReturn('some unmatched output');

        // Use a very short timeout to make test fast
        $executor = new CommandExecutor(5, 1);
        $result = $executor->execute($ssh, [
            ['command' => 'enable', 'expect' => '/^NEVER_MATCH$/'],
        ]);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('Timeout waiting for expected pattern', $result->error);
    }

    public function test_handles_empty_command_list(): void
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('setTimeout');
        $ssh->shouldReceive('read')->once()->andReturn('switch>');

        $executor = new CommandExecutor(5, 30);
        $result = $executor->execute($ssh, []);

        $this->assertTrue($result->success);
        $this->assertCount(0, $result->output);
    }

    public function test_multi_command_sequence(): void
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('setTimeout');

        // Initial read returns prompt
        $readQueue = [
            'switch>',       // initial prompt
            'Password:',     // after 'enable' command
            "switch#\n",     // after password command
            "config output\nswitch(config)#", // after 'conf t' command
        ];
        $readIndex = 0;
        $ssh->shouldReceive('read')->andReturnUsing(function () use (&$readQueue, &$readIndex): string {
            return $readQueue[$readIndex++] ?? '';
        });

        $ssh->shouldReceive('write')->with("enable\n")->once();
        $ssh->shouldReceive('write')->with("mypassword\n")->once();
        $ssh->shouldReceive('write')->with("conf t\n")->once();

        $executor = new CommandExecutor(5, 30);
        $result = $executor->execute($ssh, [
            ['command' => 'enable', 'if' => '/^.*>$/'],
            ['command' => 'mypassword', 'if' => 'Password:'],
            ['command' => 'conf t'],
        ]);

        $this->assertTrue($result->success);
        $this->assertCount(3, $result->output);
        $this->assertSame('enable', $result->output[0]->command);
        $this->assertSame('mypassword', $result->output[1]->command);
        $this->assertSame('conf t', $result->output[2]->command);
    }
}
