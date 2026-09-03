<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class LoginSecurityMiddleware
{
	public const DEVICE_COOKIE = 'gallery_device_token';
	private const COOKIE_MINUTES = 525600;
	private const ACCOUNT_MAX_ATTEMPTS = 5;
	private const IP_MAX_ATTEMPTS = 30;
	private const DECAY_SECONDS = 900;

	public function handle(Request $request, Closure $next): Response
	{
		if (!$this->isLoginRequest($request)) {
			return $next($request);
		}

		[$device, $newToken] = $this->resolveDevice($request);
		$this->queueDeviceCookie($newToken);
		[$accountKey, $ipKey] = $this->rateLimitKeys($request);
		if (RateLimiter::tooManyAttempts($accountKey, self::ACCOUNT_MAX_ATTEMPTS)
			|| RateLimiter::tooManyAttempts($ipKey, self::IP_MAX_ATTEMPTS)) {
			return response()->json([
				'message' => '登录尝试过于频繁，请在 15 分钟后重试。',
			], 429);
		}

		if ($this->trustedDeviceOnlyEnabled() && $device->trusted_at === null) {
			return response()->json([
				'message' => '此设备尚未被管理员加入可信设备。',
			], 403);
		}

		$response = $next($request);
		if ($response->getStatusCode() === 401) {
			RateLimiter::hit($accountKey, self::DECAY_SECONDS);
			RateLimiter::hit($ipKey, self::DECAY_SECONDS);
		} elseif ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
			RateLimiter::clear($accountKey);
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

	private function trustedDeviceOnlyEnabled(): bool
	{
		return (bool) DB::table('gallery_login_security_settings')
			->where('id', 1)
			->value('desktop_protection_enabled');
	}

	/** @return array{0:string,1:string} */
	private function rateLimitKeys(Request $request): array
	{
		$ip = strtolower(trim((string) $request->ip()));
		$username = mb_strtolower(trim((string) $request->input('username', '')));

		return [
			'gallery-login-account:' . hash('sha256', $ip . '|' . $username),
			'gallery-login-ip:' . hash('sha256', $ip),
		];
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
