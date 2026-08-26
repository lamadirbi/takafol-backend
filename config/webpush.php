<?php

return [
    'vapid_public' => (string) env('VAPID_PUBLIC_KEY', ''),
    'vapid_private' => (string) env('VAPID_PRIVATE_KEY', ''),
    'vapid_subject' => (string) env('VAPID_SUBJECT', env('APP_URL', 'mailto:admin@example.com')),
];
