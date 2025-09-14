<?php

namespace App\Services\Weather\Data\DTO;

use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;

/**
 * Simple DTO for normalized current weather data
 */
class WeatherData implements JsonSerializable
{
    private bool $hasError = false;
    private ?string $errorMessage = null;
    private ?int $errorCode = null;

    public function __construct(
        public readonly float $tempC,
        public readonly float $tempF,
        public readonly string $condition,
        public readonly ?string $iconUrl,
        public readonly float $windKph,
        public readonly int $humidity,
        public readonly float $feelsLikeC,
        public readonly float $feelsLikeF,
        public readonly string $source, // provider id
        public readonly DateTimeInterface $observedAt,
    ) {}

    public static function empty(string $source, ?string $message = null, ?int $code = null): self
    {
        $inst = new self(
            tempC: 0.0,
            tempF: 32.0,
            condition: 'unknown',
            iconUrl: null,
            windKph: 0.0,
            humidity: 0,
            feelsLikeC: 0.0,
            feelsLikeF: 32.0,
            source: $source,
            observedAt: new DateTimeImmutable(),
        );
        if ($message !== null || $code !== null) {
            $inst->hasError = true;
            $inst->errorMessage = $message;
            $inst->errorCode = $code;
        }
        return $inst;
    }

    public function hasError(): bool
    {
        return $this->hasError;
    }

    public function getError(): ?array
    {
        return $this->hasError ? [
            'code' => $this->errorCode,
            'message' => $this->errorMessage,
        ] : null;
    }

    public function jsonSerialize(): array
    {
        return [
            'tempC' => $this->tempC,
            'tempF' => $this->tempF,
            'condition' => $this->condition,
            'iconUrl' => $this->iconUrl,
            'windKph' => $this->windKph,
            'humidity' => $this->humidity,
            'feelsLikeC' => $this->feelsLikeC,
            'feelsLikeF' => $this->feelsLikeF,
            'source' => $this->source,
            'observedAt' => $this->observedAt->format(DATE_ATOM),
        ];
    }
}
