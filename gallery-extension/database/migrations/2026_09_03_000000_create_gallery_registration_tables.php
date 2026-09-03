<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		if (!Schema::hasTable('gallery_registration_settings')) {
			Schema::create('gallery_registration_settings', function (Blueprint $table): void {
				$table->unsignedBigInteger('id')->primary();
				$table->string('mode', 20)->default('invite');
				$table->timestamps();
			});
		}

		if (!Schema::hasTable('gallery_registration_invites')) {
			Schema::create('gallery_registration_invites', function (Blueprint $table): void {
				$table->id();
				$table->char('code_hash', 64)->unique();
				$table->unsignedBigInteger('created_by')->nullable()->index();
				$table->unsignedBigInteger('used_by')->nullable()->index();
				$table->timestamp('expires_at')->index();
				$table->timestamp('used_at')->nullable()->index();
				$table->timestamp('revoked_at')->nullable()->index();
				$table->timestamps();
			});
		}

		DB::table('gallery_registration_settings')->insertOrIgnore([
			'id' => 1,
			'mode' => 'invite',
			'created_at' => now(),
			'updated_at' => now(),
		]);
	}

	public function down(): void
	{
		Schema::dropIfExists('gallery_registration_invites');
		Schema::dropIfExists('gallery_registration_settings');
	}
};
