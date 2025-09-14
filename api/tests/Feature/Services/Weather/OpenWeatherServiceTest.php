<?php

namespace Tests\Feature\Services\Weather;

use App\Events\WeatherUpdated;
use App\Jobs\RefreshWeatherCache;
use App\Services\Weather\Data\DTO\WeatherData;
use App\Services\Weather\Data\Transformer\WeatherDataTransformer;
use App\Services\Weather\OpenWeatherService;
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

class OpenWeatherServiceTest extends TestCase
{
    private OpenWeatherService $service;
    private MockInterface $httpClient;
    private MockInterface $cache;
    private MockInterface $config;
    private MockInterface $transformer;
    private string $apiKey = 'test-api-key';

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClient = Mockery::mock(ClientInterface::class);
        $this->cache = Mockery::mock(CacheRepository::class);
        $this->config = Mockery::mock(ConfigRepository::class);
        $this->transformer = Mockery::mock(WeatherDataTransformer::class);

        $this->service = new OpenWeatherService(
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
        $this->assertEquals('openweather', $this->service->id());
    }

    public function test_current_returns_cached_weather_when_available(): void
    {
        $lat = 40.7128;
        $lon = -74.0060;
        $expectedCacheKey = 'openweather:40.712800:-74.006000';

        $cachedWeatherData = new WeatherData(
            tempC: 20.0,
            tempF: 68.0,
            condition: 'Sunny',
            iconUrl: 'https://example.com/sunny.png',
            windKph: 10.0,
            humidity: 60,
            feelsLikeC: 21.0,
            feelsLikeF: 69.8,
            source: 'openweather',
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

        $lat = 40.7128;
        $lon = -74.0060;
        $expectedCacheKey = 'openweather:40.712800:-74.006000';

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
        $this->assertEquals('openweather', $result->source);
        $this->assertEquals('Fetching weather (async)…', $result->getError()['message']);
        $this->assertEquals(102, $result->getError()['code']);
    }

    public function test_refresh_now_successful_api_call(): void
    {
        Event::fake();

        $lat = 40.7128;
        $lon = -74.0060;
        $ttl = 3600;
        $expectedCacheKey = 'openweather:40.712800:-74.006000';

        $apiResponse = [
            'weather' => [['main' => 'Clear', 'description' => 'clear sky']],
            'main' => [
                'temp' => 20.0,
                'feels_like' => 21.0,
                'humidity' => 60
            ],
            'wind' => ['speed' => 10.0]
        ];

        $transformedWeatherData = new WeatherData(
            tempC: 20.0,
            tempF: 68.0,
            condition: 'Clear',
            iconUrl: null,
            windKph: 10.0,
            humidity: 60,
            feelsLikeC: 21.0,
            feelsLikeF: 69.8,
            source: 'openweather',
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
            ->with('GET', 'https://api.openweathermap.org/data/2.5/weather', [
                'query' => [
                    'lat' => $lat,
                    'lon' => $lon,
                    'appid' => $this->apiKey,
                    'units' => 'metric',
                ],
                'timeout' => 1.5,
                'http_errors' => false,
            ])
            ->andReturn($response);

        $expectedApiResponseWithSource = $apiResponse;
        $expectedApiResponseWithSource['source'] = 'openweather';

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
        $lat = 40.7128;
        $lon = -74.0060;
        $ttl = 3600;
        $expectedCacheKey = 'openweather:40.712800:-74.006000';

        $this->config
            ->shouldReceive('get')
            ->once()
            ->with('weather.ttl')
            ->andReturn($ttl);

        $errorResponse = new Response(401, [], json_encode(['message' => 'Invalid API key']));

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
        $lat = 40.7128;
        $lon = -74.0060;
        $ttl = 3600;
        $expectedCacheKey = 'openweather:40.712800:-74.006000';

        $this->config
            ->shouldReceive('get')
            ->once()
            ->with('weather.ttl')
            ->andReturn($ttl);

        $exception = new RequestException('Connection timeout', Mockery::mock('Psr\Http\Message\RequestInterface'));

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
        $lat = 40.7128;
        $lon = -74.0060;
        $ttl = 3600;
        $expectedCacheKey = 'openweather:40.712800:-74.006000';

        $this->config
            ->shouldReceive('get')
            ->once()
            ->with('weather.ttl')
            ->andReturn($ttl);

        $response = new Response(200, [], 'invalid json');

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
