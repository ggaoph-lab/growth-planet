<?php

namespace App\Modules\Core\Audit;

use App\Modules\Core\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogger
{
    /** Keys whose values must never be written to the audit log. */
    private const REDACTED = ['password', 'pin', 'pin_hash', 'token', 'token_hash', 'birth_date', 'remember_token'];

    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $changes
     */
    public function log(
        string $action,
        ?int $familyId,
        ?Model $subject = null,
        array $changes = [],
        ?User $actor = null,
    ): AuditLog {
        $actor ??= $this->request->user();

        return AuditLog::create([
            'family_id' => $familyId,
            'actor_user_id' => $actor?->id,
            'actor_role' => $actor?->member?->role->value,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'changes' => $changes === [] ? null : $this->redact($changes),
            'ip' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 250, ''),
            'request_id' => $this->request->attributes->get('request_id'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, self::REDACTED, true)) {
                $data[$key] = '[已隐藏]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }
}
