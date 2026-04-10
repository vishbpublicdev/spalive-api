<?php
declare(strict_types=1);

/**
 * Neuro Level 3 access policy: legacy L3, allowlist, and Level 2 presencial on/after cutoff.
 *
 * cutoff: set NEURO_L3_FROM_L2_CUTOFF in .env to override; if unset, defaults to 2026-04-10 00:00:00 (app default timezone when parsed).
 * allowlist_user_ids: sys_users.id values that always get Level 3 neuro access.
 */
return [
    'NeuroLevel3Access' => [
        'cutoff' => env('NEURO_L3_FROM_L2_CUTOFF') ?: '2026-04-10 00:00:00',
        'allowlist_user_ids' => [47746,46700,47750,47619,47611,40482,47699,47591,47816,47592,47754,47240],
    ],
];
