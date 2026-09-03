<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		if (!Schema::hasTable('gallery_registration_settings')) {
			return;
		}

		$needsCompletedAt = !Schema::hasColumn('gallery_registration_settings', 'bootstrap_completed_at');
		$needsUserId = !Schema::hasColumn('gallery_registration_settings', 'bootstrap_user_id');
		Schema::table('gallery_registration_settings', function (Blueprint $table) use ($needsCompletedAt, $needsUserId): void {
			if ($needsCompletedAt) {
				$table->timestamp('bootstrap_completed_at')->nullable();
			}
			if ($needsUserId) {
				$table->unsignedBigInteger('bootstrap_user_id')->nullable()->index();
			}
		});

		// Existing installations must never expose the public bootstrap flow. Only a
		// genuinely empty users table is eligible to create the first administrator.
		if (Schema::hasTable('users') && DB::table('users')->exists()) {
			$administratorId = DB::table('users')
				->where('may_administrate', true)
				->orderBy('id')
				->value('id');
			DB::table('gallery_registration_settings')->where('id', 1)->update([
				'bootstrap_completed_at' => now(),
				'bootstrap_user_id' => $administratorId,
				'updated_at' => now(),
			]);
		}
	}

	public function down(): void
	{
		if (!Schema::hasTable('gallery_registration_settings')) {
			return;
		}
		$hasUserId = Schema::hasColumn('gallery_registration_settings', 'bootstrap_user_id');
		$hasCompletedAt = Schema::hasColumn('gallery_registration_settings', 'bootstrap_completed_at');
		Schema::table('gallery_registration_settings', function (Blueprint $table) use ($hasUserId, $hasCompletedAt): void {
			if ($hasUserId) {
				$table->dropIndex(['bootstrap_user_id']);
				$table->dropColumn('bootstrap_user_id');
			}
			if ($hasCompletedAt) {
				$table->dropColumn('bootstrap_completed_at');
			}
		});
	}
};
