<?php

namespace App\Modules\Identity\Http\Controllers\Parent;

use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Identity\Actions\CreateChild;
use App\Modules\Identity\Enums\Avatar;
use App\Modules\Identity\Enums\ChildStatus;
use App\Modules\Identity\Http\Resources\ParentChildResource;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Support\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ChildController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $children = ChildProfile::query()
            ->with(['family', 'member.user'])
            ->where('family_id', $this->context->familyId())
            ->orderBy('id')
            ->get();

        return ParentChildResource::collection($children);
    }

    public function store(Request $request, CreateChild $createChild): JsonResponse
    {
        $data = $request->validate([
            'nickname' => ['required', 'string', 'min:1', 'max:32'],
            'avatar' => ['required', Rule::enum(Avatar::class)],
            'birth_date' => self::birthDateRules(),
            'grade' => ['required', 'integer', 'between:1,6'],
            'theme_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'pin' => ['nullable', 'string', 'regex:/^\d{4,6}$/'],
        ]);

        $child = $createChild->handle($this->context->familyId(), $data);

        return (new ParentChildResource($child->load(['family', 'member.user'])))->response()->setStatusCode(201);
    }

    public function show(int $id): ParentChildResource
    {
        return new ParentChildResource($this->find($id));
    }

    public function update(Request $request, int $id): ParentChildResource
    {
        $child = $this->find($id);
        $data = $request->validate([
            'nickname' => ['sometimes', 'string', 'min:1', 'max:32'],
            'avatar' => ['sometimes', Rule::enum(Avatar::class)],
            'grade' => ['sometimes', 'integer', 'between:1,6'],
            'theme_color' => ['sometimes', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'status' => ['sometimes', Rule::enum(ChildStatus::class)],
            'preferences' => ['sometimes', 'array:animations,sounds'],
            'preferences.animations' => ['sometimes', 'boolean'],
            'preferences.sounds' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($child, $data) {
            $before = $child->only(['nickname', 'avatar', 'grade', 'theme_color']) + ['status' => $child->status->value];

            // Only mass-assign fillable columns; status/preferences are handled explicitly
            // (strict mode throws MassAssignmentException if status is passed to fill()).
            $child->fill(collect($data)->only(['nickname', 'avatar', 'grade', 'theme_color'])->all());
            if (isset($data['status'])) {
                $child->status = ChildStatus::from($data['status']);
            }
            if (isset($data['preferences'])) {
                $child->preferences = array_merge($child->resolvedPreferences(), $data['preferences']);
            }
            $child->save();

            if (isset($data['nickname'])) {
                $child->member->forceFill(['display_name' => $data['nickname']])->save();
            }

            $this->audit->log('child.updated', $child->family_id, $child, ['before' => $before, 'after' => $data]);
        });

        return new ParentChildResource($child->refresh()->load(['family', 'member.user']));
    }

    /** Birth date drives content age checks, so changing it requires sudo. */
    public function updateBirthDate(Request $request, int $id): ParentChildResource
    {
        $child = $this->find($id);
        $data = $request->validate(['birth_date' => self::birthDateRules()]);

        $child->birth_date = $data['birth_date'];
        $child->save();
        $this->audit->log('child.birth_date_changed', $child->family_id, $child, ['birth_date' => true]);

        return new ParentChildResource($child->refresh()->load(['family', 'member.user']));
    }

    public function updatePin(Request $request, int $id): ParentChildResource
    {
        $child = $this->find($id);
        $data = $request->validate(['pin' => ['present', 'nullable', 'string', 'regex:/^\d{4,6}$/']]);

        $user = $child->member->user;
        $user->pin_hash = $data['pin'] === null ? null : Hash::make($data['pin']);
        $user->save();
        $this->audit->log('child.pin_changed', $child->family_id, $child, ['pin_enabled' => $data['pin'] !== null]);

        return new ParentChildResource($child->refresh()->load(['family', 'member.user']));
    }

    private function find(int $id): ChildProfile
    {
        return $this->context->findChild($id)->load(['family', 'member.user']);
    }

    /**
     * @return list<string>
     */
    private static function birthDateRules(): array
    {
        return ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after:'.now()->subYears(19)->toDateString()];
    }
}
