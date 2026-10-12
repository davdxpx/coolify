<?php

namespace App\Services\Distribution;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunningImageInspector
{
    public const CACHE_KEY = 'coolify:distribution:running-image';

    public const CONTAINER = 'coolify';

    public function inspect(bool $fresh = false): ?RunningImage
    {
        if (isDev()) {
            return null;
        }

        if (! $fresh) {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached)) {
                return new RunningImage($cached['image_id'], $cached['reference'], $cached['repo_digests'], $cached['inspected_at']);
            }
        }

        $server = Server::find(0);
        if (! $server) {
            return null;
        }

        try {
            $output = instant_remote_process([self::command()], $server, false, timeout: 30);
        } catch (Throwable) {
            return null;
        }

        $image = RunningImage::parse((string) $output);
        if ($image !== null) {
            Cache::put(self::CACHE_KEY, $image->toArray(), 300);
        }

        return $image;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function command(): string
    {
        $container = self::CONTAINER;

        return "sh -c 'docker inspect --format \"{{.Image}}|{{.Config.Image}}\" {$container} && docker image inspect --format \"{{json .RepoDigests}}\" \"\$(docker inspect --format \"{{.Image}}\" {$container})\"'";
    }
}
