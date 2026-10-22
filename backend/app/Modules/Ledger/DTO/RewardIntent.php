<?php

namespace App\Modules\Ledger\DTO;

use App\Modules\Ledger\Enums\SourceModule;
use Carbon\CarbonImmutable;

readonly class RewardIntent
{
    public function __construct(
        public int $familyId,
        public int $childId,
        public string $policyCode,
        public string $grantKey,
        public SourceModule $sourceModule,
        public ?string $sourceType,
        public ?int $sourceId,
        public CarbonImmutable $occurredAt,
        public ?int $xpAmount = null,
        public ?int $coinAmount = null,
        public ?int $pointAmount = null,
        public ?int $approvalRequestId = null,
        public ?int $approvedBy = null,
        public bool $postImmediately = true,
    ) {}
}
