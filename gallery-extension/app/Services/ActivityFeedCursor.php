<?php

namespace App\GalleryExtension\Services;

use Illuminate\Support\Facades\Crypt;

class ActivityFeedCursor
{
	public function decode(?string $cursor, string $type, string $scope): ?int
	{
		if (!$cursor) {
			return null;
		}

		try {
			$value = json_decode(Crypt::decryptString($cursor), true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($value)
				|| ($value['kind'] ?? null) !== 'activity-feed'
				|| ($value['type'] ?? null) !== $type
				|| ($value['scope'] ?? null) !== $scope
				|| (int) ($value['id'] ?? 0) < 1
				|| (int) ($value['expires_at'] ?? 0) < time()) {
				abort(422, 'Invalid activity cursor.');
			}

			return (int) $value['id'];
		} catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
			throw $exception;
		} catch (\Throwable) {
			abort(422, 'Invalid activity cursor.');
		}
	}

	public function encode(object $activity, string $type, string $scope): string
	{
		return Crypt::encryptString(json_encode([
			'kind' => 'activity-feed',
			'type' => $type,
			'scope' => $scope,
			'id' => (int) $activity->id,
			'expires_at' => time() + 3600,
		], JSON_THROW_ON_ERROR));
	}
}
