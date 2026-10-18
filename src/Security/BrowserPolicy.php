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
            // A browser reads shorthand, integer, octal and hex IPv4 (127.1,
            // 0x7f000001, 2130706433) as an address, exactly as libc's
            // inet_aton does; parse_url leaves them as names. Read the same
            // way, without DNS, so they reach the address check.
            $ip = self::inetAton($ip);

            if ($ip === null) {
                return false;
            }
        }

        return self::isNonPublicAddress($ip);
    }

    /**
     * libc's inet_aton: one to four dot-separated parts, each decimal, octal
     * (leading 0) or hex (0x), the last filling every remaining byte. Null when
     * the text is not such an address, so a name stays a name.
     */
    private static function inetAton(string $text): ?string
    {
        $parts = explode('.', $text);

        if (count($parts) > 4) {
            return null;
        }

        $values = [];

        foreach ($parts as $part) {
            if (preg_match('/^0x[0-9a-f]*$/i', $part) === 1) {
                $values[] = $part === '0x' || $part === '0X' ? 0 : (int) hexdec(substr($part, 2));
            } elseif (preg_match('/^0[0-7]*$/', $part) === 1) {
                $values[] = (int) octdec($part);
            } elseif (preg_match('/^[1-9][0-9]*$/', $part) === 1) {
                $values[] = (int) $part;
            } else {
                return null;
            }
        }

        $last = array_pop($values);
        $value = 0;

        foreach ($values as $index => $byte) {
            if ($byte > 255) {
                return null;
            }

            $value |= $byte << (8 * (3 - $index));
        }

        $remaining = 4 - count($values);

        if ($last >= 1 << (8 * $remaining)) {
            return null;
        }

        return long2ip($value | $last);
    }

    /**
     * Every range a browser must not be pointed at, as an EXPLICIT list (G-89).
     *
     * filter_var's private and reserved flags were the whole check, and on PHP
     * 8.4 they admit CGNAT, where Alibaba Cloud's metadata endpoint lives, and
     * the NAT64 and 6to4 spellings of 169.254.169.254. The list is the one core's
     * guarded media fetch uses, spelled identically in every language rather
     * than delegated to each language's helpers; filter_var stays as a second
     * layer.
     */
    private const NON_PUBLIC = [
        ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8],
        ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24],
        ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24],
        ['224.0.0.0', 4], ['240.0.0.0', 4],
        ['::', 96], ['::ffff:0:0', 96], ['64:ff9b:1::', 48], ['100::', 64],
        ['2001::', 32], ['2001:db8::', 32], ['fc00::', 7], ['fe80::', 10],
        ['fec0::', 10], ['ff00::', 8],
    ];

    /**
     * NAT64 and 6to4 carry an IPv4 address inside an IPv6 one, and the packet
     * goes to the embedded address, so that is what is judged. Not refused
     * outright: a DNS64 network gives public sites 64:ff9b:: addresses.
     * [prefix, bits, byte offset of the embedded IPv4]
     */
    private const EMBEDDING = [
        ['64:ff9b::', 96, 12],
        ['2002::', 16, 2],
    ];

    private static function isNonPublicAddress(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return true;
        }

        foreach (self::EMBEDDING as [$prefix, $bits, $offset]) {
            if (strlen($packed) === 16 && self::inRange($packed, $prefix, $bits)) {
                return self::isNonPublicAddress((string) inet_ntop(substr($packed, $offset, 4)));
            }
        }

        foreach (self::NON_PUBLIC as [$network, $bits]) {
            if (self::inRange($packed, $network, $bits)) {
                return true;
            }
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private static function inRange(string $packed, string $network, int $bits): bool
    {
        $base = (string) inet_pton($network);

        if (strlen($packed) !== strlen($base)) {
            return false;
        }

        $bytes = intdiv($bits, 8);

        if (strncmp($packed, $base, $bytes) !== 0) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($base[$bytes]) & $mask);
    }
}
