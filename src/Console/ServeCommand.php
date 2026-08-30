<?php

declare(strict_types=1);

namespace Prism\Browser\Console;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

final class ServeCommand extends Command
{
    protected $signature = 'prism-browser:serve';

    protected $description = 'Run the local authenticated Playwright browser service in the foreground';

    public function handle(): int
    {
        $entry = dirname(__DIR__, 2).'/sidecar/server.mjs';
        if (! is_file($entry)) {
            $this->error('The prism-browser sidecar entry point is missing.');

            return self::FAILURE;
        }
        $token = config('prism-browser.sidecar.token');
        if (! is_string($token) || strlen($token) < 32) {
            $this->error('PRISM_BROWSER_TOKEN must contain at least 32 characters.');

            return self::FAILURE;
        }
        $environment = [
            'PRISM_BROWSER_HOST' => (string) config('prism-browser.sidecar.host', '127.0.0.1'),
            'PRISM_BROWSER_PORT' => (string) config('prism-browser.sidecar.port', 4319),
            'PRISM_BROWSER_TOKEN' => $token,
            'PRISM_BROWSER_ALLOW_UNVERIFIED_EGRESS' => (bool) config('prism-browser.sidecar.allow_unverified_egress', false) ? '1' : '0',
        ];
        $proxy = config('prism-browser.sidecar.egress_proxy');
        if (is_string($proxy) && $proxy !== '') {
            $environment['PRISM_BROWSER_EGRESS_PROXY'] = $proxy;
        }
        $process = new Process(['node', $entry], base_path(), $environment, null, null);
        $process->setTty(Process::isTtySupported());

        return $process->run(fn (string $type, string $output) => $this->output->write($output));
    }
}
