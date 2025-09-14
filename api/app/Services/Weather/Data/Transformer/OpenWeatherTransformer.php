<?php

namespace App\Services\Weather\Data\Transformer;

use App\Services\Weather\Data\DTO\WeatherData;
use DateTimeImmutable;

class OpenWeatherTransformer implements WeatherDataTransformer
{
    public function transform(array $data): WeatherData
    {
        $tempC = (float) ($data['main']['temp'] ?? 0);
        $feelsC = (float) ($data['main']['feels_like'] ?? $tempC);
        $windMs = (float) ($data['wind']['speed'] ?? 0);
        $windKph = $windMs * 3.6;
        $humidity = (int) ($data['main']['humidity'] ?? 0);
        $condition = (string) ($data['weather'][0]['description'] ?? 'unknown');
        $iconCode = $data['weather'][0]['icon'] ?? null;
        $iconUrl = $iconCode ? "https://openweathermap.org/img/wn/{$iconCode}@2x.png" : null;
        $ts = (int) ($data['dt'] ?? time());

        $source = $data['source'];

        return new WeatherData(
            tempC: $tempC,
            tempF: ($tempC * 9 / 5) + 32,
            condition: $condition,
            iconUrl: $iconUrl,
            windKph: $windKph,
            humidity: $humidity,
            feelsLikeC: $feelsC,
            feelsLikeF: ($feelsC * 9 / 5) + 32,
            source: $source,
            observedAt: (new DateTimeImmutable())->setTimestamp($ts)
        );
    }
}
