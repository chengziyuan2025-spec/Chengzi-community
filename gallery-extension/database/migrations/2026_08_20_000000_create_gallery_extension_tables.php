<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		if (!Schema::hasTable('album_invite_codes')) {
			Schema::create('album_invite_codes', function (Blueprint $table): void { $table->id(); $table->string('album_id', 100)->index(); $table->string('code', 32)->unique(); $table->unsignedBigInteger('created_by')->nullable()->index(); $table->timestamps(); });
		}
		if (!Schema::hasTable('album_views')) {
			Schema::create('album_views', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('user_id')->index(); $table->string('album_id', 100)->index(); $table->timestamp('last_seen_photo_at')->nullable(); $table->timestamps(); $table->unique(['user_id', 'album_id']); });
		}
		if (!Schema::hasTable('gallery_activities')) {
			Schema::create('gallery_activities', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('user_id')->index(); $table->string('title', 120)->default(''); $table->text('body')->default(''); $table->timestamps(); });
		}
		if (!Schema::hasTable('gallery_activity_images')) {
			Schema::create('gallery_activity_images', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('activity_id')->index(); $table->unsignedInteger('position'); $table->string('path', 255); $table->string('mime_type', 100); $table->unsignedInteger('width'); $table->unsignedInteger('height'); $table->string('display_path', 255)->nullable(); $table->string('processing_status', 20)->default('pending')->index(); $table->timestamps(); $table->unique(['activity_id', 'position']); });
		} else {
			if (!Schema::hasColumn('gallery_activity_images', 'display_path')) Schema::table('gallery_activity_images', fn (Blueprint $table) => $table->string('display_path', 255)->nullable()->after('path'));
			if (!Schema::hasColumn('gallery_activity_images', 'processing_status')) Schema::table('gallery_activity_images', fn (Blueprint $table) => $table->string('processing_status', 20)->default('pending')->index()->after('display_path'));
		}
		Schema::table('gallery_activity_images', function (Blueprint $table): void { $table->index(['activity_id', 'position', 'id'], 'gallery_activity_images_cursor_index'); });
		if (!Schema::hasTable('gallery_activity_comments')) {
			Schema::create('gallery_activity_comments', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('activity_id')->index(); $table->unsignedBigInteger('user_id')->index(); $table->text('body'); $table->timestamps(); });
		}
	}

	public function down(): void
	{
		// Gallery data is user content and is intentionally retained on rollback.
	}
};
