<?php

declare(strict_types=1);

namespace Prism\Browser\Exceptions;

use RuntimeException;

final class BrowserRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
