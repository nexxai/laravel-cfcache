<?php

namespace JTSmith\Cloudflare\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class ScheduledPurgeStore
{
    public function all(): array
    {
        if (! File::exists($this->path())) {
            return [];
        }

        $entries = json_decode(File::get($this->path()), true);

        return is_array($entries) ? $entries : [];
    }

    public function add(Carbon $runAt, string $command, array $parameters): void
    {
        $entries = $this->all();

        $entries[] = [
            'run_at' => $runAt->toIso8601String(),
            'command' => $command,
            'parameters' => $parameters,
        ];

        $this->write($entries);
    }

    public function due(Carbon $now): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (array $entry): bool => Carbon::parse($entry['run_at'])->lte($now)
        ));
    }

    public function remove(array $entryToRemove): void
    {
        $entries = array_values(array_filter(
            $this->all(),
            fn (array $entry): bool => $entry !== $entryToRemove
        ));

        $this->write($entries);
    }

    public function runDue(?Carbon $now = null): void
    {
        foreach ($this->due($now ?? Carbon::now()) as $entry) {
            Artisan::call($entry['command'], $entry['parameters'] ?? []);

            $this->remove($entry);
        }
    }

    public function path(): string
    {
        return config('cfcache.scheduled_purges.file', storage_path('app/laravel-cfcache/scheduled-purges.json'));
    }

    protected function write(array $entries): void
    {
        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
