<?php

namespace Tests;

use DatabaseTransactions\RetryHelper\Console\InstallCommand;
use Symfony\Component\Console\Tester\CommandTester;

test('install command publishes package assets', function (): void {
    $command = new class () extends InstallCommand {
        /** @var list<array{0:string,1:array}> */
        public array $calls = [];
        /** @var list<string> */
        public array $infos = [];
        /** @var list<string> */
        public array $lines = [];

        public function call($command, array $arguments = [])
        {
            $this->calls[] = [$command, $arguments];

            return 0;
        }

        public function info($string, $verbosity = null): void
        {
            $this->infos[] = (string) $string;
        }

        public function line($string, $style = null, $verbosity = null): void
        {
            $this->lines[] = (string) $string;
        }
    };

    $command->setLaravel($this->app);

    $tester   = new CommandTester($command);
    $exitCode = $tester->execute(['--force' => true]);

    expect($exitCode)->toBe(0);

    $tags = array_map(static fn (array $call): ?string => $call[1]['--tag'] ?? null, $command->calls);
    expect($tags)->toBe([
        'database-transaction-retry-config',
        'database-transaction-retry-migrations',
        'database-transaction-retry-dashboard-provider',
        'database-transaction-retry-dashboard',
    ]);

    foreach ($command->calls as $call) {
        expect($call[1]['--force'])->toBeTrue();
    }

    expect(implode("\n", $command->infos))->toContain('Installing');
    expect(implode("\n", $command->lines))->toContain('php artisan migrate');
});
