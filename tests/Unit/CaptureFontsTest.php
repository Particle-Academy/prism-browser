<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Prism\Browser\Capture\CaptureOrigins;
use Prism\Browser\Capture\CaptureRequest;
use Prism\Browser\Capture\PageCapture;
use Prism\Browser\Exceptions\BrowserRefused;

function fontCapture(Factory $http): PageCapture
{
    return new PageCapture($http, CaptureOrigins::parse('https://flabs.gen'), 'http://127.0.0.1:4319', str_repeat('t', 40));
}

function fontRefusal(callable $call): BrowserRefused
{
    try {
        $call();
    } catch (BrowserRefused $refused) {
        return $refused;
    }
    throw new RuntimeException('Expected a refusal');
}

it('keeps font requirements opt-in and sends the validated faces', function () {
    $face = ['family' => 'Inter', 'weight' => 400, 'style' => 'normal'];
    expect((new CaptureRequest([375]))->requireFonts)->toBe([]);
    expect((new CaptureRequest([375], requireFonts: [['family' => str_repeat('😀', 128), 'weight' => 1000, 'style' => 'oblique']]))->requireFonts)->toHaveCount(1);
    $http = new Factory;
    $http->fake(['*' => $http->response(['error' => 'capture_fonts_failed'], 422)]);
    fontRefusal(fn () => fontCapture($http)->capture('https://flabs.gen/', new CaptureRequest([375], requireFonts: [$face])));
    $http->assertSent(fn (Request $request) => $request['require_fonts'] === [$face]);
});

it('refuses invalid font request shapes and values', function (mixed $value) {
    expect(fontRefusal(fn () => new CaptureRequest([375], requireFonts: $value))->code())->toBe('invalid_capture_options');
})->with([
    'null' => [null], 'string' => ['Inter'], 'map' => [['family' => 'Inter']], 'missing fields' => [[['family' => 'Inter']]],
    'empty family' => [[['family' => '', 'weight' => 400, 'style' => 'normal']]],
    'blank family' => [[['family' => '   ', 'weight' => 400, 'style' => 'normal']]],
    'long family' => [[['family' => str_repeat('x', 129), 'weight' => 400, 'style' => 'normal']]],
    'long unicode family' => [[['family' => str_repeat('😀', 129), 'weight' => 400, 'style' => 'normal']]],
    'invalid unicode' => [[['family' => "\xff", 'weight' => 400, 'style' => 'normal']]],
    'low weight' => [[['family' => 'a', 'weight' => 0, 'style' => 'normal']]],
    'high weight' => [[['family' => 'a', 'weight' => 1001, 'style' => 'normal']]],
    'string weight' => [[['family' => 'a', 'weight' => '400', 'style' => 'normal']]],
    'float weight' => [[['family' => 'a', 'weight' => 400.5, 'style' => 'normal']]],
    'bad style' => [[['family' => 'a', 'weight' => 400, 'style' => 'Normal']]],
    'too many' => [array_fill(0, 17, ['family' => 'a', 'weight' => 400, 'style' => 'normal'])],
]);

it('preserves valid bounded font failure context and drops other fields', function (string $status) {
    $face = ['family' => 'Inter', 'weight' => 400, 'style' => 'normal', 'status' => $status];
    $http = new Factory;
    $http->fake(['*' => $http->response(['error' => 'capture_fonts_failed', 'failed_fonts' => [$face + ['secret' => 'drop']], 'failed_fonts_total' => 1, 'secret' => 'drop'], 422)]);
    $error = fontRefusal(fn () => fontCapture($http)->capture('https://flabs.gen/', new CaptureRequest([375])));
    expect($error->code())->toBe('capture_fonts_failed')->and($error->context())->toBe(['failed_fonts' => [$face], 'failed_fonts_total' => 1]);
})->with(['missing', 'unloaded', 'error', 'fallback_rendered']);

it('drops malformed font failure details while retaining the refusal', function (array $detail) {
    $http = new Factory;
    $http->fake(['*' => $http->response(['error' => 'capture_fonts_failed', ...$detail], 422)]);
    $error = fontRefusal(fn () => fontCapture($http)->capture('https://flabs.gen/', new CaptureRequest([375])));
    expect($error->code())->toBe('capture_fonts_failed')->and($error->context())->toBe([]);
})->with([
    [[]], [['failed_fonts' => []]], [['failed_fonts' => 'a', 'failed_fonts_total' => 1]],
    [['failed_fonts' => [['family' => 'a', 'weight' => '400', 'style' => 'normal', 'status' => 'error']], 'failed_fonts_total' => 1]],
    [['failed_fonts' => [['family' => 'a', 'weight' => 400, 'style' => 'normal', 'status' => 'loaded']], 'failed_fonts_total' => 1]],
    [['failed_fonts' => [['family' => 'a', 'weight' => 400, 'style' => 'normal', 'status' => 'error']], 'failed_fonts_total' => 0]],
    [['failed_fonts' => array_fill(0, 17, ['family' => 'a', 'weight' => 400, 'style' => 'normal', 'status' => 'error']), 'failed_fonts_total' => 17]],
]);

/** @param array<string, mixed> $extra */
function fontFactsResponse(array $extra): Factory
{
    // Same small real PNG at every requested width; header dimensions are checked independently by PageCapture.
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 375, 900, 8, 2, 0, 0, 0))
        .$chunk('IDAT', (string) gzcompress(str_repeat("\0".str_repeat("\xff\xff\xff", 375), 900))).$chunk('IEND', '');
    $http = new Factory;
    $http->fake(['*' => $http->response(['captures' => [[
        'width' => 375, 'captured_width' => 375, 'viewport_height' => 900, 'page_height' => 900,
        'captured_height' => 900, 'clipped' => false, 'png' => base64_encode($png),
        'facts' => ['scroll_width' => 375, 'horizontal_overflow' => false, 'small_targets' => [], 'small_targets_total' => 0, ...$extra],
    ]]])]);

    return $http;
}

it('reads bounded font facts and defaults missing old-sidecar fields to empty lists', function () {
    $fonts = [['family' => 'Inter', 'weight' => '100 900', 'style' => 'normal', 'status' => 'loaded']];
    $rendered = [['family' => 'Inter', 'glyph_count' => 4, 'custom' => true], ['family' => 'Emoji', 'glyph_count' => 1, 'custom' => false]];
    $facts = fontCapture(fontFactsResponse(['fonts' => $fonts, 'rendered_fonts' => $rendered, 'fonts_measured' => true]))->capture('https://flabs.gen/', new CaptureRequest([375]))[0]->facts;
    expect($facts?->fonts)->toBe($fonts)->and($facts?->renderedFonts)->toBe($rendered)->and($facts?->fontsMeasured)->toBeTrue();
    $old = fontCapture(fontFactsResponse([]))->capture('https://flabs.gen/', new CaptureRequest([375]))[0]->facts;
    expect($old?->fonts)->toBe([])->and($old?->renderedFonts)->toBe([])->and($old?->fontsMeasured)->toBeFalse();
    $unmeasured = fontCapture(fontFactsResponse(['fonts' => [], 'rendered_fonts' => [], 'fonts_measured' => false]))->capture('https://flabs.gen/', new CaptureRequest([375]))[0]->facts;
    expect($unmeasured?->fontsMeasured)->toBeFalse();
});

it('refuses malformed font facts', function (array $extra) {
    expect(fontRefusal(fn () => fontCapture(fontFactsResponse($extra))->capture('https://flabs.gen/', new CaptureRequest([375])))->code())->toBe('malformed_sidecar_response');
})->with([
    [['fonts' => 'a']], [['fonts' => null]], [['fonts' => [42]]], [['fonts' => ['a' => []]]],
    [['fonts' => [['family' => 'a', 'weight' => 400, 'style' => 'normal', 'status' => 'loaded']]]],
    [['fonts' => [['family' => str_repeat('a', 129), 'weight' => '400', 'style' => 'normal', 'status' => 'loaded']]]],
    [['fonts' => [['family' => 'a', 'weight' => '400', 'style' => 'normal', 'status' => 'fake']]]],
    [['fonts' => array_fill(0, 51, ['family' => 'a', 'weight' => '400', 'style' => 'normal', 'status' => 'loaded'])]],
    [['rendered_fonts' => null]], [['rendered_fonts' => 'a']], [['rendered_fonts' => [42]]],
    [['rendered_fonts' => [['family' => 'a', 'glyph_count' => -1, 'custom' => true]]]],
    [['rendered_fonts' => [['family' => 'a', 'glyph_count' => '1', 'custom' => true]]]],
    [['rendered_fonts' => [['family' => 'a', 'glyph_count' => 1, 'custom' => 1]]]],
    [['rendered_fonts' => array_fill(0, 21, ['family' => 'a', 'glyph_count' => 1, 'custom' => true])]],
    [['fonts_measured' => null]], [['fonts_measured' => 0]], [['fonts_measured' => 'true']],
]);
