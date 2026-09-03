<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class OperationAuditMiddleware
{
	private const MUTATION_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

	public function handle(Request $request, Closure $next): Response
	{
		if (!$this->shouldAudit($request)) {
			return $next($request);
		}
		$actor = $this->actor();

		try {
			$response = $next($request);
			$status = $response->getStatusCode();
			$action = $this->action($request);
			$this->record($request, $status, $actor, $action);

			return $response;
		} catch (Throwable $exception) {
			$this->record($request, $this->statusForException($exception), $actor, $this->action($request));

			throw $exception;
		}
	}

	private function shouldAudit(Request $request): bool
	{
		return in_array($request->method(), self::MUTATION_METHODS, true)
			&& str_starts_with(trim($request->path(), '/'), 'api/v2/');
	}

	/** @param array{user_id: int|null, username: string|null} $actor */
	private function record(Request $request, int $status, array $actor, string $action): void
	{
		try {
			$currentActor = $this->actor();
			$userId = $currentActor['user_id'] ?? $actor['user_id'];
			$username = $currentActor['username'] ?? $actor['username'];
			$now = now();

			DB::table('gallery_operation_audit_events')->insert([
				'user_id' => $userId,
				'actor_username' => $username,
				'action' => $this->limit($action, 255),
				'method' => $this->limit($request->method(), 10),
				'route' => $this->limit('/' . trim($request->path(), '/'), 255),
				'status' => max(0, min(999, $status)),
				'ip_address' => $this->limit($request->ip(), 64),
				'user_agent' => $this->limit($request->userAgent(), 512),
				'metadata' => json_encode($this->metadata($request), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
				'created_at' => $now,
			]);
		} catch (Throwable $exception) {
			// The business response must not fail only because audit persistence is unavailable.
			Log::warning('Unable to persist gallery operation audit event.', [
				'route' => $this->limit('/' . trim($request->path(), '/'), 255),
				'error' => $exception->getMessage(),
			]);
		}
	}

	/** @return array{user_id: int|null, username: string|null} */
	private function actor(): array
	{
		$user = Auth::user();

		return [
			'user_id' => is_object($user) && isset($user->id) ? (int) $user->id : null,
			'username' => is_object($user) && isset($user->username)
				? $this->limit((string) $user->username, 100)
				: null,
		];
	}

	private function statusForException(Throwable $exception): int
	{
		if ($exception instanceof HttpResponseException) {
			return $exception->getResponse()->getStatusCode();
		}
		if ($exception instanceof ValidationException) {
			return $exception->status;
		}

		return $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
	}

	private function action(Request $request): string
	{
		$endpoint = strtolower(substr(trim($request->path(), '/'), strlen('api/v2/')));
		$method = $request->method();

		if (str_contains($endpoint, 'albuminvitecodes::join')) {
			return 'invite.join';
		}
		if (str_contains($endpoint, 'albuminvitecodes')) {
			return 'invite.create';
		}
		if (str_contains($endpoint, 'photocomments') || str_contains($endpoint, 'comment')) {
			return $method === 'DELETE' ? 'comment.delete' : 'comment.create';
		}
		if (str_contains($endpoint, 'registrationinvites')) {
			return $method === 'DELETE' ? 'registration.invite_revoke' : 'registration.invite_create';
		}
		if (str_contains($endpoint, 'registrationsettings')) {
			return 'registration.mode_update';
		}
		if (str_contains($endpoint, 'auth::register')) {
			return 'user.register';
		}
		if (str_contains($endpoint, 'activities')) {
			return $method === 'DELETE' ? 'activity.delete' : 'activity.create';
		}
		if (str_contains($endpoint, 'photo')) {
			if (str_contains($endpoint, 'title') || str_contains($endpoint, 'rename')) {
				return 'photo.rename';
			}
			if ($method === 'DELETE' || str_contains($endpoint, 'delete')) {
				return 'photo.delete';
			}
			if ($method === 'POST' || str_contains($endpoint, 'upload') || str_contains($endpoint, 'add')) {
				return 'photo.upload';
			}
		}
		if (str_contains($endpoint, 'album')) {
			if ($method === 'DELETE' || str_contains($endpoint, 'delete')) {
				return 'album.delete';
			}
			if ($method === 'POST' || str_contains($endpoint, 'add')) {
				return 'album.create';
			}
		}

		return '/' . trim($request->path(), '/');
	}

	private function metadata(Request $request): array
	{
		$metadata = [];
		$limits = [
			'album_id' => 100,
			'photo_id' => 100,
			'id' => 100,
			'parent_id' => 100,
			'title' => 255,
			'code' => 64,
		];

		foreach ($limits as $key => $length) {
			$value = $request->input($key);
			if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
				$metadata[$key] = $this->limit((string) $value, $length);
			}
		}

		$metadata['has_image'] = $request->hasFile('image')
			|| $request->hasFile('file')
			|| trim((string) $request->input('image_data', '')) !== '';

		return $metadata;
	}

	private function limit(?string $value, int $length): ?string
	{
		$value = trim((string) $value);

		return $value === '' ? null : mb_substr($value, 0, $length);
	}
}
