<?php

namespace App\Services\Weather;

use App\Jobs\RefreshWeatherCache;
use App\Services\Weather\Data\DTO\WeatherData;
use App\Services\Weather\Data\Transformer\WeatherDataTransformer;
use App\Services\Weather\Support\HandlesWeatherErrors;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

class OpenWeatherService implements WeatherProvider
{
    use HandlesWeatherErrors;

    protected ClientInterface $http;
    protected CacheRepository $cache;
    protected ConfigRepository $config;
    protected WeatherDataTransformer $transformer;
    protected ?string $apiKey;

    public function __construct(
        ClientInterface $http,
        CacheRepository $cache,
        ConfigRepository $config,
        WeatherDataTransformer $transformer,
        ?string $apiKey = null
    ) {
        $this->http = $http;
        $this->cache = $cache;
        $this->config = $config;
        $this->transformer = $transformer;
        $this->apiKey = $apiKey;
    }

    public function id(): string
    {
        return 'openweather';
    }

    /**
     * Serve cached data immediately; on miss, queue async refresh and return a placeholder.
     */
    public function current(float $lat, float $lon): WeatherData
    {
        $cacheKey = $this->cacheKey($lat, $lon);

        $cached = $this->cache->get($cacheKey);
        if ($cached instanceof WeatherData) {
            return $cached;
        }

        RefreshWeatherCache::dispatch($lat, $lon)->onQueue('default');
        /* IDEALLY DITCH THIS MESSAGE FOR A FRONTEND LOADING PLACEHOLDER INSTEAD */
        return WeatherData::empty($this->id(), 'Fetching weather (async)…', 102);
    }

    /**
     * Perform the actual API request and populate cache. Used by the queue job.
     */
    public function refreshNow(float $lat, float $lon): ?WeatherData
    {
        $cacheKey = $this->cacheKey($lat, $lon);
        $ttl = (int) ($this->config->get('weather.ttl') ?? 3600);

        try {
            $resp = $this->http->request('GET', 'https://api.openweathermap.org/data/2.5/weather', [
                'query' => [
                    'lat' => $lat,
                    'lon' => $lon,
                    'appid' => $this->apiKey,
                    'units' => 'metric',
                ],
                'timeout' => 1.5,
                'http_errors' => false, // handle non-2xx manually
            ]);

            $status = $resp->getStatusCode();
            if ($status >= 400) {
                $message = $this->parseErrorMessage($resp, 'OpenWeather error');
                Log::warning('OpenWeather HTTP error', [
                    'provider' => $this->id(),
                    'lat' => $lat,
                    'lon' => $lon,
                    'status' => $status,
                    'message' => $message,
                ]);

                // brief error cache to avoid hammering
                $errorData = WeatherData::empty($this->id(), $message, $status);
                $this->cache->put($cacheKey, $errorData, now()->addSeconds(60));
                event(new \App\Events\WeatherUpdated($lat, $lon, null, $errorData->getError()));
                return null;
            }

            $data = json_decode((string) $resp->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $data['source'] = $this->id();

            $transformed = $this->transformer->transform($data);
            $this->cache->put($cacheKey, $transformed, $ttl);

            // Broadcast async update so frontend can refresh the row
            event(new \App\Events\WeatherUpdated($lat, $lon, $transformed, null));
            return $transformed;
        } catch (RequestException $e) {
            Log::error('OpenWeather transport error', [
                'provider' => $this->id(),
                'lat' => $lat,
                'lon' => $lon,
                'message' => $e->getMessage(),
            ]);

            // better error handling and enumeration would be nice
            $errorData = WeatherData::empty($this->id(), 'Transport error contacting weather service', 0);
            $this->cache->put($cacheKey, $errorData, now()->addSeconds(60));
            event(new \App\Events\WeatherUpdated($lat, $lon, null, $errorData->getError()));
            return null;
        } catch (\Throwable $e) {
            Log::error('OpenWeather unexpected error', [
                'provider' => $this->id(),
                'lat' => $lat,
                'lon' => $lon,
                'message' => $e->getMessage(),
            ]);

            // better error handling and enumeration would be nice
            $errorData = WeatherData::empty($this->id(), 'Unexpected error', 0);
            $this->cache->put($cacheKey, $errorData, now()->addSeconds(60));
            event(new \App\Events\WeatherUpdated($lat, $lon, null, $errorData->getError()));
            return null;
        }
    }

    private function cacheKey(float $lat, float $lon): string
    {
        return sprintf('%s:%f:%f', $this->id(), $lat, $lon);
    }
}
