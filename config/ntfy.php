<?php

return [
    'enabled' => filter_var(env('NTFY_ENABLED', true), FILTER_VALIDATE_BOOL),
    'base_url' => rtrim((string) env('NTFY_BASE_URL', 'https://ntfy.sh'), '/'),
    'token' => (string) env('NTFY_TOKEN', ''),
    'play_store_url' => (string) env(
        'NTFY_PLAY_STORE_URL',
        'https://play.google.com/store/apps/details?id=io.heckel.ntfy'
    ),
    'app_store_url' => (string) env(
        'NTFY_APP_STORE_URL',
        'https://apps.apple.com/app/ntfy/id1625396347'
    ),
    'frontend_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/'),
];
