<?php

return [

    'relying_party' => [
        'name' => env('WEBAUTHN_NAME', config('app.name')),
        'id' => env('WEBAUTHN_ID'),
    ],

    'origins' => env('WEBAUTHN_ORIGINS'),

    'challenge' => [
        'bytes' => 16,
        'timeout' => 60,
        'key' => '_webauthn',
    ],
];
