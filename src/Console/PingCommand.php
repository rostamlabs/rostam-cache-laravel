<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache\Console;

use Illuminate\Console\Command;
use Rostam\Cache\RostamManager;
use Rostam\Exceptions\RostamException;

class PingCommand extends Command
{
    protected $signature = 'rostam:ping {--connection= : The configured Rostam connection to reach}';

    protected $description = 'Check that a Rostam server is reachable on its binary TCP port';

    public function handle(RostamManager $manager): int
    {
        $name = $this->option('connection') ?: $manager->defaultConnection();

        try {
            $client = $manager->connection($name);

            $startedAt = microtime(true);
            $client->ping();
            $elapsed = (microtime(true) - $startedAt) * 1000;

            // A second ping on the now-warm pooled socket is the number worth
            // reading: the first one paid for the TCP (and TLS) handshake.
            $startedAt = microtime(true);
            $client->ping();
            $warm = (microtime(true) - $startedAt) * 1000;
        } catch (RostamException $exception) {
            $this->components->error("Rostam [{$name}] is unreachable: ".$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Rostam [%s] answered in %.2f ms (%.2f ms warm).',
            $name,
            $elapsed,
            $warm
        ));

        return self::SUCCESS;
    }
}
