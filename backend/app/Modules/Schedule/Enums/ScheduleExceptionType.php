<?php

namespace App\Modules\Schedule\Enums;

enum ScheduleExceptionType: string
{
    case NoSchool = 'no_school';
    case MakeupDay = 'makeup_day';
    case Custom = 'custom';
}
