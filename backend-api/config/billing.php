<?php

return [
    // Emergency release gate. Keep off until production prechecks and pilot approval are complete.
    'v2_enabled' => filter_var(env('BILLING_V2_ENABLED', false), FILTER_VALIDATE_BOOL),
    // Even with the global gate open, only explicitly listed landlord user IDs can use V2.
    // Empty means nobody is enabled; never interpret it as allow-all.
    'v2_pilot_user_ids' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('BILLING_V2_PILOT_USER_IDS', '')),
    ), static fn (int $id): bool => $id > 0)),
];
