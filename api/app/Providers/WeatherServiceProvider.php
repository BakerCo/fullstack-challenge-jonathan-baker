<?php

namespace App\Providers;

use App\Services\Weather\Data\Transformer\OpenWeatherTransformer;
use App\Services\Weather\Data\Transformer\WeatherApiTransformer;
use App\Services\Weather\OpenWeatherService;
use App\Services\Weather\WeatherApiService;
use App\Services\Weather\WeatherProvider;
use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;

class WeatherServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WeatherProvider::class, function ($app) {
            $driver = config('weather.driver');
            $client = new Client();
            $cacheRepository = $app->make('cache.store');
            $configRepository = $app->make('config');

            return match ($driver) {
                'openweather' => new OpenWeatherService($client, $cacheRepository, $configRepository, new OpenWeatherTransformer, $configRepository->get('weather.providers.openweather.key')),
                'weatherapi' => new WeatherApiService($client, $cacheRepository, $configRepository, new WeatherApiTransformer, $configRepository->get('weather.providers.weatherapi.key')),
                default => new OpenWeatherService($client, $cacheRepository, $configRepository, new OpenWeatherTransformer, null),
            };
        });
    }
}
