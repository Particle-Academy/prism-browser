<?php

declare(strict_types=1);

namespace Prism\Browser\Enums;

enum AttachmentState: string
{
    case Open = 'open';
    case Closed = 'closed';
    case InDoubt = 'in_doubt';
}
