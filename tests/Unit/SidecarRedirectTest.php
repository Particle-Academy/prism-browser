<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Prism\Browser\Capture\CaptureOrigins;
use Prism\Browser\Capture\CaptureRequest;
use Prism\Browser\Capture\PageCapture;
use Prism\Browser\Engine\PlaywrightSidecarEngine;
use Prism\Browser\Exceptions\BrowserRefused;
use Prism\Browser\Security\BrowserPolicy;

require_once __DIR__.'/../Support/RedirectLoopback.php';

/*
 * Sidecar redirects, against real loopback servers.
 *
 * A sidecar request follows a redirect only when the scheme, host and port stay
 * the same as the configured sidecar URL. Anything else is refused before a
 * second request is made, so the other server receives nothing at all.
 */

const SIDECAR_REDIRECT_TOKEN = 'fake-sidecar-token-0000000000000000000001';

beforeEach(function (): void {
    $this->sidecar = RedirectLoopback::start('127.0.0.1');
    $this->elsewhere = RedirectLoopback::start('127.0.0.2');
});

afterEach(function (): void {
    $this->sidecar->stop();
    $this->elsewhere->stop();
});

function redirectEngine(string $baseUrl, ?Factory $http = null): PlaywrightSidecarEngine
{
    return new PlaywrightSidecarEngine($http ?? new Factory, new BrowserPolicy(['flabs.gen']), $baseUrl, SIDECAR_REDIRECT_TOKEN, 5);
}

function redirectCapture(string $baseUrl, ?Factory $http = null): PageCapture
{
    return new PageCapture($http ?? new Factory, CaptureOrigins::parse('https://flabs.gen'), $baseUrl, SIDECAR_REDIRECT_TOKEN);
}

/** @return array{0: ?string, 1: string} the refusal code and message, if one was raised */
function sidecarRefusal(callable $call): array
{
    try {
        $call();
    } catch (BrowserRefused $refused) {
        return [$refused->code(), $refused->getMessage()];
    }

    return [null, ''];
}

it('does not follow an engine redirect to another host and port', function (int $status): void {
    $this->sidecar->answer([['match' => '/v1/', 'status' => $status, 'location' => $this->elsewhere->origin().'/landed']]);

    [$code, $message] = sidecarRefusal(fn () => redirectEngine($this->sidecar->origin())->checkpoint('att_1'));

    expect($this->elsewhere->requests())->toBe([])
        ->and($this->sidecar->requests())->toHaveCount(1)
        ->and($code)->toBe('sidecar_redirect_refused')
        ->and($message)->not->toContain(SIDECAR_REDIRECT_TOKEN);
})->with([301, 302, 303, 307, 308]);

it('does not follow a capture redirect to another host and port', function (int $status): void {
    $this->sidecar->answer([['match' => '/v1/capture', 'status' => $status, 'location' => $this->elsewhere->origin().'/landed']]);

    [$code, $message] = sidecarRefusal(fn () => redirectCapture($this->sidecar->origin())->capture('https://flabs.gen/', new CaptureRequest([375])));

    expect($this->elsewhere->requests())->toBe([])
        ->and($this->elsewhere->received())->not->toContain('flabs.gen')
        ->and($code)->toBe('sidecar_redirect_refused')
        ->and($message)->not->toContain(SIDECAR_REDIRECT_TOKEN);
})->with([302, 307]);

it('does not follow a sidecar redirect to the same host on another port', function (): void {
    $other = RedirectLoopback::start('127.0.0.1');

    try {
        $this->sidecar->answer([['match' => '/v1/', 'status' => 307, 'location' => $other->origin().'/landed']]);

        [$code] = sidecarRefusal(fn () => redirectEngine($this->sidecar->origin())->checkpoint('att_1'));

        expect($other->requests())->toBe([])
            ->and($code)->toBe('sidecar_redirect_refused');
    } finally {
        $other->stop();
    }
});

it('does not follow a sidecar redirect that changes the scheme', function (): void {
    $this->sidecar->answer([['match' => '/v1/', 'status' => 308, 'location' => 'https://{host}/landed']]);

    [$code] = sidecarRefusal(fn () => redirectEngine($this->sidecar->origin())->checkpoint('att_1'));

    expect($this->sidecar->requests())->toHaveCount(1)
        ->and($code)->toBe('sidecar_redirect_refused');
});

it('follows a sidecar redirect that keeps the scheme, host and port', function (): void {
    $this->sidecar->answer([['match' => '/v1/', 'status' => 307, 'location' => '/landed']], body: '{"checkpoint":"cp_1"}');

    $checkpoint = redirectEngine($this->sidecar->origin())->checkpoint('att_1');

    $requests = $this->sidecar->requests();

    expect($checkpoint)->toBe('cp_1')
        ->and($requests)->toHaveCount(2)
        ->and($requests[1]['uri'])->toBe('/landed')
        ->and($this->elsewhere->requests())->toBe([]);
});

/*
 * Hostile Location headers. Each row is a Location the sidecar answers with:
 * {a} is the sidecar's host:port, {e} the other server's, {pa} and {pe} their
 * ports. Whatever the encoding, the other server must receive nothing, and the
 * caller must get a BrowserRefused rather than an HTTP client exception.
 */
function hostileLocation(string $template, RedirectLoopback $sidecar, RedirectLoopback $elsewhere): string
{
    return strtr($template, [
        '{a}' => "{$sidecar->host}:{$sidecar->port}",
        '{e}' => "{$elsewhere->host}:{$elsewhere->port}",
        '{pa}' => (string) $sidecar->port,
        '{pe}' => (string) $elsewhere->port,
    ]);
}

/** @return array{0: ?string, 1: string} */
function sidecarCall(string $entry, string $baseUrl, ?Factory $http = null): array
{
    return sidecarRefusal($entry === 'engine'
        ? fn () => redirectEngine($baseUrl, $http)->checkpoint('att_1')
        : fn () => redirectCapture($baseUrl, $http)->capture('https://flabs.gen/', new CaptureRequest([375])));
}

dataset('sidecar entry points', ['engine', 'capture']);

it('refuses a hostile Location and sends nothing to the other origin', function (string $location, string $entry): void {
    $this->sidecar->answer([['match' => '/v1/', 'status' => 307, 'location' => hostileLocation($location, $this->sidecar, $this->elsewhere)]]);

    [$code, $message] = sidecarCall($entry, $this->sidecar->origin());

    expect($this->elsewhere->requests())->toBe([])
        ->and($this->elsewhere->received())->not->toContain(SIDECAR_REDIRECT_TOKEN)
        ->and($this->sidecar->requests())->toHaveCount(1)
        ->and($code)->toBeIn(['sidecar_redirect_refused', 'sidecar_failed'])
        ->and($message)->not->toContain(SIDECAR_REDIRECT_TOKEN);
})->with([
    'scheme-relative' => '//{e}/landed',
    'backslashes for slashes' => 'http:\\\\{e}/landed',
    'sidecar as userinfo' => 'http://{a}@{e}/landed',
    'fragment before @' => 'http://{e}#@{a}/landed',
    'query before @' => 'http://{e}?@{a}/landed',
    'upper-case scheme, other host' => 'HTTP://{e}/landed',
    'other host on the default port' => 'http://127.0.0.2:80/landed',
    'sidecar host on the default port' => 'http://127.0.0.1:80/landed',
    'sidecar host on a zero-padded default port' => 'http://127.0.0.1:080/landed',
    'other host with a trailing dot' => 'http://127.0.0.2.:{pe}/landed',
    'sidecar host with a trailing dot' => 'http://127.0.0.1.:{pa}/landed',
    'other host as IPv4-mapped IPv6' => 'http://[::ffff:127.0.0.2]:{pe}/landed',
    'other host as hex IPv4-mapped IPv6' => 'http://[::ffff:7f00:2]:{pe}/landed',
    'sidecar host as IPv4-mapped IPv6' => 'http://[::ffff:127.0.0.1]:{pa}/landed',
    'https to the other host' => 'https://{e}/landed',
    'https to the sidecar host' => 'https://{a}/landed',
    'non-http scheme' => 'ftp://{e}/landed',
    'port out of range' => 'http://127.0.0.2:99999/landed',
    'empty host' => 'http://:{pe}/landed',
    'unclosed IPv6 bracket' => 'http://[127.0.0.2:{pe}/landed',
])->with('sidecar entry points');

it('never reaches a host named only in the userinfo', function (string $location, string $entry): void {
    $this->sidecar->answer([['match' => '/v1/', 'status' => 307, 'location' => hostileLocation($location, $this->sidecar, $this->elsewhere)]], body: '{"checkpoint":"cp_1"}');

    sidecarCall($entry, $this->sidecar->origin());

    // The authority of these Locations is the sidecar itself, so following them
    // is allowed; reaching the host spelled in the userinfo is not.
    expect($this->elsewhere->requests())->toBe([]);
})->with([
    'backslash before @' => 'http://{e}\@{a}/landed',
    'encoded hash before @' => 'http://{e}%23@{a}/landed',
])->with('sidecar entry points');

it('follows a Location that keeps the sidecar origin', function (string $location, string $entry): void {
    $this->sidecar->answer([['match' => '/v1/', 'status' => 307, 'location' => hostileLocation($location, $this->sidecar, $this->elsewhere)]], body: '{"checkpoint":"cp_1"}');

    [$code] = sidecarCall($entry, $this->sidecar->origin());

    $requests = $this->sidecar->requests();

    expect($requests)->toHaveCount(2)
        ->and($requests[1]['uri'])->toBe('/landed')
        ->and($this->elsewhere->requests())->toBe([])
        // The engine answers; the capture follows, then refuses a body that is not a capture.
        ->and($code)->toBe($entry === 'engine' ? null : 'malformed_sidecar_response');
})->with([
    'relative path' => '../landed',
    'absolute path' => '/landed',
    'explicit same port' => 'http://{a}/landed',
    'upper-case scheme' => 'HTTP://{a}/landed',
])->with('sidecar entry points');

it('compares the sidecar host case-insensitively', function (): void {
    $this->sidecar->answer([['match' => '/v1/', 'status' => 307, 'location' => "http://LocalHost:{$this->sidecar->port}/landed"]], body: '{"checkpoint":"cp_1"}');

    $checkpoint = redirectEngine("http://localhost:{$this->sidecar->port}")->checkpoint('att_1');

    expect($checkpoint)->toBe('cp_1')
        ->and($this->sidecar->requests())->toHaveCount(2);
});

it('follows no redirect when the application turned redirects off', function (mixed $off, string $entry): void {
    $this->sidecar->answer([['match' => '/v1/', 'status' => 307, 'location' => '/landed']], body: '{"checkpoint":"cp_1"}');
    $http = new Factory;
    $http->globalOptions(['allow_redirects' => $off]);

    [$code] = sidecarCall($entry, $this->sidecar->origin(), $http);

    expect($this->sidecar->requests())->toHaveCount(1)
        ->and($code)->toBe('sidecar_failed');
})->with([
    'false' => [false],
    'zero' => [0],
    'empty string' => [''],
    'null' => [null],
])->with('sidecar entry points');

it('keeps the origin check when the application allows redirects', function (mixed $setting, string $entry): void {
    $this->sidecar->answer([['match' => '/v1/', 'status' => 307, 'location' => $this->elsewhere->origin().'/landed']]);
    $http = new Factory;
    $http->globalOptions(['allow_redirects' => $setting]);

    [$code] = sidecarCall($entry, $this->sidecar->origin(), $http);

    expect($this->elsewhere->requests())->toBe([])
        ->and($code)->toBe('sidecar_redirect_refused');
})->with([
    'true' => [true],
    'its own settings' => [['max' => 10]],
])->with('sidecar entry points');

it('refuses a same-origin redirect loop as a sidecar failure', function (string $entry): void {
    $this->sidecar->answer([['match' => '/v1/', 'status' => 307, 'location' => '/v1/again']]);

    [$code] = sidecarCall($entry, $this->sidecar->origin());

    expect($code)->toBe('sidecar_failed')
        ->and($this->sidecar->requests())->toHaveCount(6)
        ->and($this->elsewhere->requests())->toBe([]);
})->with('sidecar entry points');

it('refuses an unreachable sidecar as a sidecar failure', function (string $entry): void {
    $gone = RedirectLoopback::start('127.0.0.1');
    $origin = $gone->origin();
    $gone->stop();

    [$code] = sidecarCall($entry, $origin);

    expect($code)->toBe('sidecar_failed');
})->with('sidecar entry points');
