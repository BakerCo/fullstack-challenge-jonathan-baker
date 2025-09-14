<?php

namespace Tests\Feature\Services\Weather;

use App\Events\WeatherUpdated;
use App\Jobs\RefreshWeatherCache;
use App\Services\Weather\Data\DTO\WeatherData;
use App\Services\Weather\Data\Transformer\WeatherDataTransformer;
use App\Services\Weather\WeatherApiService;
use DateTimeImmutable;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class WeatherApiServiceTest extends TestCase
{
    private WeatherApiService $service;
    private MockInterface $httpClient;
    private MockInterface $cache;
    private MockInterface $config;
    private MockInterface $transformer;
    private string $apiKey = 'test-weather-api-key';

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClient = Mockery::mock(ClientInterface::class);
        $this->cache = Mockery::mock(CacheRepository::class);
        $this->config = Mockery::mock(ConfigRepository::class);
        $this->transformer = Mockery::mock(WeatherDataTransformer::class);

        $this->service = new WeatherApiService(
            $this->httpClient,
            $this->cache,
            $this->config,
            $this->transformer,
            $this->apiKey
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_id_returns_correct_provider_id(): void
    {
        $this->assertEquals('weatherapi', $this->service->id());
    }

    public function test_current_returns_cached_weather_when_available(): void
    {
        $lat = 51.5074;
        $lon = -0.1278;
        $expectedCacheKey = 'weatherapi:51.507400:-0.127800';

        $cachedWeatherData = new WeatherData(
            tempC: 15.0,
            tempF: 59.0,
            condition: 'Partly cloudy',
            iconUrl: 'https://example.com/cloudy.png',
            windKph: 12.0,
            humidity: 75,
            feelsLikeC: 13.0,
            feelsLikeF: 55.4,
            source: 'weatherapi',
            observedAt: new DateTimeImmutable()
        );

        $this->cache
            ->shouldReceive('get')
            ->once()
            ->with($expectedCacheKey)
            ->andReturn($cachedWeatherData);

        $result = $this->service->current($lat, $lon);

        $this->assertSame($cachedWeatherData, $result);
    }

    public function test_current_dispatches_job_and_returns_placeholder_when_cache_miss(): void
    {
        Queue::fake();

        $lat = 51.5074;
        $lon = -0.1278;
        $expectedCacheKey = 'weatherapi:51.507400:-0.127800';

        $this->cache
            ->shouldReceive('get')
            ->once()
            ->with($expectedCacheKey)
            ->andReturn(null);

        $result = $this->service->current($lat, $lon);

        Queue::assertPushed(RefreshWeatherCache::class, function ($job) use ($lat, $lon) {
            return $job->lat === $lat && $job->lon === $lon;
        });

        $this->assertInstanceOf(WeatherData::class, $result);
        $this->assertTrue($result->hasError());
        $this->assertEquals('weatherapi', $result->source);
        $this->assertEquals('Fetching weather (async)…', $result->getError()['message']);
        $this->assertEquals(102, $result->getError()['code']);
    }

    public function test_refresh_now_successful_api_call(): void
    {
        Event::fake();

        $lat = 51.5074;
        $lon = -0.1278;
        $ttl = 3600;
        $expectedCacheKey = 'weatherapi:51.507400:-0.127800';

        $apiResponse = [
            'current' => [
                'temp_c' => 15.0,
                'temp_f' => 59.0,
                'condition' => ['text' => 'Partly cloudy', 'icon' => '//cdn.weatherapi.com/weather/64x64/day/116.png'],
                'wind_kph' => 12.0,
                'humidity' => 75,
                'feelslike_c' => 13.0,
                'feelslike_f' => 55.4
            ]
        ];

        $transformedWeatherData = new WeatherData(
            tempC: 15.0,
            tempF: 59.0,
            condition: 'Partly cloudy',
            iconUrl: 'https://cdn.weatherapi.com/weather/64x64/day/116.png',
            windKph: 12.0,
            humidity: 75,
            feelsLikeC: 13.0,
            feelsLikeF: 55.4,
            source: 'weatherapi',
            observedAt: new DateTimeImmutable()
        );

        $this->config
            ->shouldReceive('get')
            ->once()
            ->with('weather.ttl')
            ->andReturn($ttl);

        $response = new Response(200, [], json_encode($apiResponse));

        $this->httpClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', 'https://api.weatherapi.com/v1/current.json', [
                'query' => [
                    'key' => $this->apiKey,
                    'q' => sprintf('%f,%f', $lat, $lon),
                    'aqi' => 'no',
                ],
                'timeout' => 1.5,
                'http_errors' => false,
            ])
            ->andReturn($response);

        $expectedApiResponseWithSource = $apiResponse;
        $expectedApiResponseWithSource['source'] = 'weatherapi';

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->with($expectedApiResponseWithSource)
            ->andReturn($transformedWeatherData);

        $this->cache
            ->shouldReceive('put')
            ->once()
            ->with($expectedCacheKey, $transformedWeatherData, $ttl);

        $result = $this->service->refreshNow($lat, $lon);

        $this->assertSame($transformedWeatherData, $result);
        Event::assertDispatched(WeatherUpdated::class, function ($event) use ($lat, $lon, $transformedWeatherData) {
            return $event->lat === $lat
                && $event->lon === $lon
                && $event->weather === $transformedWeatherData
                && $event->error === null;
        });
    }

    public function test_refresh_now_handles_http_error_status(): void
    {
        $lat = 51.5074;
        $lon = -0.1278;
        $ttl = 3600;
        $expectedCacheKey = 'weatherapi:51.507400:-0.127800';

        $this->config
            ->shouldReceive('get')
            ->once()
            ->with('weather.ttl')
            ->andReturn($ttl);

        $errorResponse = new Response(400, [], json_encode([
            'error' => ['message' => 'Invalid coordinates']
        ]));

        $this->httpClient
            ->shouldReceive('request')
            ->once()
            ->andReturn($errorResponse);

        $this->cache
            ->shouldReceive('put')
            ->once()
            ->with($expectedCacheKey, Mockery::type(WeatherData::class), Mockery::any());

        $result = $this->service->refreshNow($lat, $lon);

        $this->assertNull($result);
    }

    public function test_refresh_now_handles_request_exception(): void
    {
        $lat = 51.5074;
        $lon = -0.1278;
        $ttl = 3600;
        $expectedCacheKey = 'weatherapi:51.507400:-0.127800';

        $this->config
            ->shouldReceive('get')
            ->once()
            ->with('weather.ttl')
            ->andReturn($ttl);

        $exception = new RequestException('Network error', Mockery::mock('Psr\Http\Message\RequestInterface'));

        $this->httpClient
            ->shouldReceive('request')
            ->once()
            ->andThrow($exception);

        $this->cache
            ->shouldReceive('put')
            ->once()
            ->with($expectedCacheKey, Mockery::type(WeatherData::class), Mockery::any());

        $result = $this->service->refreshNow($lat, $lon);

        $this->assertNull($result);
    }

    public function test_refresh_now_handles_json_decode_exception(): void
    {
        $lat = 51.5074;
        $lon = -0.1278;
        $ttl = 3600;
        $expectedCacheKey = 'weatherapi:51.507400:-0.127800';

        $this->config
            ->shouldReceive('get')
            ->once()
            ->with('weather.ttl')
            ->andReturn($ttl);

        $response = new Response(200, [], 'malformed json response');

        $this->httpClient
            ->shouldReceive('request')
            ->once()
            ->andReturn($response);

        $this->cache
            ->shouldReceive('put')
            ->once()
            ->with($expectedCacheKey, Mockery::type(WeatherData::class), Mockery::any());

        $result = $this->service->refreshNow($lat, $lon);

        $this->assertNull($result);
    }
}
