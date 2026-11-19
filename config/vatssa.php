<?php

// VATSSA-owned settings. Values come from .env; nothing secret is written here.
return [
    // Bearer token the Discord role sync bot uses to report sync results.
    // Unset: the result endpoint refuses everyone.
    'discord_bot_token' => env('VATSSA_DISCORD_BOT_TOKEN'),
];
