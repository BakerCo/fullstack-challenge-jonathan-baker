<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Weather\WeatherProvider;
use App\Http\Resources\UserWeatherResource;
use App\Http\Resources\UserWeatherResourceCollection;
use App\Services\Weather\Data\DTO\WeatherData;

class UsersWeatherController extends Controller
{
    protected WeatherProvider $weather;

    public function __construct(WeatherProvider $weather)
    {
        $this->weather = $weather;
    }

    /**
     * List users with basic weather data
     */
    public function index()
    {
        $userWeather = User::query()->select(['id', 'name', 'email', 'latitude', 'longitude'])
            ->get()
            ->map(function (User $user) {
                return $this->mapData($user, $this->weather->current($user->latitude, $user->longitude));
            });

        return new UserWeatherResourceCollection($userWeather);
    }

    /**
     * Detailed weather for a specific user id
     */
    public function show(int $id)
    {
        $user = User::query()->select(['id', 'name', 'email', 'latitude', 'longitude'])->findOrFail($id);
        return new UserWeatherResource(
            $this->mapData($user, $this->weather->current($user->latitude, $user->longitude))
        );
    }

    protected function mapData(User $user, WeatherData $weather): array
    {
        return [
            'user' => $user,
            'weather' => $weather->hasError() ? null : $weather,
            'error' => $weather->hasError() ? $weather->getError() : null,
        ];
    }
}
