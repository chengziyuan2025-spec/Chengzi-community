<?php

namespace App\Http\Controllers\Gallery;

use App\GalleryExtension\Services\ActivityCursor;
use App\GalleryExtension\Services\GalleryCache;
use App\Http\Requests\StoreActivityCommentRequest;
use App\Http\Requests\StoreActivityRequest;
use App\Jobs\OptimizeActivityMedia;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ActivityController extends Controller
{
	public function __construct(private readonly ActivityCursor $cursor, private readonly GalleryCache $cache)
	{
	}

	public function album(Request $request): JsonResponse
	{
		$this->currentUser();
		$includePhotos = $request->boolean('include_photos', true);
		$limit = min(60, max(1, (int) $request->query('limit', 60)));
		$cursor = $this->cursor->decode($request->query('cursor'));
		$query = DB::table('gallery_activity_images')
			->join('gallery_activities', 'gallery_activities.id', '=', 'gallery_activity_images.activity_id')
			->orderByDesc('gallery_activities.id')
			->orderBy('gallery_activity_images.position')
			->select([
				'gallery_activity_images.id', 'gallery_activity_images.activity_id',
				'gallery_activity_images.position', 'gallery_activity_images.mime_type',
				'gallery_activity_images.path', 'gallery_activity_images.display_path', 'gallery_activity_images.width', 'gallery_activity_images.height',
				'gallery_activity_images.created_at as image_created_at',
				'gallery_activity_images.updated_at as image_updated_at',
				'gallery_activities.title as activity_title', 'gallery_activities.body as activity_body',
				'gallery_activities.created_at as activity_created_at',
				'gallery_activities.updated_at as activity_updated_at',
			]);

		if ($cursor !== null) {
			$query->where(function ($nested) use ($cursor): void {
				$nested->where('gallery_activity_images.activity_id', '<', $cursor['activity_id'])
					->orWhere(function ($sameActivity) use ($cursor): void {
						$sameActivity->where('gallery_activity_images.activity_id', $cursor['activity_id'])
							->where(function ($afterImage) use ($cursor): void {
								$afterImage->where('gallery_activity_images.position', '>', $cursor['position'])
									->orWhere(function ($samePosition) use ($cursor): void {
										$samePosition->where('gallery_activity_images.position', $cursor['position'])
											->where('gallery_activity_images.id', '>', $cursor['id']);
									});
							});
					});
			});
		}
		$total = DB::table('gallery_activity_images')->count();
		$cover = DB::table('gallery_activity_images')
			->join('gallery_activities', 'gallery_activities.id', '=', 'gallery_activity_images.activity_id')
			->orderByDesc('gallery_activities.id')->orderBy('gallery_activity_images.position')->first([
				'gallery_activity_images.id', 'gallery_activity_images.activity_id', 'gallery_activity_images.position', 'gallery_activity_images.mime_type',
				'gallery_activity_images.path', 'gallery_activity_images.display_path', 'gallery_activity_images.width', 'gallery_activity_images.height',
				'gallery_activity_images.created_at as image_created_at', 'gallery_activity_images.updated_at as image_updated_at',
				'gallery_activities.title as activity_title', 'gallery_activities.body as activity_body', 'gallery_activities.created_at as activity_created_at', 'gallery_activities.updated_at as activity_updated_at',
			]);
		$rows = $includePhotos ? $query->limit($limit + 1)->get() : collect();
		$hasMore = $includePhotos && $rows->count() > $limit;
		$photos = $rows->take($limit)->filter(fn ($image) => $this->resolveActivityImagePath($image) !== null)->values();
		$coverPhoto = $cover === null ? null : $this->imagePayload((int) $cover->activity_id, $cover);

		return response()->json([
			'album' => [
				'id' => '__activity_album__',
				'title' => '动态相册',
				'num_photos' => $total,
				'is_activity_album' => true,
				'read_only' => true,
				'thumb' => $coverPhoto === null ? null : [
					'thumb2x' => $coverPhoto['url'],
					'thumb' => $coverPhoto['url'],
					'url' => $coverPhoto['url'],
				],
			],
			'photos' => $photos->map(fn ($image) => $this->imagePayload((int) $image->activity_id, $image))->values()->all(),
			'pagination' => [
				'limit' => $limit,
				'has_more' => $hasMore,
				'next_cursor' => $hasMore && $photos->isNotEmpty() ? $this->cursor->encode($photos->last()) : null,
			],
		]);
	}

	public function index(Request $request): JsonResponse
	{
		$user = $this->currentUser();
		$limit = min(15, max(1, (int) $request->query('limit', 15)));
		$page = min(100000, max(1, (int) $request->query('page', 1)));
		$rows = $this->cache->remember('activities:' . $user->id . ':' . $page, 20, function () use ($page, $limit) {
			return DB::table('gallery_activities')
			->join('users', 'users.id', '=', 'gallery_activities.user_id')
			->orderByDesc('gallery_activities.id')
			->offset(($page - 1) * $limit)
			->limit($limit + 1)
			->get([
				'gallery_activities.id', 'gallery_activities.user_id', 'gallery_activities.title',
				'gallery_activities.body', 'gallery_activities.created_at', 'gallery_activities.updated_at',
				'users.username', 'users.display_name',
			]);
		});

		$hasMore = $rows->count() > $limit;
		$rows = $rows->take($limit)->values();
		$activityIds = $rows->pluck('id')->map(static fn ($id) => (int) $id)->all();
		$imagesByActivity = $this->availableImagesByActivity($activityIds);
		$commentCounts = $this->commentCounts($activityIds);

		return response()->json([
			'activities' => $rows->map(function ($row) use ($imagesByActivity, $commentCounts, $user): array {
				$images = $imagesByActivity[(int) $row->id] ?? [];
				return $this->activityPayload($row, array_slice($images, 0, 9), $user, count($images), (int) ($commentCounts[(int) $row->id] ?? 0));
			})->all(),
			'pagination' => ['page' => $page, 'has_more' => $hasMore],
		]);
	}

	public function images(Request $request, int $activityId): JsonResponse
	{
		$this->currentUser();
		if (!DB::table('gallery_activities')->where('id', $activityId)->exists()) {
			abort(404, 'Activity not found.');
		}
		$limit = min(9, max(1, (int) $request->query('limit', 9)));
		$cursor = $this->cursor->decode($request->query('cursor'));
		$query = DB::table('gallery_activity_images')
			->where('activity_id', $activityId)
			->orderBy('position')->orderBy('id');
		if ($cursor !== null && $cursor['activity_id'] === $activityId) {
			$query->where(function ($nested) use ($cursor): void {
				$nested->where('position', '>', $cursor['position'])->orWhere(function ($samePosition) use ($cursor): void {
					$samePosition->where('position', $cursor['position'])->where('id', '>', $cursor['id']);
				});
			});
		}
		$rows = $query->limit($limit + 1)->get(['id', 'activity_id', 'position', 'path', 'display_path', 'mime_type', 'width', 'height', 'created_at', 'updated_at']);
		$hasMore = $rows->count() > $limit;
		$images = $rows->take($limit)->filter(fn ($image) => $this->resolveActivityImagePath($image) !== null)->values();

		return response()->json([
			'images' => $images->take($limit)->map(fn ($image) => $this->imagePayload($activityId, $image))->values()->all(),
			'image_count' => DB::table('gallery_activity_images')->where('activity_id', $activityId)->count(),
			'has_more' => $hasMore,
			'next_cursor' => $hasMore && $images->isNotEmpty() ? $this->cursor->encode($images->last()) : null,
		]);
	}

	public function comments(Request $request, int $activityId): JsonResponse
	{
		$user = $this->currentUser();
		$this->ensureActivityExists($activityId);

		$comments = DB::table('gallery_activity_comments')
			->join('users', 'users.id', '=', 'gallery_activity_comments.user_id')
			->where('gallery_activity_comments.activity_id', $activityId)
			->orderBy('gallery_activity_comments.created_at')
			->orderBy('gallery_activity_comments.id')
			->get([
				'gallery_activity_comments.id', 'gallery_activity_comments.activity_id',
				'gallery_activity_comments.user_id', 'gallery_activity_comments.body',
				'gallery_activity_comments.created_at', 'gallery_activity_comments.updated_at',
				'users.username', 'users.display_name',
			])
			->map(fn ($comment) => $this->commentPayload($comment, $user))
			->values()
			->all();

		return response()->json(['comments' => $comments]);
	}

	public function storeComment(StoreActivityCommentRequest $request, int $activityId): JsonResponse
	{
		$user = $this->currentUser();
		$this->ensureActivityExists($activityId);
		$body = trim((string) $request->validated('body'));

		$now = now();
		$commentId = DB::table('gallery_activity_comments')->insertGetId([
			'activity_id' => $activityId,
			'user_id' => $user->id,
			'body' => $body,
			'created_at' => $now,
			'updated_at' => $now,
		]);
		$comment = DB::table('gallery_activity_comments')
			->join('users', 'users.id', '=', 'gallery_activity_comments.user_id')
			->where('gallery_activity_comments.id', $commentId)
			->first([
				'gallery_activity_comments.id', 'gallery_activity_comments.activity_id',
				'gallery_activity_comments.user_id', 'gallery_activity_comments.body',
				'gallery_activity_comments.created_at', 'gallery_activity_comments.updated_at',
				'users.username', 'users.display_name',
			]);

		$this->cache->forgetActivities();
		return response()->json(['comment' => $this->commentPayload($comment, $user)], 201);
	}

	public function image(Request $request, int $activityId, int $imageId)
	{
		$this->currentUser();
		$image = DB::table('gallery_activity_images')
			->where('id', $imageId)
			->where('activity_id', $activityId)
			->first(['path', 'display_path', 'mime_type']);
		if ($image === null) {
			abort(404, 'Activity image not found.');
		}
		$displayPath = $this->resolveStoredImagePath((string) ($image->display_path ?? ''));
		$storedPath = $displayPath ?: $this->resolveStoredImagePath((string) $image->path);
		if ($storedPath === null) {
			abort(404, 'Activity image not found.');
		}
		$response = response()->file($storedPath, [
			'Content-Type' => $displayPath !== null ? $this->displayMime((string) $image->display_path) : (string) $image->mime_type,
			'Cache-Control' => 'private, max-age=2592000, immutable',
			'X-Content-Type-Options' => 'nosniff',
		]);
		$response->setAutoEtag();
		$response->setAutoLastModified();
		$response->setPrivate();
		$response->setMaxAge(2592000);
		$response->setImmutable();
		$response->isNotModified($request);
		return $response;
	}

	public function destroy(int $activityId): JsonResponse
	{
		$user = $this->currentUser();
		$paths = DB::transaction(function () use ($activityId, $user): array {
			$activity = DB::table('gallery_activities')->where('id', $activityId)->lockForUpdate()->first();
			if ($activity === null) {
				abort(404, 'Activity not found.');
			}
			if ((int) $activity->user_id !== (int) $user->id && !(bool) $user->may_administrate) {
				abort(403, 'You cannot delete this activity.');
			}
			$paths = DB::table('gallery_activity_images')->where('activity_id', $activityId)->pluck('path')->all();
			DB::table('gallery_activity_images')->where('activity_id', $activityId)->delete();
			DB::table('gallery_activity_comments')->where('activity_id', $activityId)->delete();
			DB::table('gallery_activities')->where('id', $activityId)->delete();
			return $paths;
		});

		foreach ($paths as $path) {
			$this->deleteStoredImage((string) $path);
		}

		$this->cache->forgetActivities();
		return response()->json(['ok' => true]);
	}

	public function store(StoreActivityRequest $request): JsonResponse
	{
		$user = $this->currentUser();
		$title = Str::limit(trim((string) $request->validated('title', '')), 120, '');
		$body = Str::limit(trim((string) $request->validated('body', '')), 5000, '');
		$files = array_values(array_filter((array) $request->file('images', [])));

		$now = now();
		[$activityId, $imageIds] = DB::transaction(function () use ($user, $title, $body, $files, $now): array {
			$id = DB::table('gallery_activities')->insertGetId([
				'user_id' => $user->id,
				'title' => $title,
				'body' => $body,
				'created_at' => $now,
				'updated_at' => $now,
			]);
			$imageIds = [];
			foreach ($files as $position => $file) {
				$imageIds[] = $this->storeImage($id, $position, $file, $now);
			}
			return [$id, $imageIds];
		});
		foreach ($imageIds as $imageId) {
			OptimizeActivityMedia::dispatch($imageId)->afterCommit();
		}
		$this->cache->forgetActivities();

		$row = DB::table('gallery_activities')
			->join('users', 'users.id', '=', 'gallery_activities.user_id')
			->where('gallery_activities.id', $activityId)
			->first(['gallery_activities.id', 'gallery_activities.user_id', 'gallery_activities.title', 'gallery_activities.body', 'gallery_activities.created_at', 'gallery_activities.updated_at', 'users.username', 'users.display_name']);
		$images = $this->activityImages($activityId);
		$imageCount = (int) DB::table('gallery_activity_images')->where('activity_id', $activityId)->count();

		return response()->json(['activity' => $this->activityPayload($row, $images, $user, $imageCount, 0)], 201);
	}

	private function ensureActivityExists(int $activityId): void
	{
		if (!DB::table('gallery_activities')->where('id', $activityId)->exists()) {
			abort(404, 'Activity not found.');
		}
	}

	/** @return array<int, int> */
	private function commentCounts(array $activityIds): array
	{
		if ($activityIds === []) {
			return [];
		}

		return DB::table('gallery_activity_comments')
			->whereIn('activity_id', $activityIds)
			->select('activity_id', DB::raw('COUNT(*) as aggregate'))
			->groupBy('activity_id')
			->pluck('aggregate', 'activity_id')
			->map(fn ($count) => (int) $count)
			->all();
	}

	private function commentPayload(object $comment, User $currentUser): array
	{
		return [
			'id' => (int) $comment->id,
			'activity_id' => (int) $comment->activity_id,
			'user_id' => (int) $comment->user_id,
			'username' => $comment->username,
			'display_name' => $comment->display_name ?: $comment->username,
			'body' => (string) $comment->body,
			'created_at' => $comment->created_at,
			'updated_at' => $comment->updated_at,
			'is_mine' => (int) $comment->user_id === (int) $currentUser->id,
		];
	}

	private function currentUser(): User
	{
		$user = Auth::user();
		if (!$user instanceof User) {
			abort(401, 'Login required.');
		}
		return $user;
	}

	private function storeImage(int $activityId, int $position, object $file, $now): int
	{
		if (!$file->isValid()) {
			abort(422, 'Invalid uploaded image.');
		}
		$mime = (string) $file->getMimeType();
		$extensions = [
			'image/jpeg' => 'jpg',
			'image/png' => 'png',
			'image/webp' => 'webp',
			'image/gif' => 'gif',
			'image/heic' => 'heic',
			'image/heif' => 'heif',
			'video/mp4' => 'mp4',
			'video/quicktime' => 'mov',
			'video/webm' => 'webm',
		];
		if (!isset($extensions[$mime])) {
			abort(422, 'Only common image formats are supported.');
		}
		$relativeDir = 'custom-gallery/activity-uploads/' . date('Y/m');
		$directory = public_path($relativeDir);
		if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
			abort(500, 'Cannot create image upload directory.');
		}
		$filename = Str::uuid() . '.' . $extensions[$mime];
		$destination = $directory . '/' . $filename;
		if (str_starts_with($mime, 'video/')) {
			$file->move($directory, $filename);
			[$width, $height] = $this->videoDimensions($destination);
		} elseif (in_array($mime, ['image/heic', 'image/heif'], true)) {
			try {
				$image = new \Imagick($file->getRealPath());
				$width = $image->getImageWidth();
				$height = $image->getImageHeight();
				$image->clear();
				$image->destroy();
				$file->move($directory, $filename);
			} catch (\Throwable) {
				@unlink($destination);
				abort(422, 'This HEIC/HEIF image cannot be inspected.');
			}
		} else {
			$info = @getimagesize($file->getRealPath());
			if ($info === false) {
				abort(422, 'Invalid image file.');
			}
			$width = (int) $info[0];
			$height = (int) $info[1];
			$file->move($directory, $filename);
		}
		return (int) DB::table('gallery_activity_images')->insertGetId([
			'activity_id' => $activityId,
			'position' => $position,
			'path' => $relativeDir . '/' . $filename,
			'mime_type' => $mime,
			'width' => $width,
			'height' => $height,
			'processing_status' => 'pending',
			'created_at' => $now,
			'updated_at' => $now,
		]);
	}

	private function activityImages(int $activityId)
	{
		return DB::table('gallery_activity_images')
			->where('activity_id', $activityId)
			->orderBy('position')
			->get(['id', 'activity_id', 'position', 'path', 'display_path', 'mime_type', 'width', 'height', 'created_at', 'updated_at'])
			->filter(fn ($image) => $this->resolveActivityImagePath($image) !== null)
			->take(9)
			->values();
	}

	/** @return array<int, array<int, object>> */
	private function availableImagesByActivity(array $activityIds): array
	{
		if ($activityIds === []) {
			return [];
		}

		$imagesByActivity = [];
		$images = DB::table('gallery_activity_images')
			->whereIn('activity_id', $activityIds)
			->orderBy('activity_id')
			->orderBy('position')
			->get(['id', 'activity_id', 'position', 'path', 'display_path', 'mime_type', 'width', 'height', 'created_at', 'updated_at']);

		foreach ($images as $image) {
			$activityId = (int) $image->activity_id;
			if ($this->resolveActivityImagePath($image) !== null) {
				$imagesByActivity[$activityId][] = $image;
			}
		}

		return $imagesByActivity;
	}

	private function imagePayload(int $activityId, object $image): array
	{
		$imageCreatedAt = $image->image_created_at ?? $image->created_at ?? null;
		$imageUpdatedAt = $image->image_updated_at ?? $image->updated_at ?? $imageCreatedAt;
		$version = substr(hash('sha256', (string) $image->id . '|' . (string) $imageUpdatedAt), 0, 16);
		$url = '/api/v2/ActivityImages/' . $activityId . '/' . $image->id . '?v=' . $version;
		$width = (int) $image->width;
		$height = (int) $image->height;
		$mime = !empty($image->display_path) ? $this->displayMime((string) $image->display_path) : (string) $image->mime_type;
		$variant = ['url' => $url, 'width' => $width, 'height' => $height];
		$title = trim((string) ($image->activity_title ?? ''));
		$createdAt = $image->activity_created_at ?? $imageCreatedAt;

		return [
			'id' => (int) $image->id,
			'activity_id' => $activityId,
			'activity_image_id' => (int) $image->id,
			'url' => $url,
			'type' => str_starts_with($mime, 'video/') ? 'video' : 'image',
			'mime' => $mime,
			'mime_type' => $mime,
			'width' => $width,
			'height' => $height,
			'title' => $title,
			'description' => (string) ($image->activity_body ?? ''),
			'created_at' => $createdAt,
			'updated_at' => $imageUpdatedAt,
			'taken_at' => $createdAt,
			'read_only' => true,
			'source' => 'activity',
			'size_variants' => [
				'original' => $variant,
				'medium' => $variant,
				'small' => $variant,
				'thumb' => $variant,
			],
		];
	}

	private function storedImagePath(string $relativePath): string
	{
		$path = $this->resolveStoredImagePath($relativePath);
		if ($path === null) {
			abort(404, 'Activity image not found.');
		}
		return $path;
	}

	private function resolveStoredImagePath(string $relativePath): ?string
	{
		if ($relativePath === '') {
			return null;
		}
		$roots = array_filter([
			realpath(public_path('custom-gallery/activity-uploads')),
			realpath(public_path('custom-gallery/activity-display')),
		]);
		$path = realpath(public_path($relativePath));
		$allowed = false;
		foreach ($roots as $root) {
			if ($path !== false && ($path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR))) {
				$allowed = true;
				break;
			}
		}
		if (!$allowed) {
			return null;
		}
		return $path;
	}

	private function resolveActivityImagePath(object $image): ?string
	{
		foreach ([(string) ($image->display_path ?? ''), (string) ($image->path ?? '')] as $candidate) {
			$resolved = $this->resolveStoredImagePath($candidate);
			if ($resolved !== null) {
				return $resolved;
			}
		}
		return null;
	}

	private function isStoredImageAvailable(string $path): bool
	{
		return $this->resolveStoredImagePath($path) !== null;
	}

	/** @return array{0:int,1:int} */
	private function videoDimensions(string $path): array
	{
		$process = new \Symfony\Component\Process\Process(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height', '-of', 'csv=s=x:p=0', $path]);
		$process->setTimeout(20);
		$process->run();
		if (!$process->isSuccessful() || preg_match('/^(\d+)x(\d+)$/', trim($process->getOutput()), $matches) !== 1) {
			abort(422, 'Invalid video file.');
		}
		return [(int) $matches[1], (int) $matches[2]];
	}

	private function displayMime(string $path): string
	{
		return str_ends_with(strtolower($path), '.mp4') ? 'video/mp4' : 'image/webp';
	}

	private function deleteStoredImage(string $path): void
	{
		$storedPath = $this->resolveStoredImagePath($path);
		if ($storedPath !== null) {
			@unlink($storedPath);
		}
	}

	private function activityPayload(object $row, $images, User $currentUser, int $imageCount, int $commentCount = 0): array
	{
		return [
			'id' => (int) $row->id,
			'user_id' => (int) $row->user_id,
			'username' => $row->username,
			'display_name' => $row->display_name ?: $row->username,
			'title' => $row->title,
			'body' => $row->body,
			'created_at' => $row->created_at,
			'updated_at' => $row->updated_at ?? $row->created_at,
			'is_mine' => (int) $row->user_id === (int) $currentUser->id,
			'can_delete' => (int) $row->user_id === (int) $currentUser->id || (bool) $currentUser->may_administrate,
			'image_count' => $imageCount,
			'comment_count' => $commentCount,
			'images' => collect($images)->map(fn ($image) => $this->imagePayload((int) $row->id, $image))->values()->all(),
		];
	}
}
