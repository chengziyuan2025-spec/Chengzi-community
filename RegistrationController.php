<?php

namespace App\Http\Controllers\Gallery;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class RegistrationController extends Controller
{
	public function status(): JsonResponse
	{
		return response()->json([
			'mode' => $this->mode(),
			'setup_required' => $this->setupRequired(),
		]);
	}

	public function settings(): JsonResponse
	{
		$this->requireAdministrator();

		return response()->json(['mode' => $this->mode()]);
	}

	public function updateSettings(Request $request): JsonResponse
	{
		$this->requireAdministrator();
		$validated = $request->validate(['mode' => ['required', 'string', 'in:open,invite']]);
		$now = now();
		$updated = DB::table('gallery_registration_settings')->where('id', 1)->update([
			'mode' => $validated['mode'], 'updated_at' => $now,
		]);
		if ($updated === 0 && !DB::table('gallery_registration_settings')->where('id', 1)->exists()) {
			DB::table('gallery_registration_settings')->insert([
				'id' => 1, 'mode' => $validated['mode'], 'created_at' => $now, 'updated_at' => $now,
			]);
		}

		return response()->json(['mode' => $validated['mode']]);
	}

	public function invites(): JsonResponse
	{
		$this->requireAdministrator();
		$now = now();
		$invites = DB::table('gallery_registration_invites')
			->orderByDesc('id')
			->limit(100)
			->get(['id', 'created_by', 'used_by', 'expires_at', 'used_at', 'revoked_at', 'created_at'])
			->map(static function ($invite) use ($now): array {
				$status = $invite->revoked_at !== null
					? 'revoked'
					: ($invite->used_at !== null ? 'used' : (Carbon::parse($invite->expires_at)->lte($now) ? 'expired' : 'available'));

				return [
					'id' => (int) $invite->id,
					'created_by' => $invite->created_by === null ? null : (int) $invite->created_by,
					'used_by' => $invite->used_by === null ? null : (int) $invite->used_by,
					'expires_at' => $invite->expires_at,
					'used_at' => $invite->used_at,
					'revoked_at' => $invite->revoked_at,
					'created_at' => $invite->created_at,
					'status' => $status,
				];
			});

		return response()->json(['invites' => $invites]);
	}

	public function createInvite(): JsonResponse
	{
		$user = $this->requireAdministrator();
		$code = strtoupper(Str::random(4) . '-' . Str::random(4) . '-' . Str::random(4));
		$now = now();
		$expiresAt = $now->copy()->addDays(7);
		$id = DB::table('gallery_registration_invites')->insertGetId([
			'code_hash' => hash('sha256', $this->normalizeCode($code)),
			'created_by' => $user->id,
			'expires_at' => $expiresAt,
			'created_at' => $now,
			'updated_at' => $now,
		]);

		return response()->json([
			'invite' => [
				'id' => (int) $id,
				'code' => $code,
				'expires_at' => $expiresAt,
				'status' => 'available',
			],
		], 201);
	}

	public function revokeInvite(int $id): JsonResponse
	{
		$this->requireAdministrator();
		$updated = DB::table('gallery_registration_invites')
			->where('id', $id)
			->whereNull('used_at')
			->whereNull('revoked_at')
			->update(['revoked_at' => now(), 'updated_at' => now()]);
		if ($updated !== 1) {
			abort(422, '该邀请码不存在、已使用或已撤销。');
		}

		return response()->json(['ok' => true]);
	}

	private function mode(): string
	{
		if (!Schema::hasTable('gallery_registration_settings')) {
			return 'invite';
		}
		$mode = (string) DB::table('gallery_registration_settings')->where('id', 1)->value('mode');

		return in_array($mode, ['open', 'invite'], true) ? $mode : 'invite';
	}

	private function setupRequired(): bool
	{
		if (!Schema::hasTable('gallery_registration_settings')
			|| !Schema::hasColumn('gallery_registration_settings', 'bootstrap_completed_at')) {
			return false;
		}

		$settings = DB::table('gallery_registration_settings')->where('id', 1)->first();
		return $settings !== null
			&& $settings->bootstrap_completed_at === null
			&& Schema::hasTable('users')
			&& !DB::table('users')->exists();
	}

	private function normalizeCode(string $code): string
	{
		return strtoupper(preg_replace('/\s+/', '', trim($code)) ?? '');
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
}
