<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GalleryPromoteAdministratorCommand extends Command
{
	protected $signature = 'gallery:admin:promote {username : Exact username to promote}';
	protected $description = 'Promote an existing Gallery user to administrator and close first-run setup.';

	public function handle(): int
	{
		if (!Schema::hasTable('gallery_registration_settings')
			|| !Schema::hasColumn('gallery_registration_settings', 'bootstrap_completed_at')
			|| !Schema::hasColumn('gallery_registration_settings', 'bootstrap_user_id')) {
			$this->error('Registration bootstrap schema is missing. Run the Gallery migrations first.');
			return self::FAILURE;
		}
		if (!DB::table('gallery_registration_settings')->where('id', 1)->exists()) {
			$this->error('Registration settings are missing. Re-run the Gallery installer.');
			return self::FAILURE;
		}

		$username = trim((string) $this->argument('username'));
		$user = User::query()->where('username', $username)->first();
		if (!$user instanceof User || (string) $user->username !== $username) {
			$this->error("User '{$username}' was not found.");
			return self::FAILURE;
		}

		$wasAdministrator = (bool) $user->may_administrate;
		DB::transaction(function () use ($user): void {
			DB::table('gallery_registration_settings')->where('id', 1)->lockForUpdate()->first();
			if (!(bool) $user->may_administrate) {
				$user->may_administrate = true;
				$user->save();
			}
			DB::table('gallery_registration_settings')->where('id', 1)->update([
				'bootstrap_completed_at' => now(),
				'bootstrap_user_id' => $user->id,
				'updated_at' => now(),
			]);
		});

		$this->info($wasAdministrator
			? "User '{$username}' was already an administrator. First-run setup is closed."
			: "User '{$username}' is now an administrator.");
		return self::SUCCESS;
	}
}
