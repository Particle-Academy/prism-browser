<?php

declare(strict_types=1);

namespace Prism\Browser\Exceptions;

use RuntimeException;

/**
 * Every refusal this package raises carries a stable, machine-readable CODE.
 *
 * The sentence is for a human and is explicitly OUTSIDE the contract: reword it
 * freely, but never change a code without meaning to. Branch on `code()`, never
 * on `getMessage()`. See prism-parity `docs/decisions/0004-error-codes.md`, and
 * `Prism\Harness\Contracts\HasErrorCode` for the same reasoning in a sibling
 * package.
 *
 * **BREAKING, and deliberately loud.** This was `$refused->reason`, a readonly
 * property. It is now `$refused->code()`, and one code changed with it:
 * `private_network_refused` is now `private_address_refused`. Both moves are
 * G-21, and the second is the one that matters — the two ports have always
 * named it that, so a consumer switching on the reference's spelling fell
 * through to its default branch against a port and read an SSRF refusal as an
 * unknown failure.
 *
 * There is no `$reason` alias, on purpose. An alias would keep the SHAPE while
 * the VALUE moved underneath it, so `$e->reason === 'private_network_refused'`
 * would go from true to silently false — on the one branch in this package
 * where a wrong answer means an SSRF refusal is not recognised as one. Removing
 * the property makes that same code raise instead, at the exact line that has
 * to change.
 *
 * A METHOD rather than a property because PHP will not have it otherwise:
 * `Exception::$code` is an untyped int, and a subclass declaring
 * `public readonly string $code` is a fatal error at load time
 * ("Cannot redeclare non-readonly property Exception::$code as readonly"), as
 * is `public string $code` ("Type of ... must not be defined"). Both were run
 * rather than assumed. The three languages therefore spell the ACCESSOR
 * differently — a field in TypeScript, an attribute in Python, this method
 * here — and none of that is observable. THE CODE STRING IS, and it is
 * identical in all three.
 */
final class BrowserRefused extends RuntimeException
{
    public function __construct(private readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    /**
     * A stable, lower_snake_case identifier for this refusal.
     *
     * Identical in every language. Free to be worded differently everywhere.
     */
    public function code(): string
    {
        return $this->errorCode;
    }
}
