<?php

namespace App\Services\Weather;

use App\Services\Weather\Data\DTO\WeatherData;

interface WeatherProvider
{
    /**
     * Retrieve current weather for lat/lon
     */
    public function current(float $lat, float $lon): WeatherData;

    /**
     * Force refresh of cached data for lat/lon
     * If not implemented, current() will be used to refresh cache
     */
    public function refreshNow(float $lat, float $lon): ?WeatherData;

    /** A short provider id like 'openweather', 'weatherapi', 'nws' */
    public function id(): string;
}
