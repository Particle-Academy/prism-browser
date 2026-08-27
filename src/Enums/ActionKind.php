<?php

declare(strict_types=1);

namespace Prism\Browser\Enums;

enum ActionKind: string
{
    case Click = 'click';
    case Fill = 'fill';
    case Select = 'select';
    case Press = 'press';
    case Scroll = 'scroll';
    case Hover = 'hover';
}
