<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserWeatherResource extends JsonResource
{
    public function toArray($request): array
    {
        // If the underlying resource is already the desired array shape, return it.
        if (is_array($this->resource)) {
            return $this->resource;
        }

        // Fallback shape if a model instance is ever passed directly.
        return [
            'user' => $this->resource,
            'weather' => null,
            'error' => 'No weather data available',
        ];
    }
}
