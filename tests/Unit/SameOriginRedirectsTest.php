<?php

declare(strict_types=1);

use Prism\Browser\Support\SameOriginRedirects;

/*
 * What "redirects off" means, and how many hops an application allows.
 *
 * The same rows are used in prism-mcp, prism-human-plus and prism-browser, so
 * the three packages agree on one definition. It follows Guzzle's own reading
 * of `allow_redirects` (RedirectMiddleware): any empty value, or an empty
 * `max`, follows nothing. Where Guzzle would reject the value outright, it is
 * read as off rather than replaced by a default that follows. The answer is
 * never more than 5 hops.
 */

it('reads an allow_redirects setting the way the HTTP client does', function (mixed $setting, ?int $hops): void {
    expect(SameOriginRedirects::hopLimit($setting))->toBe($hops);
})->with([
    'true' => [true, 5],
    'false' => [false, null],
    'null' => [null, null],
    'int 0' => [0, null],
    'int 1' => [1, null],
    'empty string' => ['', null],
    'string 0' => ['0', null],
    'string false' => ['false', null],
    'string true' => ['true', null],
    'object' => [new stdClass, null],
    'empty array' => [[], null],
    'array without max' => [['strict' => true], 5],
    'max 0' => [['max' => 0], null],
    'max null' => [['max' => null], null],
    'max -1' => [['max' => -1], null],
    'max string 0' => [['max' => '0'], null],
    'max false' => [['max' => false], null],
    'max true' => [['max' => true], null],
    'max abc' => [['max' => 'abc'], null],
    'max 1.5' => [['max' => 1.5], null],
    'max string -1' => [['max' => '-1'], null],
    'max 1' => [['max' => 1], 1],
    'max 3' => [['max' => 3], 3],
    'max string 3' => [['max' => '3'], 3],
    'max 5' => [['max' => 5], 5],
    'max 50' => [['max' => 50], 5],
    'max with a leading space' => [['max' => ' 3'], null],
    'max with a trailing newline' => [['max' => "3\n"], null],
    'max too large for an int' => [['max' => '99999999999999999999'], 5],
]);

it('treats the default port and an explicit default port as one origin', function (string $a, string $b): void {
    expect(SameOriginRedirects::origin($a))->not->toBeNull()
        ->and(SameOriginRedirects::origin($a))->toBe(SameOriginRedirects::origin($b));
})->with([
    'https' => ['https://h', 'https://h:443'],
    'http' => ['http://h', 'http://h:80'],
    'https with a path' => ['https://h/v1/act', 'https://h:443/landed'],
    'host case' => ['https://H', 'https://h:443'],
]);

it('treats another port, scheme or host as another origin', function (string $a, string $b): void {
    expect(SameOriginRedirects::origin($a))->not->toBe(SameOriginRedirects::origin($b));
})->with([
    'https on another port' => ['https://h', 'https://h:8443'],
    'http on another port' => ['http://h', 'http://h:8080'],
    'https default port over http' => ['http://h:443', 'https://h:443'],
    'http to https' => ['http://h', 'https://h'],
    'another host' => ['https://h', 'https://g'],
]);
