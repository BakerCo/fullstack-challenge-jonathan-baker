<?php

namespace App\Services\Weather\Data\Transformer;

use App\Services\Weather\Data\DTO\WeatherData;

interface WeatherDataTransformer
{
    public function transform(array $response): WeatherData;
}
