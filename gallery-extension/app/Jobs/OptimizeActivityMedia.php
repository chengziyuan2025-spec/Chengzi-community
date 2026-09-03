<?php

namespace App\Jobs;

use App\Models\GalleryActivityImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Process\Process;

class OptimizeActivityMedia implements ShouldQueue
{
	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	public int $tries = 3;
	public int $timeout = 900;

	public function __construct(public readonly int $imageId)
	{
	}

	public function handle(): void
	{
		$image = GalleryActivityImage::find($this->imageId);
		if ($image === null || $image->display_path !== null) {
			return;
		}
		$source = public_path($image->path);
		if (!is_file($source)) {
			GalleryActivityImage::whereKey($this->imageId)->update(['processing_status' => 'failed', 'updated_at' => now()]);
			return;
		}
		if (str_starts_with((string) $image->mime_type, 'video/')) {
			$this->transcodeVideo($image, $source);
			return;
		}
		if (!class_exists(\Imagick::class) || str_starts_with((string) $image->mime_type, 'image/gif')) {
			GalleryActivityImage::whereKey($this->imageId)->update(['processing_status' => 'ready', 'updated_at' => now()]);
			return;
		}

		$relativeDir = 'custom-gallery/activity-display/' . now()->format('Y/m');
		$targetDir = public_path($relativeDir);
		if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
			throw new \RuntimeException('Cannot create optimized media directory.');
		}
		$target = $targetDir . '/' . pathinfo((string) $image->path, PATHINFO_FILENAME) . '.webp';
		$media = new \Imagick($source);
		try {
			$media->autoOrient();
			$media->stripImage();
			// Keep the original aspect ratio and never upscale small uploads.
			$maxDimension = 2048;
			$width = $media->getImageWidth();
			$height = $media->getImageHeight();
			if ($width > $maxDimension || $height > $maxDimension) {
				$media->thumbnailImage($maxDimension, $maxDimension, true, false);
			}
			$media->setImageFormat('webp');
			$media->setImageCompressionQuality(80);
			$media->writeImage($target);
			$this->commitOutput($target, $relativeDir . '/' . basename($target));
		} finally {
			$media->clear();
			$media->destroy();
		}
	}

	private function transcodeVideo(GalleryActivityImage $image, string $source): void
	{
		$relativeDir = 'custom-gallery/activity-display/' . now()->format('Y/m');
		$targetDir = public_path($relativeDir);
		if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) throw new \RuntimeException('Cannot create optimized media directory.');
		$target = $targetDir . '/' . pathinfo((string) $image->path, PATHINFO_FILENAME) . '.mp4';
		$temp = $target . '.' . bin2hex(random_bytes(6)) . '.tmp.mp4';
		try {
			$process = new Process(['ffmpeg', '-y', '-i', $source, '-map', '0:v:0', '-map', '0:a?', '-vf', 'scale=1920:1080:force_original_aspect_ratio=decrease', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', $temp]);
			$process->setTimeout(900);
			$process->mustRun();
			if (!rename($temp, $target)) throw new \RuntimeException('Cannot store optimized video.');
			$this->commitOutput($target, $relativeDir . '/' . basename($target));
		} finally {
			@unlink($temp);
		}
	}

	private function commitOutput(string $target, string $displayPath): void
	{
		$updated = GalleryActivityImage::whereKey($this->imageId)
			->whereNull('display_path')
			->update(['display_path' => $displayPath, 'processing_status' => 'ready', 'updated_at' => now()]);
		if ($updated !== 1) {
			$currentPath = GalleryActivityImage::whereKey($this->imageId)->value('display_path');
			if ((string) $currentPath !== $displayPath) {
				@unlink($target);
			}
		}
	}

	public function failed(\Throwable $exception): void
	{
		GalleryActivityImage::whereKey($this->imageId)->update(['processing_status' => 'failed', 'updated_at' => now()]);
	}
}
