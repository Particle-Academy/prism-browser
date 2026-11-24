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

function redirectEngine(string $baseUrl): PlaywrightSidecarEngine
{
    return new PlaywrightSidecarEngine(new Factory, new BrowserPolicy(['flabs.gen']), $baseUrl, SIDECAR_REDIRECT_TOKEN, 5);
}

function redirectCapture(string $baseUrl): PageCapture
{
    return new PageCapture(new Factory, CaptureOrigins::parse('https://flabs.gen'), $baseUrl, SIDECAR_REDIRECT_TOKEN);
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
