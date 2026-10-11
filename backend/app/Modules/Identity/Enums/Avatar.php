<?php

namespace App\Modules\Identity\Enums;

/**
 * Built-in avatars. Children never upload a photo of themselves.
 */
enum Avatar: string
{
    case Astronaut = 'astronaut';
    case Rocket = 'rocket';
    case Planet = 'planet';
    case Star = 'star';
    case Comet = 'comet';
    case Moon = 'moon';
    case Ufo = 'ufo';
    case Robot = 'robot';
    case Cat = 'cat';
    case Dog = 'dog';
    case Rabbit = 'rabbit';
    case Panda = 'panda';
}
