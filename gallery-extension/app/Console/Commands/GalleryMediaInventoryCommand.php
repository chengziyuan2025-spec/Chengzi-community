<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class GalleryMediaInventoryCommand extends Command
{
	protected $signature = 'gallery:media-inventory {--json : Emit JSON} {--apply : Delete orphan activity media files}';
	protected $description = 'Report activity media usage and optionally delete orphan files.';

	public function handle(): int
	{
		$registered = [];
		DB::table('gallery_activity_images')->orderBy('id')->get(['path', 'display_path'])->each(
			static function ($media) use (&$registered): void {
				foreach ([(string) $media->path, (string) ($media->display_path ?? '')] as $path) {
					if ($path !== '') {
						$registered[str_replace('\\', '/', $path)] = true;
					}
				}
			}
		);

		$report = [];
		foreach (['activity-uploads', 'activity-display'] as $directoryName) {
			$report[$directoryName] = $this->inspectDirectory($directoryName, $registered, (bool) $this->option('apply'));
		}

		if ($this->option('json')) {
			$this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			return self::SUCCESS;
		}

		$this->table(
			['Directory', 'Files', 'Bytes', 'Orphans', 'Orphan bytes', 'Deleted'],
			array_map(static fn ($name, $row) => [
				$name, $row['files'], $row['bytes'], $row['orphans'], $row['orphan_bytes'], $row['deleted'],
			], array_keys($report), $report)
		);
		foreach ($report as $directoryName => $row) {
			foreach ($row['orphan_files'] as $path) {
				$this->line('ORPHAN ' . $directoryName . ': ' . $path);
			}
			foreach ($row['delete_failures'] as $path) {
				$this->warn('DELETE FAILED ' . $directoryName . ': ' . $path);
			}
		}
		$this->info($this->option('apply')
			? 'Orphan activity media cleanup completed.'
			: 'Report only. Re-run with --apply to delete the listed orphan files.');

		return self::SUCCESS;
	}

	/** @param array<string, bool> $registered */
	private function inspectDirectory(string $directoryName, array $registered, bool $apply): array
	{
		$root = public_path('custom-gallery/' . $directoryName);
		$rootRealPath = realpath($root);
		$stats = [
			'files' => 0, 'bytes' => 0, 'orphans' => 0, 'orphan_bytes' => 0, 'deleted' => 0,
			'orphan_files' => [], 'delete_failures' => [],
		];
		if ($rootRealPath === false || !is_dir($rootRealPath)) {
			return $stats;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($rootRealPath, \FilesystemIterator::SKIP_DOTS)
		);
		foreach ($iterator as $file) {
			if (!$file instanceof SplFileInfo || !$file->isFile()) {
				continue;
			}
			$stats['files']++;
			$stats['bytes'] += $file->getSize();
			$relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($rootRealPath))), '/');
			$key = 'custom-gallery/' . $directoryName . '/' . $relative;
			if (isset($registered[$key])) {
				continue;
			}
			$stats['orphans']++;
			$stats['orphan_bytes'] += $file->getSize();
			$stats['orphan_files'][] = $relative;
			if ($apply) {
				if (@unlink($file->getPathname())) {
					$stats['deleted']++;
				} else {
					$stats['delete_failures'][] = $relative;
				}
			}
		}

		return $stats;
	}
}
