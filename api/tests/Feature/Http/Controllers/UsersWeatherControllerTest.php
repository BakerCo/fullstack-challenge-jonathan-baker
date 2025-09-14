<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\User;
use App\Services\Weather\Data\DTO\WeatherData;
use App\Services\Weather\WeatherProvider;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Mockery\MockInterface;
use Tests\TestCase;

class UsersWeatherControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    public function test_index_returns_users_with_weather_data(): void
    {
        $users = User::factory(3)->create();

        $mockWeatherData = new WeatherData(
            tempC: 20.5,
            tempF: 68.9,
            condition: 'Sunny',
            iconUrl: 'https://example.com/sunny.png',
            windKph: 10.2,
            humidity: 65,
            feelsLikeC: 22.0,
            feelsLikeF: 71.6,
            source: 'test-provider',
            observedAt: new DateTimeImmutable('2023-10-01 12:00:00')
        );

        $this->mock(WeatherProvider::class, function (MockInterface $mock) use ($mockWeatherData) {
            $mock->shouldReceive('current')
                ->times(3)
                ->andReturn($mockWeatherData);
        });

        $response = $this->getJson('/users');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'latitude',
                        'longitude',
                    ],
                    'weather' => [
                        'tempC',
                        'tempF',
                        'condition',
                        'iconUrl',
                        'windKph',
                        'humidity',
                        'feelsLikeC',
                        'feelsLikeF',
                        'source',
                        'observedAt',
                    ],
                    'error'
                ]
            ]
        ]);

        $responseData = $response->json('data');
        $this->assertCount(3, $responseData);

        foreach ($responseData as $item) {
            $this->assertNull($item['error']);
            $this->assertNotNull($item['weather']);
            $this->assertEquals(20.5, $item['weather']['tempC']);
            $this->assertEquals('Sunny', $item['weather']['condition']);
        }
    }

    public function test_index_handles_weather_service_errors(): void
    {
        User::factory(2)->create();

        $errorWeatherData = WeatherData::empty('test-provider', 'API Error', 500);

        $this->mock(WeatherProvider::class, function (MockInterface $mock) use ($errorWeatherData) {
            $mock->shouldReceive('current')
                ->times(2)
                ->andReturn($errorWeatherData);
        });

        $response = $this->getJson('/users');

        $response->assertStatus(200);
        $responseData = $response->json('data');

        foreach ($responseData as $item) {
            $this->assertNull($item['weather']);
            $this->assertNotNull($item['error']);
            $this->assertEquals(500, $item['error']['code']);
            $this->assertEquals('API Error', $item['error']['message']);
        }
    }

    public function test_show_returns_specific_user_weather(): void
    {
        $user = User::factory()->create();

        $mockWeatherData = new WeatherData(
            tempC: 25.0,
            tempF: 77.0,
            condition: 'Partly Cloudy',
            iconUrl: 'https://example.com/cloudy.png',
            windKph: 15.5,
            humidity: 70,
            feelsLikeC: 27.0,
            feelsLikeF: 80.6,
            source: 'test-provider',
            observedAt: new DateTimeImmutable('2023-10-01 14:00:00')
        );

        $this->mock(WeatherProvider::class, function (MockInterface $mock) use ($mockWeatherData, $user) {
            $mock->shouldReceive('current')
                ->once()
                ->with($user->latitude, $user->longitude)
                ->andReturn($mockWeatherData);
        });

        $response = $this->getJson("/users/{$user->id}/weather");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'user' => [
                    'id',
                    'name',
                    'email',
                    'latitude',
                    'longitude',
                ],
                'weather' => [
                    'tempC',
                    'tempF',
                    'condition',
                    'iconUrl',
                    'windKph',
                    'humidity',
                    'feelsLikeC',
                    'feelsLikeF',
                    'source',
                    'observedAt',
                ],
                'error'
            ]
        ]);

        $data = $response->json('data');
        $this->assertEquals($user->id, $data['user']['id']);
        $this->assertEquals(25.0, $data['weather']['tempC']);
        $this->assertEquals('Partly Cloudy', $data['weather']['condition']);
        $this->assertNull($data['error']);
    }

    public function test_show_returns_404_for_nonexistent_user(): void
    {
        $response = $this->getJson('/users/999/weather');

        $response->assertStatus(404);
    }

    public function test_show_handles_weather_service_error(): void
    {
        $user = User::factory()->create();
        $errorWeatherData = WeatherData::empty('test-provider', 'Service unavailable', 503);

        $this->mock(WeatherProvider::class, function (MockInterface $mock) use ($errorWeatherData, $user) {
            $mock->shouldReceive('current')
                ->once()
                ->with($user->latitude, $user->longitude)
                ->andReturn($errorWeatherData);
        });

        $response = $this->getJson("/users/{$user->id}/weather");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertNull($data['weather']);
        $this->assertNotNull($data['error']);
        $this->assertEquals(503, $data['error']['code']);
        $this->assertEquals('Service unavailable', $data['error']['message']);
    }

    public function test_show_validates_numeric_user_id(): void
    {
        $response = $this->getJson('/users/invalid/weather');
        $response->assertStatus(404); // Laravel routing will return 404 for non-numeric ID due to whereNumber constraint
    }

    public function test_index_returns_empty_collection_when_no_users(): void
    {
        // No users created

        $response = $this->getJson('/users');

        $response->assertStatus(200);
        $response->assertJson(['data' => []]);
    }

    public function test_weather_provider_coordinates_passed_correctly(): void
    {
        $user = User::factory()->create([
            'latitude' => 40.7128,
            'longitude' => -74.0060
        ]);

        $mockWeatherData = new WeatherData(
            tempC: 18.0,
            tempF: 64.4,
            condition: 'Clear',
            iconUrl: null,
            windKph: 5.0,
            humidity: 50,
            feelsLikeC: 18.5,
            feelsLikeF: 65.3,
            source: 'test-provider',
            observedAt: new DateTimeImmutable()
        );

        $this->mock(WeatherProvider::class, function (MockInterface $mock) use ($mockWeatherData) {
            $mock->shouldReceive('current')
                ->once()
                ->with(40.7128, -74.0060)
                ->andReturn($mockWeatherData);
        });

        $response = $this->getJson("/users/{$user->id}/weather");

        $response->assertStatus(200);
    }
}
