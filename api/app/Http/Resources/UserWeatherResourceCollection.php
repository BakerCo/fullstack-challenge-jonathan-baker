<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class UserWeatherResourceCollection extends ResourceCollection
{
    /**
     * Automatically wrap each item with UserWeatherResource
     */
    public $collects = UserWeatherResource::class;
}
