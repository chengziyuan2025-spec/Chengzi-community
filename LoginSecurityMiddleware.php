<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class LoginSecurityMiddleware
{
	public const DEVICE_COOKIE = 'gallery_device_token';
	private const COOKIE_MINUTES = 525600;
	private const MAX_FAILED_ATTEMPTS = 5;
	private const BAN_DAYS = 5;

	public function handle(Request $request, Closure $next): Response
	{
		if (!$this->isLoginRequest($request)) {
			return $next($request);
		}

		[$device, $newToken] = $this->resolveDevice($request);
		$this->queueDeviceCookie($newToken);
		$now = now();

		if ($device->banned_until !== null && Carbon::parse($device->banned_until)->isAfter($now)) {
			return response()->json([
				'message' => 'This device is temporarily blocked after too many failed password attempts.',
			], 429);
		}

		if ($this->desktopProtectionEnabled() && (bool) $device->is_desktop && $device->trusted_at === null) {
			return response()->json([
				'message' => 'Desktop login is restricted to trusted devices.',
			], 403);
		}

		$response = $next($request);
		if ($response->getStatusCode() === 401) {
			$this->recordFailedAttempt((string) $device->token_hash);
		} elseif ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
			DB::table('gallery_login_devices')
				->where('token_hash', $device->token_hash)
				->update([
					'failed_attempts' => 0,
					'banned_until' => null,
					'last_seen_at' => now(),
					'updated_at' => now(),
				]);
		}

		return $response;
	}

	private function isLoginRequest(Request $request): bool
	{
		return $request->isMethod('post')
			&& trim($request->path(), '/') === 'api/v2/Auth::login';
	}

	/**
	 * @return array{0: object, 1: string|null}
	 */
	private function resolveDevice(Request $request): array
	{
		$token = $request->cookie(self::DEVICE_COOKIE);
		$newToken = null;
		if (!is_string($token) || preg_match('/\A[a-f0-9]{64}\z/i', $token) !== 1) {
			$token = bin2hex(random_bytes(32));
			$newToken = $token;
		}

		$tokenHash = hash('sha256', $token);
		$now = now();
		DB::table('gallery_login_devices')->insertOrIgnore([
			'token_hash' => $tokenHash,
			'is_desktop' => $this->isDesktop($request),
			'first_seen_at' => $now,
			'last_seen_at' => $now,
			'ip_address' => $this->limit($request->ip(), 64),
			'user_agent' => $this->limit($request->userAgent(), 512),
			'failed_attempts' => 0,
			'created_at' => $now,
			'updated_at' => $now,
		]);

		$device = DB::table('gallery_login_devices')->where('token_hash', $tokenHash)->first();
		if ($device === null) {
			abort(503, 'Unable to initialize login security for this device.');
		}

		$updates = [
			'is_desktop' => $this->isDesktop($request),
			'last_seen_at' => $now,
			'ip_address' => $this->limit($request->ip(), 64),
			'user_agent' => $this->limit($request->userAgent(), 512),
			'updated_at' => $now,
		];
		if ($device->banned_until !== null && Carbon::parse($device->banned_until)->isPast()) {
			$updates['failed_attempts'] = 0;
			$updates['banned_until'] = null;
		}
		DB::table('gallery_login_devices')->where('token_hash', $tokenHash)->update($updates);

		return [(object) array_merge((array) $device, $updates), $newToken];
	}

	private function recordFailedAttempt(string $tokenHash): void
	{
		DB::transaction(function () use ($tokenHash): void {
			$device = DB::table('gallery_login_devices')
				->where('token_hash', $tokenHash)
				->lockForUpdate()
				->first();
			if ($device === null) {
				return;
			}

			$attempts = (int) $device->failed_attempts + 1;
			$updates = [
				'failed_attempts' => $attempts,
				'last_seen_at' => now(),
				'updated_at' => now(),
			];
			if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
				$updates['banned_until'] = now()->addDays(self::BAN_DAYS);
			}

			DB::table('gallery_login_devices')->where('id', $device->id)->update($updates);
		});
	}

	private function desktopProtectionEnabled(): bool
	{
		return (bool) DB::table('gallery_login_security_settings')
			->where('id', 1)
			->value('desktop_protection_enabled');
	}

	private function queueDeviceCookie(?string $token): void
	{
		if ($token === null) {
			return;
		}

		Cookie::queue(self::DEVICE_COOKIE, $token, self::COOKIE_MINUTES, '/', null, true, true, false, 'lax');
	}

	private function isDesktop(Request $request): bool
	{
		$userAgent = strtolower((string) $request->userAgent());
		return preg_match('/android|iphone|ipad|ipod|mobile|windows phone|blackberry|opera mini|kindle|silk\//', $userAgent) !== 1;
	}

	private function limit(?string $value, int $length): ?string
	{
		$value = trim((string) $value);
		return $value === '' ? null : mb_substr($value, 0, $length);
	}
}
