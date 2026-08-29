<?php

namespace App\Http\Controllers\Gallery;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OperationAuditController extends Controller
{
	public function index(Request $request): JsonResponse
	{
		$this->requireAdministrator();
		$filters = $request->validate([
			'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
			'per_page' => ['nullable', 'integer', 'min:20', 'max:100'],
			'action' => ['nullable', 'string', 'max:255'],
			'actor' => ['nullable', 'string', 'max:100'],
			'status' => ['nullable', 'string', 'in:success,rejected,failed'],
		]);
		$page = (int) ($filters['page'] ?? 1);
		$perPage = (int) ($filters['per_page'] ?? 50);

		$query = DB::table('gallery_operation_audit_events as events')
			->leftJoin('users', 'users.id', '=', 'events.user_id');
		if (($filters['action'] ?? '') !== '') {
			$query->where('events.action', 'like', '%' . $this->escapeLike($filters['action']) . '%');
		}
		if (($filters['actor'] ?? '') !== '') {
			$actor = '%' . $this->escapeLike($filters['actor']) . '%';
			$query->where(function ($nested) use ($actor): void {
				$nested->where('events.actor_username', 'like', $actor)
					->orWhere('users.username', 'like', $actor)
					->orWhere('users.display_name', 'like', $actor);
			});
		}
		if (($filters['status'] ?? '') === 'success') {
			$query->whereBetween('events.status', [200, 299]);
		} elseif (($filters['status'] ?? '') === 'rejected') {
			$query->whereBetween('events.status', [400, 499]);
		} elseif (($filters['status'] ?? '') === 'failed') {
			$query->where('events.status', '>=', 500);
		}

		$total = $query->count();
		$events = $query
			->orderByDesc('events.created_at')
			->orderByDesc('events.id')
			->forPage($page, $perPage)
			->get([
				'events.id', 'events.user_id', 'events.actor_username', 'events.action', 'events.method',
				'events.route', 'events.status', 'events.ip_address', 'events.user_agent', 'events.metadata',
				'events.created_at', 'users.username as current_username', 'users.display_name',
			])
			->map(static function ($event): array {
				$metadata = json_decode((string) $event->metadata, true);
				$actorUsername = $event->actor_username ?: $event->current_username;
				$displayName = $event->display_name ?: $actorUsername ?: 'Anonymous';

				return [
					'id' => (int) $event->id,
					'username' => $actorUsername,
					'display_name' => $event->display_name ?: $actorUsername ?: 'Anonymous',
					'user_id' => $event->user_id === null ? null : (int) $event->user_id,
					'username' => $actorUsername,
					'display_name' => $displayName,
					'actor' => [
						'user_id' => $event->user_id === null ? null : (int) $event->user_id,
						'username' => $actorUsername,
						'display_name' => $displayName,
					],
					'action' => $event->action,
					'method' => $event->method,
					'route' => $event->route,
					'status' => (int) $event->status,
					'ip_address' => $event->ip_address,
					'user_agent' => $event->user_agent,
					'metadata' => (object) (is_array($metadata) ? $metadata : []),
					'created_at' => $event->created_at,
				];
			});

		return response()->json([
			'events' => $events,
			'pagination' => [
				'page' => $page,
				'per_page' => $perPage,
				'total' => $total,
				'last_page' => max(1, (int) ceil($total / $perPage)),
			],
		]);
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

	private function escapeLike(string $value): string
	{
		return addcslashes($value, '\\%_');
	}
}
