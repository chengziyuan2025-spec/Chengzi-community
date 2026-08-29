<?php

namespace App\GalleryExtension\Services;

use Illuminate\Http\Request;
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
			if (!is_array($value) || !isset($value['activity_id'], $value['position'], $value['id'])) {
				return null;
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
		], JSON_THROW_ON_ERROR));
	}
}
