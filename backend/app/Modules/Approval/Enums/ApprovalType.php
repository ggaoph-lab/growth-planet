<?php

namespace App\Modules\Approval\Enums;

enum ApprovalType: string
{
    case TaskCompletion = 'task_completion';
    case RewardGrant = 'reward_grant';
    case Redemption = 'redemption';
    case EnglishLog = 'english_log';
}
