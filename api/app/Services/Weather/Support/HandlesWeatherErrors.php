<?php

namespace App\Services\Weather\Support;

use App\Services\Weather\Data\DTO\WeatherData;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait HandlesWeatherErrors
{
    /** Build an empty WeatherData with optional error info */
    protected function buildEmpty(string $source, ?string $message, ?int $code): WeatherData
    {
        return WeatherData::empty($source, $message, $code);
    }

    /** Extract a readable error message from an HTTP response */
    protected function parseErrorMessage(?ResponseInterface $response, string $fallback): string
    {
        if (!$response) {
            return $fallback;
        }

        $raw = (string) $response->getBody();
        if ($raw === '') {
            return $fallback;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            // IDEALLY ABSTRACT THIS PER PROVIDER
            // WeatherAPI error style: {"error": {"message": "..."}}
            if (isset($decoded['error']['message'])) {
                return (string) $decoded['error']['message'];
            }
            // OpenWeather and others often use 'message'
            if (isset($decoded['message'])) {
                return (string) $decoded['message'];
            }
        } catch (\Throwable) {
            // ignore
        }

        return $fallback;
    }

    /** Log errors at appropriate level based on status */
    protected function logHttpException(HttpException $e, string $provider, float $lat, float $lon): void
    {
        $status = $e->getStatusCode();
        $context = [
            'provider' => $provider,
            'lat' => $lat,
            'lon' => $lon,
            'status' => $status,
            'message' => $e->getMessage(),
        ];

        if (in_array($status, [400, 401, 403], true)) {
            Log::warning('Weather provider client error', $context);
        } elseif ($status >= 500) {
            Log::error('Weather provider server error', $context);
        } else {
            Log::error('Weather provider request error', $context);
        }
    }
}
