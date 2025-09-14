<?php

namespace App\Console\Commands;

use App\Jobs\RefreshWeatherCache;
use App\Models\User;
use Illuminate\Console\Command;

class WarmWeatherCache extends Command
{
    protected $signature = 'weather:warm-cache {--chunk=100 : Number of users per chunk}';
    protected $description = 'Dispatch background jobs to warm weather cache for all users';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');

        User::query()
            ->select(['latitude', 'longitude'])
            ->orderBy('id')
            ->chunk($chunk, function ($users) {
                foreach ($users as $user) {
                    // use unique lat/lon pairs to avoid duplicate jobs (optional: use a set)
                    RefreshWeatherCache::dispatch($user->latitude, $user->longitude)->onQueue('default');
                }
            });

        $this->info('Weather cache warm-up jobs dispatched.');
        return self::SUCCESS;
    }
}
