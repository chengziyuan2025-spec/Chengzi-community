<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		if (!Schema::hasTable('account_login_events')) Schema::create('account_login_events', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('user_id')->index(); $table->timestamp('logged_in_at')->index(); $table->string('ip_address', 64)->nullable(); $table->string('user_agent', 255)->nullable(); $table->timestamps(); });
		if (!Schema::hasTable('gallery_login_security_settings')) Schema::create('gallery_login_security_settings', function (Blueprint $table): void { $table->unsignedBigInteger('id')->primary(); $table->boolean('desktop_protection_enabled')->default(false); $table->timestamps(); });
		if (!Schema::hasTable('gallery_login_devices')) Schema::create('gallery_login_devices', function (Blueprint $table): void { $table->id(); $table->char('token_hash', 64)->unique(); $table->boolean('is_desktop')->default(false)->index(); $table->timestamp('first_seen_at'); $table->timestamp('last_seen_at')->index(); $table->string('ip_address', 64)->nullable(); $table->string('user_agent', 512)->nullable(); $table->unsignedTinyInteger('failed_attempts')->default(0); $table->timestamp('banned_until')->nullable()->index(); $table->timestamp('trusted_at')->nullable()->index(); $table->unsignedBigInteger('trusted_by')->nullable()->index(); $table->timestamps(); });
		if (!Schema::hasTable('gallery_operation_audit_events')) Schema::create('gallery_operation_audit_events', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('user_id')->nullable()->index(); $table->string('actor_username', 100)->nullable(); $table->string('action', 255)->index(); $table->string('method', 10); $table->string('route', 255); $table->unsignedSmallInteger('status')->index(); $table->string('ip_address', 64)->nullable(); $table->string('user_agent', 512)->nullable(); $table->text('metadata'); $table->timestamp('created_at')->index(); });
		DB::table('gallery_login_security_settings')->insertOrIgnore(['id' => 1, 'desktop_protection_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
	}

	public function down(): void
	{
		// Security and audit data is retained deliberately on rollback.
	}
};
