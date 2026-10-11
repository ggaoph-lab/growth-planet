<?php

namespace App\Modules\Task\Enums;

enum TaskKind: string
{
    case Standard = 'standard';
    case Stepped = 'stepped';
    case LongTerm = 'long_term';
}
