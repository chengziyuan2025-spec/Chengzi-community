<?php

namespace App\GalleryExtension\Services;

use Illuminate\Support\Facades\Crypt;

class ActivityCursor
{
	/** @return array{activity_id:int,position:int,id:int}|null */
	public function decode(?string $cursor): ?array
	{
		if (!$cursor) {
			return null;
		}
		try {
			$value = json_decode(Crypt::decryptString($cursor), true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($value)
				|| !isset($value['activity_id'], $value['position'], $value['id'], $value['expires_at'])
				|| (int) $value['activity_id'] < 1
				|| (int) $value['id'] < 1
				|| (int) $value['expires_at'] < time()) {
				abort(422, 'Invalid or expired cursor.');
			}
			return ['activity_id' => (int) $value['activity_id'], 'position' => (int) $value['position'], 'id' => (int) $value['id']];
		} catch (\Throwable) {
			abort(422, 'Invalid cursor.');
		}
	}

	public function encode(object $image): string
	{
		return Crypt::encryptString(json_encode([
			'activity_id' => (int) $image->activity_id,
			'position' => (int) $image->position,
			'id' => (int) $image->id,
			'expires_at' => time() + 3600,
		], JSON_THROW_ON_ERROR));
	}
}
