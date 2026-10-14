<?php

namespace App\Services;

use App\Models\BoardingHouse;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class BoardingHouseAccessService
{
    public function houses(User $user): Builder
    {
        // Roles are scoped to an existing Landlord workspace, not a global user
        // flag. Legal household-head data never comes from these permissions.
        return BoardingHouse::query()->whereHas('landlord')->where(function (Builder $query) use ($user): void {
            $query->whereHas('landlord', fn (Builder $owner) => $owner->where('user_id', $user->id))
                ->orWhereHas('managers', fn (Builder $managers) => $managers->where('users.id', $user->id));
        });
    }

    public function house(User $user, int $id, bool $lock = false): BoardingHouse
    {
        if (! $lock) {
            return $this->houses($user)->findOrFail($id);
        }

        // Assignment writes use the same house lock. Recheck permission after
        // obtaining it so a manager cannot keep writing through a revoked grant.
        $house = BoardingHouse::query()->with('landlord')->lockForUpdate()->findOrFail($id);
        abort_unless($house->landlord !== null, 404);
        if ($this->isOwner($user, $house) || $house->managers()->where('users.id', $user->id)->lockForUpdate()->first(['users.id'])) {
            return $house;
        }
        abort(404);
    }

    public function room(User $user, int $id): Room
    {
        return Room::query()->whereIn('boarding_house_id', $this->houses($user)->select('boarding_houses.id'))->findOrFail($id);
    }

    public function isOwner(User $user, BoardingHouse $house): bool
    {
        return (string) $house->landlord?->user_id === (string) $user->id;
    }

    public function role(User $user, BoardingHouse $house): string
    {
        return $this->isOwner($user, $house) ? 'OWNER' : 'MANAGER';
    }
}
