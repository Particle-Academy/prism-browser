<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Prism\Browser\Capture\CaptureOrigins;
use Prism\Browser\Capture\CaptureRequest;
use Prism\Browser\Capture\PageCapture;
use Prism\Browser\Exceptions\BrowserRefused;
use Prism\Prism\ValueObjects\Media\Image;

/*
 * PAGE CAPTURE, the PHP side: it refuses an undeclared URL before any request, keeps every option in bounds, and trusts
 * nothing the sidecar returns. The capturing itself is tested against real Chromium in sidecar/capture.test.mjs.
 */

/** A real, minimal PNG of the given size (one colour, stored uncompressed). */
function pngOf(int $width, int $height): string
{
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $row = "\0".str_repeat("\xff\xff\xff", $width);

    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
        .$chunk('IDAT', (string) gzcompress(str_repeat($row, $height))).$chunk('IEND', '');
}

/** @param array<string, mixed> $overrides */
function captureEntry(int $width, int $pngWidth, int $pngHeight, array $overrides = []): array
{
    return [
        'width' => $width, 'captured_width' => $pngWidth, 'viewport_height' => 900, 'page_height' => max($pngHeight, 900),
        'captured_height' => $pngHeight, 'clipped' => false, 'png' => base64_encode(pngOf($pngWidth, $pngHeight)),
        'facts' => ['scroll_width' => $pngWidth, 'horizontal_overflow' => $pngWidth > $width, 'small_targets' => [
            ['tag' => 'button', 'role' => null, 'text' => 'x', 'x' => 1, 'y' => 2, 'width' => 20, 'height' => 20],
        ], 'small_targets_total' => 1],
        ...$overrides,
    ];
}

function pageCapture(Factory $http, string $origins = 'https://flabs.gen'): PageCapture
{
    return new PageCapture($http, CaptureOrigins::parse($origins), 'http://127.0.0.1:4319', str_repeat('t', 40));
}

function refusedCode(callable $fn): ?string
{
    try {
        $fn();
    } catch (BrowserRefused $refused) {
        return $refused->code();
    }

    return null;
}

describe('declared origins', function () {
    it('accepts exact origins and normalises them as a browser does', function () {
        expect(CaptureOrigins::parse('https://flabs.gen, HTTP://Capture.Test:8080/')->origins)
            ->toBe(['https://flabs.gen', 'http://capture.test:8080']);
    });

    it('refuses anything that is not exactly an origin', function (string $bad) {
        expect(refusedCode(fn () => CaptureOrigins::parse($bad)))->toBe('invalid_capture_origin');
        // An explicit default port is refused, as the sidecar refuses it: one spelling per origin, on both sides.
    })->with(['https://flabs.gen:443', 'https://flabs.gen/sites', 'https://flabs.gen?x=1', 'https://u:p@flabs.gen', 'ftp://flabs.gen', 'https://127.0.0.1', 'https://[::1]', 'flabs.gen']);

    it('declares by origin equality, nothing looser', function () {
        $origins = CaptureOrigins::parse('https://flabs.gen');
        expect($origins->declares('https://flabs.gen/sites/render/1'))->toBeTrue()
            ->and($origins->declares('https://flabs.gen.evil.test/'))->toBeFalse()
            ->and($origins->declares('http://flabs.gen/'))->toBeFalse()
            ->and($origins->declares('https://flabs.gen:8443/'))->toBeFalse()
            ->and($origins->declares('https://user@flabs.gen/'))->toBeFalse();
    });
});

describe('the request', function () {
    it('is refused out of bounds, never adjusted', function (array $args) {
        expect(refusedCode(fn () => new CaptureRequest(...$args)))->toBe('invalid_capture_options');
    })->with([
        'no widths' => [[[]]],
        'too narrow' => [[[100]]],
        'too wide' => [[[5000]]],
        'too many' => [[[300, 400, 500, 600, 700, 800, 900, 1000, 1100]]],
        'max height' => [[[375], 'maxHeight' => 99999]],
        'timeout' => [[[375], 'timeoutSeconds' => 0]],
    ]);

    it('sends the sidecar exactly the bounds it was given', function () {
        expect((new CaptureRequest([375, 768, 375], clip: true))->toArray())->toBe([
            'widths' => [375, 768], 'full_page' => true, 'clip' => true, 'wait_for_ready_attribute' => false, 'measure' => true,
            'viewport_height' => 900, 'max_height' => 16384, 'max_bytes' => 15 * 1024 * 1024, 'timeout_ms' => 30000,
            'require_images' => false, 'require_fonts' => [],
        ]);
    });
});

describe('capturing', function () {
    it('refuses an undeclared URL before any request is made', function () {
        $http = new Factory;
        $http->fake();
        expect(refusedCode(fn () => pageCapture($http)->capture('http://169.254.169.254/latest/meta-data', new CaptureRequest([375]))))->toBe('capture_origin_not_declared');
        $http->assertNothingSent();
    });

    it('returns one Prism image per width, checked against its own header', function () {
        $http = new Factory;
        $http->fake(['*' => $http->response(['url' => 'https://flabs.gen/', 'captures' => [captureEntry(375, 600, 1200), captureEntry(1280, 1280, 900)]])]);
        $pages = pageCapture($http)->capture('https://flabs.gen/sites/render/7', new CaptureRequest([375, 1280]));

        expect($pages)->toHaveCount(2)
            ->and($pages[0]->image)->toBeInstanceOf(Image::class)
            ->and($pages[0]->image->mimeType())->toBe('image/png')
            ->and($pages[0]->width)->toBe(375)
            ->and($pages[0]->capturedWidth)->toBe(600)
            ->and($pages[0]->capturedHeight)->toBe(1200)
            ->and($pages[0]->facts?->horizontalOverflow)->toBeTrue()
            ->and($pages[1]->facts?->smallTargets[0]['tag'])->toBe('button');
        $http->assertSent(fn (Request $request) => $request->url() === 'http://127.0.0.1:4319/v1/capture'
            && $request['url'] === 'https://flabs.gen/sites/render/7' && $request['widths'] === [375, 1280]
            && $request->hasHeader('Authorization', 'Bearer '.str_repeat('t', 40)));
    });

    it('passes the sidecar\'s refusal code through, and only a code', function () {
        $http = new Factory;
        $http->fake(['*' => $http->response(['error' => 'capture_too_large'], 422)]);
        expect(refusedCode(fn () => pageCapture($http)->capture('https://flabs.gen/', new CaptureRequest([375]))))->toBe('capture_too_large');
        $http = new Factory;
        $http->fake(['*' => $http->response(['error' => 'Some <b>sentence</b> with a path C:\\x'], 422)]);
        expect(refusedCode(fn () => pageCapture($http)->capture('https://flabs.gen/', new CaptureRequest([375]))))->toBe('sidecar_failed');
    });

    it('trusts nothing the sidecar returns', function (array $captures) {
        $http = new Factory;
        $http->fake(['*' => $http->response(['captures' => $captures])]);
        expect(refusedCode(fn () => pageCapture($http)->capture('https://flabs.gen/', new CaptureRequest([375], maxHeight: 2000))))->toBe('malformed_sidecar_response');
    })->with([
        'wrong count' => [[]],
        'wrong width order' => [[captureEntry(768, 768, 900)]],
        'image narrower than asked' => [[captureEntry(375, 300, 900)]],
        'reported size is not the image size' => [[captureEntry(375, 375, 900, ['captured_height' => 800])]],
        'taller than the bound' => [[captureEntry(375, 375, 2500)]],
        'clipped though clipping was not allowed' => [[captureEntry(375, 375, 900, ['clipped' => true, 'page_height' => 4000])]],
        'not a PNG' => [[captureEntry(375, 375, 900, ['png' => base64_encode('GIF89a not a png at all, padding padding')])]],
        'not base64' => [[captureEntry(375, 375, 900, ['png' => '***'])]],
        'a label longer than 80' => [[captureEntry(375, 375, 900, ['facts' => ['scroll_width' => 375, 'horizontal_overflow' => false, 'small_targets_total' => 1, 'small_targets' => [['tag' => 'a', 'role' => null, 'text' => str_repeat('x', 81), 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]]]])]],
        'more than 50 targets' => [[captureEntry(375, 375, 900, ['facts' => ['scroll_width' => 375, 'horizontal_overflow' => false, 'small_targets_total' => 51, 'small_targets' => array_fill(0, 51, ['tag' => 'a', 'role' => null, 'text' => '', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1])]])]],
    ]);

    it('refuses an image over the byte bound even when the sidecar let it through', function () {
        $http = new Factory;
        $http->fake(['*' => $http->response(['captures' => [captureEntry(375, 375, 900)]])]);
        expect(refusedCode(fn () => pageCapture($http)->capture('https://flabs.gen/', new CaptureRequest([375], maxBytes: 1024))))->toBe('capture_too_large');
    });
});

describe('require images', function () {
    it('asks the sidecar for it, and is off by default', function () {
        expect((new CaptureRequest([375]))->toArray()['require_images'])->toBeFalse()
            ->and((new CaptureRequest([375], requireImages: true))->toArray()['require_images'])->toBeTrue();
    });

    it('refuses with capture_images_failed and names the failed images in context()', function () {
        $http = new Factory;
        $http->fake(['*' => $http->response([
            'error' => 'capture_images_failed',
            'failed_images' => [['src' => 'https://flabs.gen/missing.png', 'natural_width' => 0, 'natural_height' => 0, 'complete' => true, 'decoded' => false]],
            'failed_images_total' => 1,
        ], 422)]);

        try {
            pageCapture($http)->capture('https://flabs.gen/', new CaptureRequest([375], requireImages: true));
            $this->fail('a page with a broken image was captured');
        } catch (BrowserRefused $refused) {
            expect($refused->code())->toBe('capture_images_failed')
                ->and($refused->context())->toBe([
                    'failed_images' => [['src' => 'https://flabs.gen/missing.png', 'natural_width' => 0, 'natural_height' => 0, 'complete' => true, 'decoded' => false]],
                    'failed_images_total' => 1,
                ]);
        }
    });

    it('drops malformed failure detail but keeps the refusal', function () {
        $http = new Factory;
        $http->fake(['*' => $http->response(['error' => 'capture_images_failed', 'failed_images' => [['src' => 42]], 'failed_images_total' => 1], 422)]);

        try {
            pageCapture($http)->capture('https://flabs.gen/', new CaptureRequest([375], requireImages: true));
            $this->fail('captured');
        } catch (BrowserRefused $refused) {
            expect($refused->code())->toBe('capture_images_failed')->and($refused->context())->toBe([]);
        }
    });

    it('reports each image the sidecar measured, and refuses facts that do not check out', function () {
        $images = [['src' => 'https://flabs.gen/hero.png?2x', 'natural_width' => 1600, 'natural_height' => 900]];
        $http = new Factory;
        $http->fake(['*' => $http->response(['captures' => [captureEntry(375, 375, 900, ['facts' => [
            'scroll_width' => 375, 'horizontal_overflow' => false, 'small_targets' => [], 'small_targets_total' => 0,
            'images' => $images, 'images_total' => 1,
        ]])]])]);
        $facts = pageCapture($http)->capture('https://flabs.gen/', new CaptureRequest([375]))[0]->facts;

        expect($facts?->images)->toBe($images)->and($facts?->imagesTotal)->toBe(1);

        $bad = new Factory;
        $bad->fake(['*' => $bad->response(['captures' => [captureEntry(375, 375, 900, ['facts' => [
            'scroll_width' => 375, 'horizontal_overflow' => false, 'small_targets' => [], 'small_targets_total' => 0,
            'images' => [['src' => str_repeat('a', 3000), 'natural_width' => 1, 'natural_height' => 1]], 'images_total' => 1,
        ]])]])]);
        expect(refusedCode(fn () => pageCapture($bad)->capture('https://flabs.gen/', new CaptureRequest([375]))))->toBe('malformed_sidecar_response');
    });

    it('reads facts from a sidecar that reports no images as none', function () {
        $http = new Factory;
        $http->fake(['*' => $http->response(['captures' => [captureEntry(375, 375, 900)]])]);
        $facts = pageCapture($http)->capture('https://flabs.gen/', new CaptureRequest([375]))[0]->facts;

        expect($facts?->images)->toBe([])->and($facts?->imagesTotal)->toBe(0);
    });
});
