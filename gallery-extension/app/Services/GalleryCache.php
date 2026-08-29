<?php

namespace App\GalleryExtension\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

class GalleryCache
{
	private const PREFIX = 'gallery:v3:';

	public function remember(string $key, int $seconds, Closure $resolver): mixed
	{
		return Cache::remember(self::PREFIX . $key, now()->addSeconds($seconds), $resolver);
	}

	public function forgetAlbum(string $albumId): void
	{
		Cache::forget(self::PREFIX . 'album:' . $albumId . ':first');
	}

	public function forgetActivities(): void
	{
		for ($page = 1; $page <= 3; $page++) {
			Cache::forget(self::PREFIX . 'activities:' . $page);
		}
	}
}
