<?php

namespace App\Http\Middleware;

use App\GalleryExtension\Support\ApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class GalleryApiMiddleware
{
	public function handle(Request $request, Closure $next)
	{
		try {
			$response = $next($request);
		} catch (ValidationException $exception) {
			return ApiResponse::error($request, 'validation_failed', $exception->getMessage(), $exception->status, $exception->errors());
		} catch (HttpExceptionInterface $exception) {
			return ApiResponse::error($request, 'request_failed', $exception->getMessage() ?: 'Request failed.', $exception->getStatusCode());
		} catch (Throwable $exception) {
			report($exception);
			return ApiResponse::error($request, 'internal_error', 'The request could not be completed.', 500);
		}

		if (!$response instanceof JsonResponse || $this->isEnvelope($response)) {
			return $response;
		}

		$payload = $response->getData(true);
		$wrapped = ApiResponse::success($request, $payload, $response->getStatusCode());
		foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
			if (!in_array(strtolower($name), ['content-type', 'content-length', 'etag'], true)) {
				$wrapped->headers->set($name, $values);
			}
		}

		return $wrapped;
	}

	private function isEnvelope(JsonResponse $response): bool
	{
		$payload = $response->getData(true);
		return is_array($payload) && array_key_exists('success', $payload) && array_key_exists('request_id', $payload);
	}
}
