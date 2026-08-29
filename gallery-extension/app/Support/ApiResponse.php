<?php

namespace App\GalleryExtension\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ApiResponse
{
	public static function success(Request $request, mixed $data = null, int $status = 200, array $meta = []): JsonResponse
	{
		$response = response()->json([
			'success' => true,
			'data' => $data,
			'meta' => $meta,
			'error' => null,
			'request_id' => self::requestId($request),
		], $status);

		return self::withVersion($response, $data);
	}

	public static function error(Request $request, string $code, string $message, int $status, array $details = []): JsonResponse
	{
		return response()->json([
			'success' => false,
			'data' => null,
			'meta' => [],
			'error' => array_filter([
				'code' => $code,
				'message' => $message,
				'details' => $details ?: null,
			]),
			'request_id' => self::requestId($request),
		], $status);
	}

	public static function requestId(Request $request): string
	{
		$id = (string) $request->attributes->get('gallery_request_id', '');
		if ($id === '') {
			$id = (string) Str::uuid();
			$request->attributes->set('gallery_request_id', $id);
		}

		return $id;
	}

	private static function withVersion(JsonResponse $response, mixed $data): JsonResponse
	{
		$etag = '"' . substr(hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''), 0, 32) . '"';
		$response->setEtag($etag);

		return $response;
	}
}
