<?php

return [
    // Emergency kill switch.
    // Per-boarding-house authorization remains enforced separately.
    'v2_enabled' => filter_var(env('BILLING_V2_ENABLED', false), FILTER_VALIDATE_BOOL),
];
