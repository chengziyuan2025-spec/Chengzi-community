<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Schema;

return new class extends Migration {
	public function up(): void
	{
		if (!Schema::hasTable('gallery_activity_likes')) {
			Schema::create('gallery_activity_likes', function (Blueprint $table): void {
				$table->id();
				$table->unsignedBigInteger('activity_id')->index();
				$table->unsignedBigInteger('user_id')->index();
				$table->timestamps();
				$table->unique(['activity_id', 'user_id']);
			});
		}
	}

	public function down(): void
	{
		// Gallery interaction data is retained deliberately on rollback.
	}
};
