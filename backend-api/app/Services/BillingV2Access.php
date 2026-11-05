<?php

namespace App\Services;

use App\Models\User;

class BillingV2Access
{
    public function enabledFor(?User $user): bool
    {
        if (! config('billing.v2_enabled', false) || ! $user) {
            return false;
        }

        $pilotUserIds = config('billing.v2_pilot_user_ids', []);

        return is_array($pilotUserIds) && in_array((int) $user->id, array_map('intval', $pilotUserIds), true);
    }
}
