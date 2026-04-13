<?php

declare(strict_types=1);

namespace App\Services\SshProxy;

use phpseclib3\Net\SSH2;

class CommandExecutor
{
    public function __construct(
        protected int $readTimeoutSeconds = 5,
        protected int $commandTimeoutSeconds = 30,
    ) {}

    /**
     * @param  array<int, array{command: string, if?: string, expect?: string}>  $commands
     * @return array{success: bool, output: array<int, array{command: string, output: string}>, error?: string}
     */
    public function execute(SSH2 $ssh, array $commands): array
    {
        $output = [];
        $lastOutput = '';

        // Read initial prompt
        $ssh->setTimeout($this->readTimeoutSeconds);
        $initial = $ssh->read();
        if (is_string($initial)) {
            $lastOutput = $initial;
        }

        foreach ($commands as $cmd) {
            // Check "if" condition
            if (isset($cmd['if'])) {
                $lastLine = $this->getLastLine($lastOutput);
                if (! $this->matchesCondition($lastLine, $cmd['if'])) {
                    continue;
                }
            }

            // Write command
            $ssh->write($cmd['command']."\n");

            // Read output
            if (isset($cmd['expect'])) {
                $ssh->setTimeout($this->commandTimeoutSeconds);
                $result = $this->readUntilExpect($ssh, $cmd['expect']);
                if ($result === null) {
                    return [
                        'success' => false,
                        'output' => $output,
                        'error' => sprintf('Timeout waiting for expected pattern: %s', $cmd['expect']),
                    ];
                }

                $lastOutput = $result;
            } else {
                $ssh->setTimeout($this->readTimeoutSeconds);
                $result = $ssh->read();
                $lastOutput = is_string($result) ? $result : '';
            }

            $output[] = [
                'command' => $cmd['command'],
                'output' => $lastOutput,
            ];
        }

        return [
            'success' => true,
            'output' => $output,
        ];
    }

    protected function getLastLine(string $output): string
    {
        $lines = array_filter(explode("\n", trim($output)));

        return end($lines) ?: '';
    }

    protected function matchesCondition(string $text, string $condition): bool
    {
        // If starts and ends with /, treat as regex
        if (strlen($condition) > 2 && $condition[0] === '/' && $condition[-1] === '/') {
            return (bool) preg_match($condition, $text);
        }

        // Otherwise substring match
        return str_contains($text, $condition);
    }

    protected function readUntilExpect(SSH2 $ssh, string $expect): ?string
    {
        $buffer = '';
        $startTime = time();

        while ((time() - $startTime) < $this->commandTimeoutSeconds) {
            $chunk = $ssh->read();
            if (is_string($chunk) && $chunk !== '') {
                $buffer .= $chunk;
                $lastLine = $this->getLastLine($buffer);
                if ($this->matchesCondition($lastLine, $expect)) {
                    return $buffer;
                }
            } else {
                // No data, check if we already have the expected output
                $lastLine = $this->getLastLine($buffer);
                if ($this->matchesCondition($lastLine, $expect)) {
                    return $buffer;
                }

                usleep(50000); // 50ms
            }
        }

        return null;
    }
}
