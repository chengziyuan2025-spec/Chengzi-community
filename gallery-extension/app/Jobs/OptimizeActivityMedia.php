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
	public int $timeout = 300;

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
			return;
		}
		if (str_starts_with((string) $image->mime_type, 'video/')) {
			$this->transcodeVideo($image, $source);
			return;
		}
		if (!class_exists(\Imagick::class) || str_starts_with((string) $image->mime_type, 'image/gif')) return;

		$targetDir = public_path('custom-gallery/activity-display/' . now()->format('Y/m'));
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
			$image->forceFill([
				'display_path' => 'custom-gallery/activity-display/' . now()->format('Y/m') . '/' . basename($target),
				'processing_status' => 'ready',
			])->save();
		} finally {
			$media->clear();
			$media->destroy();
		}
	}

	private function transcodeVideo(GalleryActivityImage $image, string $source): void
	{
		$targetDir = public_path('custom-gallery/activity-display/' . now()->format('Y/m'));
		if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) throw new \RuntimeException('Cannot create optimized media directory.');
		$target = $targetDir . '/' . pathinfo((string) $image->path, PATHINFO_FILENAME) . '.mp4';
		$temp = $target . '.' . bin2hex(random_bytes(6)) . '.tmp.mp4';
		try {
			$process = new Process(['ffmpeg', '-y', '-i', $source, '-map', '0:v:0', '-map', '0:a?', '-vf', 'scale=1920:1080:force_original_aspect_ratio=decrease', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', $temp]);
			$process->setTimeout(900);
			$process->mustRun();
			if (!rename($temp, $target)) throw new \RuntimeException('Cannot store optimized video.');
			$image->forceFill(['display_path' => 'custom-gallery/activity-display/' . now()->format('Y/m') . '/' . basename($target), 'processing_status' => 'ready'])->save();
		} finally {
			@unlink($temp);
		}
	}

	public function failed(\Throwable $exception): void
	{
		GalleryActivityImage::whereKey($this->imageId)->update(['processing_status' => 'failed', 'updated_at' => now()]);
	}
}
