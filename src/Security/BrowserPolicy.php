<?php

declare(strict_types=1);

namespace Prism\Browser\Security;

use Prism\Browser\Enums\ActionKind;
use Prism\Browser\Exceptions\BrowserRefused;

final readonly class BrowserPolicy
{
    /**
     * @param  list<string>  $allowedHosts
     * @param  list<ActionKind>  $allowedActions
     * @param  list<int>  $allowedPorts
     */
    public function __construct(
        public array $allowedHosts,
        public array $allowedActions = [ActionKind::Click, ActionKind::Fill, ActionKind::Select, ActionKind::Press, ActionKind::Scroll, ActionKind::Hover],
        public bool $requireHttps = true,
        public int $maxObservationBytes = 65536,
        public array $allowedPorts = [443],
    ) {}

    public function assertUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;

        if (! is_string($scheme) || ! is_string($host)) {
            throw new BrowserRefused('invalid_url', 'Browser navigation requires an absolute URL.');
        }

        $host = strtolower(rtrim($host, '.'));
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new BrowserRefused('url_credentials_refused', 'Browser navigation URLs may not contain credentials.');
        }
        if ($this->requireHttps && $scheme !== 'https') {
            throw new BrowserRefused('https_required', 'Browser policy requires HTTPS.');
        }

        // `private_address_refused`, not `private_network_refused`: the test
        // below is per-ADDRESS, and an allow-list entry naming one does not
        // widen it. G-21 — the two ports have always used this spelling, and
        // the reference moved to it rather than the other way round.
        if ($this->isLocalOrPrivate($host)) {
            throw new BrowserRefused(
                'private_address_refused',
                sprintf(
                    'Browser policy refuses the private or loopback address [%s]. A browser an agent can point at a '
                    .'metadata endpoint or at localhost reaches services that assumed they were unreachable.',
                    $host,
                ),
            );
        }

        if (! $this->matchesAllowedHost($host)) {
            throw new BrowserRefused('host_not_allowed', sprintf('Browser policy does not allow host [%s].', $host));
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! in_array($port, $this->allowedPorts, true)) {
            throw new BrowserRefused('port_not_allowed', sprintf('Browser policy does not allow port [%d].', $port));
        }
    }

    public function assertAction(ActionKind $kind): void
    {
        if (! in_array($kind, $this->allowedActions, true)) {
            throw new BrowserRefused('action_not_allowed', sprintf('Browser policy does not allow action [%s].', $kind->value));
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'allowed_hosts' => $this->allowedHosts,
            'allowed_actions' => array_map(fn (ActionKind $action): string => $action->value, $this->allowedActions),
            'require_https' => $this->requireHttps,
            'max_observation_bytes' => $this->maxObservationBytes,
            'allowed_ports' => $this->allowedPorts,
        ];
    }

    private function matchesAllowedHost(string $host): bool
    {
        foreach ($this->allowedHosts as $allowed) {
            $allowed = strtolower(rtrim($allowed, '.'));
            if ($host === $allowed || (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1)))) {
                return true;
            }
        }

        return false;
    }

    private function isLocalOrPrivate(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return true;
        }

        $ip = trim($host, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
