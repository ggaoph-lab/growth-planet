<?php

namespace App\Modules\Movie\Enums;

enum CollectionSortMode: string
{
    case Story = 'story';
    case Release = 'release';
    case Custom = 'custom';
}
