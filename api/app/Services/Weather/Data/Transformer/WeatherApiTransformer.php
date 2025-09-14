<?php

namespace App\Services\Weather\Data\Transformer;

use App\Services\Weather\Data\DTO\WeatherData;
use DateTimeImmutable;

class WeatherApiTransformer implements WeatherDataTransformer
{
    public function transform(array $data): WeatherData
    {
        $c = $data['current'] ?? [];
        $tempC = (float) ($c['temp_c'] ?? 0);
        $feelsC = (float) ($c['feelslike_c'] ?? $tempC);
        $windKph = (float) ($c['wind_kph'] ?? 0);
        $humidity = (int) ($c['humidity'] ?? 0);
        $condition = (string) ($c['condition']['text'] ?? 'unknown');
        $iconUrl = isset($c['condition']['icon']) ? ('https:' . $c['condition']['icon']) : null;
        $ts = isset($c['last_updated_epoch']) ? (int) $c['last_updated_epoch'] : time();

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
