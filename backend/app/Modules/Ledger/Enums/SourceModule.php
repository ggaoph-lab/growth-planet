<?php

namespace App\Modules\Ledger\Enums;

enum SourceModule: string
{
    case Task = 'task';
    case Habit = 'habit';
    case Shop = 'shop';
    case Parent = 'parent';
    case Movie = 'movie';
    case English = 'english';
    case Exploration = 'exploration';
    case Encyclopedia = 'encyclopedia';
    case Poetry = 'poetry';
    case Item = 'item';
    case Game = 'game';
    case Ielts = 'ielts';
    case System = 'system';
}
