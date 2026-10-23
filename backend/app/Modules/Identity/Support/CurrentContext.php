<?php

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\Family;
use App\Modules\Identity\Models\FamilyMember;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/**
 * Resolves the authenticated user's family membership. All family-scoped queries start from here,
 * never from IDs supplied by the client.
 */
class CurrentContext
{
    public function __construct(private readonly Request $request) {}

    public function user(): User
    {
        $user = $this->request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        // Kid/parent APIs always need membership + family; load once per request.
        $user->loadMissing(['member.family', 'member.childProfile']);

        return $user;
    }

    public function member(): FamilyMember
    {
        $member = $this->user()->member;
        if ($member === null) {
            throw new AuthenticationException;
        }

        return $member;
    }

    public function family(): Family
    {
        return $this->member()->family;
    }

    public function familyId(): int
    {
        return $this->member()->family_id;
    }

    /** The logged-in child's profile. Only valid on /kid routes. */
    public function child(): ChildProfile
    {
        $child = $this->member()->childProfile;
        if ($child === null) {
            throw new AuthenticationException;
        }

        return $child;
    }

    /** A child of the current family by ID, or 404. */
    public function findChild(int|string $id): ChildProfile
    {
        return ChildProfile::query()
            ->where('family_id', $this->familyId())
            ->findOrFail($id);
    }
}
