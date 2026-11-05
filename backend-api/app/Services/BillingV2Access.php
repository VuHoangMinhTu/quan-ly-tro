<?php

namespace App\Services;

use App\Models\User;

class BillingV2Access
{
    public function enabledFor(?User $user): bool
    {
        // Feature gate, not resource authorization.
        // Per-house access is enforced by controllers/services.
        return $user !== null && (bool) config('billing.v2_enabled', false);
    }
}
