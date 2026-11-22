<?php

// VATSSA-owned settings. Values come from .env; nothing secret is written here.
return [
    // Bearer token the Discord role sync bot uses to report sync results.
    // Unset: the result endpoint refuses everyone.
    'discord_bot_token' => env('VATSSA_DISCORD_BOT_TOKEN'),

    // Off: VATSIM's answers are stored and previewed (vatssa:vatsim-leavers), `users` is not
    // touched. On: `users` follows VATSIM, and upstream's 05:00 job closes leavers' trainings.
    'vatsim_write_users' => (bool) env('VATSSA_VATSIM_WRITE_USERS', false),
];
