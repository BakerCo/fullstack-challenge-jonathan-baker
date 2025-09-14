<?php

namespace App\Jobs;

use App\Services\Weather\WeatherProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshWeatherCache implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Max attempts */
    public $tries = 3;

    /** Backoff in seconds between attempts */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(
        public float $lat,
        public float $lon
    ) {}

    public function handle(Container $container): void
    {
        /** @var WeatherProvider $provider */
        $provider = $container->make(WeatherProvider::class);

        $provider->refreshNow($this->lat, $this->lon);
    }
}
