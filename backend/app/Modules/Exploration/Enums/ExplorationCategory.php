<?php

namespace App\Modules\Exploration\Enums;

enum ExplorationCategory: string
{
    case Game = 'game';
    case Activity = 'activity';
    case Sport = 'sport';
    case Story = 'story';
    case Movie = 'movie';
    case Experience = 'experience';
}
