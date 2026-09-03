<?php

namespace App\Http\Controllers\Gallery;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class AuthRegisterController extends Controller
{
	public function register(Request $request): JsonResponse
	{
		if (Auth::check()) {
			return response()->json(['ok' => true, 'already_logged_in' => true]);
		}

		$validated = $request->validate([
			'username' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[A-Za-z0-9_\-\x{4e00}-\x{9fff}]+$/u'],
			'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
			'invite_code' => ['nullable', 'string', 'max:64'],
		], [
			'username.required' => '请输入用户名。',
			'username.string' => '用户名格式不正确。',
			'username.min' => '用户名至少需要 3 个字符。',
			'username.max' => '用户名不能超过 40 个字符。',
			'username.regex' => '用户名只能包含中文、字母、数字、下划线或短横线。',
			'password.required' => '请输入密码。',
			'password.string' => '密码格式不正确。',
			'password.confirmed' => '两次输入的密码不一致。',
			'password.min' => '密码至少需要 8 位。',
			'password.max' => '密码不能超过 255 个字符。',
			'invite_code.string' => '邀请码格式不正确。',
			'invite_code.max' => '邀请码格式不正确。',
		]);

		$username = trim((string) $validated['username']);
		if ($this->usernameExists($username)) {
			abort(422, '该用户名已被使用，请换一个。');
		}

		try {
			$initialAdministrator = false;
			$user = DB::transaction(function () use ($validated, $username, &$initialAdministrator): User {
				if (!Schema::hasTable('gallery_registration_settings')
					|| !Schema::hasColumn('gallery_registration_settings', 'bootstrap_completed_at')
					|| !Schema::hasColumn('gallery_registration_settings', 'bootstrap_user_id')) {
					abort(503, '注册服务尚未完成初始化，请先运行数据库迁移。');
				}

				$settings = DB::table('gallery_registration_settings')->where('id', 1)->lockForUpdate()->first();
				if ($settings === null) {
					abort(503, '注册设置缺失，请重新运行安装脚本。');
				}

				$initialAdministrator = $settings->bootstrap_completed_at === null;
				if ($initialAdministrator && DB::table('users')->exists()) {
					abort(409, '站点已存在账号，不能通过网页创建初始管理员。请使用管理员恢复命令。');
				}
				$mode = in_array((string) $settings->mode, ['open', 'invite'], true)
					? (string) $settings->mode
					: 'invite';

				$invite = null;
				if (!$initialAdministrator && $mode === 'invite') {
					if (trim((string) ($validated['invite_code'] ?? '')) === '') {
						abort(422, '请输入邀请码。');
					}
					$codeHash = hash('sha256', $this->normalizeCode((string) ($validated['invite_code'] ?? '')));
					$invite = DB::table('gallery_registration_invites')
						->where('code_hash', $codeHash)
						->whereNull('used_at')
						->whereNull('revoked_at')
						->where('expires_at', '>', now())
						->lockForUpdate()
						->first();
					if ($invite === null) {
						abort(422, '邀请码无效、已使用或已过期。');
					}
				}

				$user = new User();
				$user->username = $username;
				$user->password = Hash::make((string) $validated['password']);
				$user->may_upload = true;
				$user->may_edit_own_settings = true;
				$user->may_administrate = $initialAdministrator;
				if (Schema::hasColumn('users', 'display_name')) {
					$user->display_name = $username;
				}
				$user->save();

				if ($initialAdministrator) {
					$updated = DB::table('gallery_registration_settings')
						->where('id', 1)
						->whereNull('bootstrap_completed_at')
						->update([
							'bootstrap_completed_at' => now(),
							'bootstrap_user_id' => $user->id,
							'updated_at' => now(),
						]);
					if ($updated !== 1) {
						abort(409, '初始管理员已由其他请求创建，请返回登录。');
					}
				}

				if ($invite !== null) {
					$updated = DB::table('gallery_registration_invites')
						->where('id', $invite->id)
						->whereNull('used_at')
						->update(['used_by' => $user->id, 'used_at' => now(), 'updated_at' => now()]);
					if ($updated !== 1) {
						abort(422, '该邀请码刚刚被使用，请更换邀请码。');
					}
				}

				return $user;
			});
		} catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
			throw $exception;
		} catch (\Throwable $exception) {
			abort(422, '该用户名已被使用，请换一个。');
		}

		Auth::login($user);
		$request->session()->regenerate();

		return response()->json(['ok' => true, 'initial_administrator' => $initialAdministrator], 201);
	}

	private function usernameExists(string $username): bool
	{
		return DB::table('users')
			->whereRaw('LOWER(username) = ?', [mb_strtolower($username)])
			->exists();
	}

	private function normalizeCode(string $code): string
	{
		return strtoupper(preg_replace('/\s+/', '', trim($code)) ?? '');
	}
}
