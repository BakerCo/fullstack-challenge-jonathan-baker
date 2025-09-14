<?php

return [
    // Choose provider via env: openweather, weatherapi, nws
    'driver' => env('WEATHER_DRIVER', 'weatherapi'),

    'ttl' => env('WEATHER_TTL_SECONDS', 3600), // 1 hour cache default

    'providers' => [
        'openweather' => [
            'key' => env('OPENWEATHER_API_KEY'),
        ],
        'weatherapi' => [
            'key' => env('WEATHERAPI_API_KEY'),
        ],
    ],
];
