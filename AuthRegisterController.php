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
		], [
			'username.regex' => '用户名只能包含中文、字母、数字、下划线或短横线。',
			'password.confirmed' => '两次输入的密码不一致。',
			'password.min' => '密码至少需要 8 位。',
		]);

		$username = trim((string) $validated['username']);
		if ($this->usernameExists($username)) {
			abort(422, '该用户名已被使用，请换一个。');
		}

		$user = new User();
		$user->username = $username;
		$user->password = Hash::make((string) $validated['password']);
		$user->may_upload = true;
		$user->may_edit_own_settings = true;
		$user->may_administrate = false;
		if (Schema::hasColumn('users', 'display_name')) {
			$user->display_name = $username;
		}

		try {
			$user->save();
		} catch (\Throwable $exception) {
			abort(422, '该用户名已被使用，请换一个。');
		}

		Auth::login($user);
		$request->session()->regenerate();

		return response()->json(['ok' => true], 201);
	}

	private function usernameExists(string $username): bool
	{
		return DB::table('users')
			->whereRaw('LOWER(username) = ?', [mb_strtolower($username)])
			->exists();
	}
}
