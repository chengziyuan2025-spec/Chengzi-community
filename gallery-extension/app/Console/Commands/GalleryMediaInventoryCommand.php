<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class GalleryMediaInventoryCommand extends Command
{
	protected $signature = 'gallery:media-inventory {--json : Emit JSON}';
	protected $description = 'Report Lychee derivative media usage without deleting any files.';

	public function handle(): int
	{
		$root = public_path('Pictures');
		$variants = ['thumb', 'thumb2x', 'small', 'small2x', 'medium', 'medium2x', 'placeholder'];
		$report = [];
		foreach ($variants as $variant) {
			$path = $root . DIRECTORY_SEPARATOR . $variant;
			$bytes = 0;
			$count = 0;
			if (is_dir($path)) {
				foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
					if ($file instanceof SplFileInfo && $file->isFile()) {
						$count++;
						$bytes += $file->getSize();
					}
				}
			}
			$report[$variant] = ['files' => $count, 'bytes' => $bytes];
		}
		if ($this->option('json')) {
			$this->line(json_encode($report, JSON_PRETTY_PRINT));
			return self::SUCCESS;
		}
		$this->table(['Variant', 'Files', 'Bytes'], array_map(static fn ($name, $row) => [$name, $row['files'], $row['bytes']], array_keys($report), $report));
		$this->info('Report only: no files were removed.');
		return self::SUCCESS;
	}
}
