<?php

namespace App\Events;

use App\Services\Weather\Data\DTO\WeatherData;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WeatherUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public float $lat,
        public float $lon,
        public ?WeatherData $weather = null,
        public ?array $error = null,
    ) {}

    public function broadcastOn(): Channel
    {
        // Public channel for simplicity; switch to private if needed
        return new Channel('weather');
    }

    public function broadcastAs(): string
    {
        return 'WeatherUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'lat' => $this->lat,
            'lon' => $this->lon,
            'weather' => $this->weather ? $this->weather->jsonSerialize() : null,
            'error' => $this->error,
        ];
    }
}
