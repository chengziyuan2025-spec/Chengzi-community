<?php

namespace App\Http\Controllers\Gallery;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountLoginController extends Controller
{

	public function index(Request $request): JsonResponse
	{
		$user = Auth::user();
		if (!$user instanceof User) {
			abort(401, 'Login required.');
		}
		if (!$this->canViewLoginEvents($user)) {
			abort(403, 'Administrator access required to view site entry events.');
		}

		$query = DB::table('account_login_events')
			->join('users', 'users.id', '=', 'account_login_events.user_id')
			->orderByDesc('account_login_events.logged_in_at')
			->limit(80)
			->select([
				'account_login_events.id',
				'account_login_events.user_id',
				'account_login_events.logged_in_at',
				'account_login_events.ip_address',
				'account_login_events.user_agent',
				'users.username',
				'users.display_name',
			]);

		return response()->json([
			'events' => $query->get()->map(static fn ($row) => [
				'id' => (int) $row->id,
				'user_id' => (int) $row->user_id,
				'username' => $row->username,
				'display_name' => $row->display_name ?: $row->username,
				'logged_in_at' => $row->logged_in_at,
				'ip_address' => $row->ip_address,
				'user_agent' => $row->user_agent,
			])->all(),
		]);
	}

	public function store(Request $request): JsonResponse
	{
		$user = Auth::user();
		if (!$user instanceof User) {
			abort(401, 'Login required.');
		}
		if ($this->canViewLoginEvents($user)) {
			return response()->json(['ok' => true, 'skipped' => true]);
		}

		$now = now();
		$ipAddress = Str::limit((string) $request->ip(), 64, '');
		$userAgent = Str::limit((string) $request->userAgent(), 255, '');

		DB::table('account_login_events')->insert([
			'user_id' => $user->id,
			'logged_in_at' => $now,
			'ip_address' => $ipAddress,
			'user_agent' => $userAgent,
			'created_at' => $now,
			'updated_at' => $now,
		]);

		return response()->json(['ok' => true], 201);
	}

	private function canViewLoginEvents(User $user): bool
	{
		return (bool) $user->may_administrate;
	}
}
