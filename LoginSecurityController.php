<?php

namespace App\Http\Controllers\Gallery;

use App\Http\Middleware\LoginSecurityMiddleware;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;

class LoginSecurityController extends Controller
{
	public function index(Request $request): JsonResponse
	{
		$this->requireAdministrator();
		[$device, $newToken] = $this->resolveCurrentDevice($request);
		$this->queueDeviceCookie($newToken);
		$settings = $this->settings();

		$devices = DB::table('gallery_login_devices')
			->whereNotNull('trusted_at')
			->orderByDesc('trusted_at')
			->get([
				'id', 'is_desktop', 'first_seen_at', 'last_seen_at', 'ip_address', 'user_agent', 'trusted_at',
			])
			->map(static fn ($trustedDevice) => [
				'id' => (int) $trustedDevice->id,
				'is_desktop' => (bool) $trustedDevice->is_desktop,
				'is_trusted_desktop' => true,
				'is_trusted_device' => true,
				'first_seen_at' => $trustedDevice->first_seen_at,
				'last_seen_at' => $trustedDevice->last_seen_at,
				'ip_address' => $trustedDevice->ip_address,
				'user_agent' => $trustedDevice->user_agent,
				'trusted_at' => $trustedDevice->trusted_at,
			])->all();

		return response()->json([
			'desktop_protection_enabled' => (bool) $settings->desktop_protection_enabled,
			'trusted_device_only_enabled' => (bool) $settings->desktop_protection_enabled,
			'current_device' => [
				'id' => (int) $device->id,
				'is_desktop' => (bool) $device->is_desktop,
				'is_trusted_desktop' => $device->trusted_at !== null,
				'is_trusted_device' => $device->trusted_at !== null,
				'trusted_at' => $device->trusted_at,
			],
			'devices' => $devices,
		]);
	}

	public function trustCurrentDevice(Request $request): JsonResponse
	{
		$user = $this->requireAdministrator();
		[$device, $newToken] = $this->resolveCurrentDevice($request);
		DB::table('gallery_login_devices')->where('id', $device->id)->update([
			'trusted_at' => now(),
			'trusted_by' => $user->id,
			'updated_at' => now(),
		]);
		$this->queueDeviceCookie($newToken);

		return response()->json(['ok' => true]);
	}

	public function setDesktopProtection(Request $request): JsonResponse
	{
		$this->requireAdministrator();
		$enabled = $this->parseBoolean($request->input('enabled'));
		if ($enabled && !$this->hasTrustedDevice()) {
			abort(422, '开启可信设备限制前，请先信任至少一台设备。');
		}

		DB::table('gallery_login_security_settings')->updateOrInsert(
			['id' => 1],
			['desktop_protection_enabled' => $enabled, 'updated_at' => now(), 'created_at' => now()]
		);

		return response()->json(['ok' => true, 'desktop_protection_enabled' => $enabled, 'trusted_device_only_enabled' => $enabled]);
	}

	public function revokeDevice(Request $request, int $id): JsonResponse
	{
		$this->requireAdministrator();
		DB::transaction(function () use ($id): void {
			$settings = DB::table('gallery_login_security_settings')->where('id', 1)->lockForUpdate()->first();
			$device = DB::table('gallery_login_devices')->where('id', $id)->lockForUpdate()->first();
			if ($device === null || $device->trusted_at === null) {
				abort(404, 'Trusted device not found.');
			}

			if ((bool) ($settings->desktop_protection_enabled ?? false)) {
				$trustedDevices = DB::table('gallery_login_devices')
					->whereNotNull('trusted_at')
					->lockForUpdate()
					->count();
				if ($trustedDevices <= 1) {
					abort(422, '可信设备限制开启时不能撤销最后一台可信设备。');
				}
			}

			DB::table('gallery_login_devices')->where('id', $device->id)->update([
				'trusted_at' => null,
				'trusted_by' => null,
				'updated_at' => now(),
			]);
		});

		return response()->json(['ok' => true]);
	}

	private function requireAdministrator(): User
	{
		$user = Auth::user();
		if (!$user instanceof User) {
			abort(401, 'Login required.');
		}
		if (!(bool) $user->may_administrate) {
			abort(403, 'Administrator access required.');
		}

		return $user;
	}

	/**
	 * @return array{0: object, 1: string|null}
	 */
	private function resolveCurrentDevice(Request $request): array
	{
		$token = $request->cookie(LoginSecurityMiddleware::DEVICE_COOKIE);
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

		DB::table('gallery_login_devices')->where('token_hash', $tokenHash)->update([
			'is_desktop' => $this->isDesktop($request),
			'last_seen_at' => $now,
			'ip_address' => $this->limit($request->ip(), 64),
			'user_agent' => $this->limit($request->userAgent(), 512),
			'updated_at' => $now,
		]);
		$device = DB::table('gallery_login_devices')->where('token_hash', $tokenHash)->first();
		if ($device === null) {
			abort(503, 'Unable to initialize login security for this device.');
		}

		return [$device, $newToken];
	}

	private function settings(): object
	{
		DB::table('gallery_login_security_settings')->insertOrIgnore([
			'id' => 1,
			'desktop_protection_enabled' => false,
			'created_at' => now(),
			'updated_at' => now(),
		]);

		return DB::table('gallery_login_security_settings')->where('id', 1)->first();
	}

	private function hasTrustedDevice(): bool
	{
		return DB::table('gallery_login_devices')
			->whereNotNull('trusted_at')
			->exists();
	}

	private function parseBoolean(mixed $value): bool
	{
		if (in_array($value, [true, 1, '1', 'true'], true)) {
			return true;
		}
		if (in_array($value, [false, 0, '0', 'false'], true)) {
			return false;
		}

		abort(422, 'The enabled value must be boolean.');
	}

	private function queueDeviceCookie(?string $token): void
	{
		if ($token !== null) {
			Cookie::queue(LoginSecurityMiddleware::DEVICE_COOKIE, $token, 525600, '/', null, true, true, false, 'lax');
		}
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
