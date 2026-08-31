#!/bin/sh
set -eu

APP_DIR="/app/www"
SRC_DIR="/config/codex-custom-frontend"
DEST_DIR="$APP_DIR/public/custom-gallery"
CONFIG_DEST_DIR="/config/www/custom-gallery"
ACTIVITY_UPLOAD_DIR="$CONFIG_DEST_DIR/activity-uploads"
ACTIVITY_DISPLAY_DIR="$CONFIG_DEST_DIR/activity-display"
ADMIN_SRC_DIR="$SRC_DIR/admin"
ADMIN_DEST_DIR="$APP_DIR/public/myadmin"
CONFIG_ADMIN_DEST_DIR="/config/www/myadmin"
NGINX_SITE_CONF="/config/nginx/site-confs/default.conf"
VIEW_DIR="$APP_DIR/resources/views"
COMPONENT_DIR="$VIEW_DIR/components"
ERROR_VIEW_DIR="$VIEW_DIR/error"
COMMAND_DIR="$APP_DIR/app/Console/Commands"
GALLERY_EXTENSION_SRC="$SRC_DIR/gallery-extension"
GALLERY_ROUTE_DIR="$APP_DIR/routes"
GALLERY_MIGRATION_DIR="$APP_DIR/database/migrations/gallery-extension"
PHP_DIR="/config/php"
PHP_LOCAL_INI="$PHP_DIR/php-local.ini"
ENV_FILE="$APP_DIR/.env"
BACKUP_DIR="/config/codex-backups/custom-frontend"
CUSTOM_INIT_DIR="/config/custom-cont-init.d"
CUSTOM_INIT_HOOK="$CUSTOM_INIT_DIR/20-install-custom-gallery"
BACKUP_STAMP="$(date +%Y%m%d%H%M%S)"

require_file() {
	if [ ! -f "$1" ]; then
		echo "Missing required file: $1" >&2
		exit 1
	fi
}

backup_and_copy() {
	src="$1"
	dest="$2"
	name="$(basename "$dest")"
	if [ -f "$dest" ] && ! cmp -s "$src" "$dest"; then
		cp "$dest" "$BACKUP_DIR/$name.$BACKUP_STAMP.bak"
	fi
	cp "$src" "$dest"
}

asset_version() {
	cat "$SRC_DIR/app.js" "$SRC_DIR/styles.css" | sha256sum | cut -c1-16
}

render_asset_template() {
	template="$1"
	destination="$2"
	sed "s/__ASSET_VERSION__/$ASSET_VERSION/g" "$template" > "$destination"
}

ensure_ini_value() {
	file="$1"
	key="$2"
	value="$3"
	if grep -Eq "^[[:space:]]*;?[[:space:]]*$key[[:space:]]*=" "$file"; then
		sed -i "s|^[[:space:]]*;\\?[[:space:]]*$key[[:space:]]*=.*|$key = $value|" "$file"
	else
		printf '%s = %s\n' "$key" "$value" >> "$file"
	fi
}

ensure_env_value() {
	file="$1"
	key="$2"
	value="$3"
	touch "$file"
	if grep -Eq "^[[:space:]]*#?[[:space:]]*$key[[:space:]]*=" "$file"; then
		sed -i "s|^[[:space:]]*#\\?[[:space:]]*$key[[:space:]]*=.*|$key=$value|" "$file"
	else
		printf '%s=%s\n' "$key" "$value" >> "$file"
	fi
}

ensure_env_value_if_missing() {
	file="$1"
	key="$2"
	value="$3"
	touch "$file"
	if ! grep -Eq "^[[:space:]]*#?[[:space:]]*$key[[:space:]]*=" "$file"; then
		printf '%s=%s\n' "$key" "$value" >> "$file"
	fi
}

require_file "$SRC_DIR/index.html"
require_file "$SRC_DIR/styles.css"
require_file "$SRC_DIR/app.js"
require_file "$SRC_DIR/vueapp.blade.php"
require_file "$SRC_DIR/robots.txt"
require_file "$SRC_DIR/AccountLoginController.php"
require_file "$SRC_DIR/AuthRegisterController.php"
require_file "$SRC_DIR/ActivityController.php"
require_file "$SRC_DIR/LoginSecurityController.php"
require_file "$SRC_DIR/LoginSecurityMiddleware.php"
require_file "$SRC_DIR/PerformanceTimingMiddleware.php"
require_file "$SRC_DIR/OperationAuditController.php"
require_file "$SRC_DIR/OperationAuditMiddleware.php"
require_file "$GALLERY_EXTENSION_SRC/routes/gallery.php"
require_file "$GALLERY_EXTENSION_SRC/app/Support/ApiResponse.php"
require_file "$GALLERY_EXTENSION_SRC/app/Http/Middleware/GalleryApiMiddleware.php"
require_file "$GALLERY_EXTENSION_SRC/database/migrations/2026_08_20_000000_create_gallery_extension_tables.php"
require_file "$ADMIN_SRC_DIR/index.html"
require_file "$ADMIN_SRC_DIR/admin.css"
require_file "$ADMIN_SRC_DIR/admin.js"

mkdir -p "$DEST_DIR" "$ACTIVITY_UPLOAD_DIR" "$ACTIVITY_DISPLAY_DIR" "$ADMIN_DEST_DIR" "$APP_DIR/bootstrap/cache" "$APP_DIR/storage/logs" "$COMPONENT_DIR" "$ERROR_VIEW_DIR" "$APP_DIR/app/Http/Middleware" "$APP_DIR/app/Http/Requests" "$APP_DIR/app/Http/Resources" "$APP_DIR/app/Models" "$APP_DIR/app/Jobs" "$APP_DIR/app/GalleryExtension" "$COMMAND_DIR" "$GALLERY_ROUTE_DIR" "$GALLERY_MIGRATION_DIR" "$PHP_DIR" "$BACKUP_DIR" "$CUSTOM_INIT_DIR" "$(dirname "$NGINX_SITE_CONF")"

printf '%s\n' \
	'#!/bin/sh' \
	'exec sh /config/codex-custom-frontend/20-install-custom-gallery.sh' \
	> "$CUSTOM_INIT_HOOK"
chmod +x "$CUSTOM_INIT_HOOK"

# Activity uploads used to live only in the container layer and disappeared
# whenever the container was recreated. Move any surviving files into /config
# and keep the public path as a stable symlink.
if [ ! -L "$DEST_DIR/activity-uploads" ]; then
	if [ -d "$DEST_DIR/activity-uploads" ]; then
		cp -a "$DEST_DIR/activity-uploads/." "$ACTIVITY_UPLOAD_DIR/"
		rm -rf "$DEST_DIR/activity-uploads"
	elif [ -e "$DEST_DIR/activity-uploads" ]; then
		rm -f "$DEST_DIR/activity-uploads"
	fi
fi
ln -sfn "$ACTIVITY_UPLOAD_DIR" "$DEST_DIR/activity-uploads"
ln -sfn "$ACTIVITY_DISPLAY_DIR" "$DEST_DIR/activity-display"

ASSET_VERSION="$(asset_version)"
render_asset_template "$SRC_DIR/index.html" "$DEST_DIR/index.html"
cp "$SRC_DIR/styles.css" "$DEST_DIR/styles.css"
cp "$SRC_DIR/app.js" "$DEST_DIR/app.js"
cp "$ADMIN_SRC_DIR/index.html" "$ADMIN_DEST_DIR/index.html"
cp "$ADMIN_SRC_DIR/admin.css" "$ADMIN_DEST_DIR/admin.css"
cp "$ADMIN_SRC_DIR/admin.js" "$ADMIN_DEST_DIR/admin.js"
if [ -f "$SRC_DIR/apple-touch-icon.png" ]; then
	cp "$SRC_DIR/apple-touch-icon.png" "$DEST_DIR/apple-touch-icon.png"
fi
if [ -d /config/www ]; then
	mkdir -p "$CONFIG_DEST_DIR"
	render_asset_template "$SRC_DIR/index.html" "$CONFIG_DEST_DIR/index.html"
	cp "$SRC_DIR/styles.css" "$CONFIG_DEST_DIR/styles.css"
	cp "$SRC_DIR/app.js" "$CONFIG_DEST_DIR/app.js"
	if [ -f "$SRC_DIR/apple-touch-icon.png" ]; then
		cp "$SRC_DIR/apple-touch-icon.png" "$CONFIG_DEST_DIR/apple-touch-icon.png"
	fi
	mkdir -p "$CONFIG_ADMIN_DEST_DIR"
	cp "$ADMIN_SRC_DIR/index.html" "$CONFIG_ADMIN_DEST_DIR/index.html"
	cp "$ADMIN_SRC_DIR/admin.css" "$CONFIG_ADMIN_DEST_DIR/admin.css"
	cp "$ADMIN_SRC_DIR/admin.js" "$CONFIG_ADMIN_DEST_DIR/admin.js"
fi
backup_and_copy "$SRC_DIR/AccountLoginController.php" "$APP_DIR/app/Http/Controllers/Gallery/AccountLoginController.php"
backup_and_copy "$SRC_DIR/AuthRegisterController.php" "$APP_DIR/app/Http/Controllers/Gallery/AuthRegisterController.php"
backup_and_copy "$SRC_DIR/ActivityController.php" "$APP_DIR/app/Http/Controllers/Gallery/ActivityController.php"
backup_and_copy "$SRC_DIR/LoginSecurityController.php" "$APP_DIR/app/Http/Controllers/Gallery/LoginSecurityController.php"
backup_and_copy "$SRC_DIR/LoginSecurityMiddleware.php" "$APP_DIR/app/Http/Middleware/LoginSecurityMiddleware.php"
backup_and_copy "$SRC_DIR/PerformanceTimingMiddleware.php" "$APP_DIR/app/Http/Middleware/PerformanceTimingMiddleware.php"
backup_and_copy "$SRC_DIR/OperationAuditController.php" "$APP_DIR/app/Http/Controllers/Gallery/OperationAuditController.php"
backup_and_copy "$SRC_DIR/OperationAuditMiddleware.php" "$APP_DIR/app/Http/Middleware/OperationAuditMiddleware.php"
cp -R "$GALLERY_EXTENSION_SRC/app/Support" "$APP_DIR/app/GalleryExtension/"
cp -R "$GALLERY_EXTENSION_SRC/app/Services" "$APP_DIR/app/GalleryExtension/"
cp -R "$GALLERY_EXTENSION_SRC/app/Http/Requests/." "$APP_DIR/app/Http/Requests/"
cp -R "$GALLERY_EXTENSION_SRC/app/Http/Resources/." "$APP_DIR/app/Http/Resources/"
cp -R "$GALLERY_EXTENSION_SRC/app/Http/Middleware/." "$APP_DIR/app/Http/Middleware/"
cp -R "$GALLERY_EXTENSION_SRC/app/Models/." "$APP_DIR/app/Models/"
cp -R "$GALLERY_EXTENSION_SRC/app/Jobs/." "$APP_DIR/app/Jobs/"
cp -R "$GALLERY_EXTENSION_SRC/app/Console/Commands/." "$COMMAND_DIR/"
cp -R "$GALLERY_EXTENSION_SRC/database/migrations/." "$GALLERY_MIGRATION_DIR/"
backup_and_copy "$GALLERY_EXTENSION_SRC/routes/gallery.php" "$GALLERY_ROUTE_DIR/gallery.php"
backup_and_copy "$SRC_DIR/robots.txt" "$APP_DIR/public/robots.txt"
if [ -f "$SRC_DIR/default.conf" ]; then
	backup_and_copy "$SRC_DIR/default.conf" "$NGINX_SITE_CONF"
fi

RENDERED_VIEW="$VIEW_DIR/.vueapp.blade.php.rendered"
render_asset_template "$SRC_DIR/vueapp.blade.php" "$RENDERED_VIEW"
backup_and_copy "$RENDERED_VIEW" "$VIEW_DIR/vueapp.blade.php"
rm -f "$RENDERED_VIEW"
if [ -f "$SRC_DIR/meta.blade.php" ] && { [ ! -f "$COMPONENT_DIR/meta.blade.php" ] || [ "${INSTALL_CUSTOM_META:-0}" = "1" ]; }; then
	backup_and_copy "$SRC_DIR/meta.blade.php" "$COMPONENT_DIR/meta.blade.php"
fi
if [ -f "$SRC_DIR/error.blade.php" ]; then
	backup_and_copy "$SRC_DIR/error.blade.php" "$ERROR_VIEW_DIR/error.blade.php"
fi

touch "$PHP_LOCAL_INI"
ensure_env_value "$ENV_FILE" "APP_ENV" "production"
ensure_env_value "$ENV_FILE" "APP_DEBUG" "false"
ensure_env_value "$ENV_FILE" "QUEUE_CONNECTION" "database"
ensure_env_value "$ENV_FILE" "SESSION_SECURE_COOKIE" "true"
ensure_env_value "$ENV_FILE" "SESSION_SAME_SITE" "lax"
ensure_env_value "$ENV_FILE" "SECURITY_HEADER_CSP_CONNECT_SRC" ""
ensure_env_value "$ENV_FILE" "SECURITY_HEADER_CSP_IMG_SRC" ""
sed -i '/^COS_[A-Z0-9_]*=/d; /^DASHSCOPE_API_KEY=/d; /^DASHSCOPE_BASE_URL=/d; /^QWEN_MODEL=/d; /^GALLERY_ACTIVITY_REPORT_EMAIL=/d' "$ENV_FILE"
for persistent_env in /config/.env /config/lychee.env; do
	if [ -f "$persistent_env" ]; then
		ensure_env_value "$persistent_env" "SECURITY_HEADER_CSP_CONNECT_SRC" ""
		ensure_env_value "$persistent_env" "SECURITY_HEADER_CSP_IMG_SRC" ""
		sed -i '/^COS_[A-Z0-9_]*=/d' "$persistent_env"
		sed -i '/^DASHSCOPE_API_KEY=/d; /^DASHSCOPE_BASE_URL=/d; /^QWEN_MODEL=/d' "$persistent_env"
	fi
done
ensure_ini_value "$PHP_LOCAL_INI" "expose_php" "Off"
ensure_ini_value "$PHP_LOCAL_INI" "upload_max_filesize" "0"
ensure_ini_value "$PHP_LOCAL_INI" "post_max_size" "0"
ensure_ini_value "$PHP_LOCAL_INI" "max_file_uploads" "100000"
ensure_ini_value "$PHP_LOCAL_INI" "max_multipart_body_parts" "120000"
ensure_ini_value "$PHP_LOCAL_INI" "max_execution_time" "300"
ensure_ini_value "$PHP_LOCAL_INI" "max_input_time" "300"
ensure_ini_value "$PHP_LOCAL_INI" "memory_limit" "768M"
ensure_ini_value "$PHP_LOCAL_INI" "session.cookie_secure" "1"
ensure_ini_value "$PHP_LOCAL_INI" "session.cookie_httponly" "1"
ensure_ini_value "$PHP_LOCAL_INI" "session.cookie_samesite" "Lax"

LOGIN_EVENTS_MARKER="// Codex account login events"
if ! grep -q "$LOGIN_EVENTS_MARKER" "$APP_DIR/routes/api_v2.php"; then
	cat >> "$APP_DIR/routes/api_v2.php" <<'ROUTES'

// Codex account login events
Route::get('/AccountLoginEvents', [Gallery\AccountLoginController::class, 'index'])->middleware(['login_required:always']);
Route::post('/AccountLoginEvents', [Gallery\AccountLoginController::class, 'store'])->middleware(['login_required:always']);
ROUTES
fi

# Remove legacy chat and emotion assistant routes and controllers from
# installations that still carry them.
sed -i \
	-e '/Codex private chat/,+3d' \
	-e '/Codex private chat images/,+2d' \
	-e '/Codex emotion assistant/,+2d' \
	-e '/Codex emotion assistant conversations/,+2d' \
	-e '/Codex emotion assistant settings/,+3d' \
	"$APP_DIR/routes/api_v2.php"
rm -f \
	"$APP_DIR/app/Http/Controllers/Gallery/PrivateChatController.php" \
	"$APP_DIR/app/Http/Controllers/Gallery/EmotionAssistantController.php" \
	"$APP_DIR/app/Http/Controllers/Gallery/EmotionAssistantConversationController.php" \
	"$APP_DIR/app/Http/Controllers/Gallery/EmotionAssistantSettingsController.php" \
	"$SRC_DIR/PrivateChatController.php" \
	"$SRC_DIR/EmotionAssistantController.php" \
	"$SRC_DIR/EmotionAssistantConversationController.php" \
	"$SRC_DIR/EmotionAssistantSettingsController.php"

ACTIVITIES_MARKER="// Codex photo activities"
if ! grep -q "$ACTIVITIES_MARKER" "$APP_DIR/routes/api_v2.php"; then
	cat >> "$APP_DIR/routes/api_v2.php" <<'ROUTES'

// Codex photo activities
Route::get('/Activities', [Gallery\ActivityController::class, 'index'])->middleware(['login_required:always']);
Route::post('/Activities', [Gallery\ActivityController::class, 'store'])->middleware(['login_required:always'])->withoutMiddleware(['content_type:json']);
ROUTES
fi
ACTIVITY_EXTENDED_MARKER="// Codex photo activity extensions"
if ! grep -q "$ACTIVITY_EXTENDED_MARKER" "$APP_DIR/routes/api_v2.php"; then
	cat >> "$APP_DIR/routes/api_v2.php" <<'ROUTES'

// Codex photo activity extensions
Route::get('/Activities/{activityId}/Images', [Gallery\ActivityController::class, 'images'])->middleware(['login_required:always']);
Route::get('/ActivityImages/{activityId}/{imageId}', [Gallery\ActivityController::class, 'image'])->middleware(['login_required:always'])->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class, 'accept_content_type:json', 'content_type:json']);
Route::delete('/Activities/{activityId}', [Gallery\ActivityController::class, 'destroy'])->middleware(['login_required:always']);
ROUTES
fi
ACTIVITY_COMMENTS_MARKER="// Codex photo activity comments"
if ! grep -q "$ACTIVITY_COMMENTS_MARKER" "$APP_DIR/routes/api_v2.php"; then
	cat >> "$APP_DIR/routes/api_v2.php" <<'ROUTES'

// Codex photo activity comments
Route::get('/Activities/{activityId}/Comments', [Gallery\ActivityController::class, 'comments'])->middleware(['login_required:always']);
Route::post('/Activities/{activityId}/Comments', [Gallery\ActivityController::class, 'storeComment'])->middleware(['login_required:always']);
ROUTES
fi
# Activity posts accept JSON metadata after direct COS uploads, while retaining
# multipart compatibility for older locally stored activity pictures.
sed -i "\\|Route::post('/Activities'|c\\
Route::post('/Activities', [Gallery\\\\ActivityController::class, 'store'])->middleware(['login_required:always'])->withoutMiddleware(['content_type:json']);" "$APP_DIR/routes/api_v2.php"
ROUTES_FILE="$APP_DIR/routes/api_v2.php" php <<'PHP'
<?php
$file = getenv('ROUTES_FILE');
$contents = file_get_contents($file);
$replacement = <<<'ROUTE'
Route::get('/ActivityImages/{activityId}/{imageId}', [Gallery\ActivityController::class, 'image'])->middleware(['login_required:always'])->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class, 'accept_content_type:json', 'content_type:json']);
ROUTE;
$updated = preg_replace("/^Route::get\('\/ActivityImages\/\{activityId\}\/\{imageId\}'.*$/m", $replacement, $contents, 1, $count);
if ($updated === null) {
	fwrite(STDERR, "Unable to update the ActivityImages route.\n");
	exit(1);
}
// The standalone Gallery route file has already removed this legacy route on
// repeat installs. In that case there is nothing to migrate.
if ($count === 1) {
	file_put_contents($file, $updated);
}
PHP

LOGIN_SECURITY_MARKER="// Codex login device security"
if ! grep -q "$LOGIN_SECURITY_MARKER" "$APP_DIR/routes/api_v2.php"; then
	cat >> "$APP_DIR/routes/api_v2.php" <<'ROUTES'

// Codex login device security
Route::get('/LoginSecurity', [Gallery\LoginSecurityController::class, 'index'])->middleware(['login_required:always']);
Route::post('/LoginSecurity::trust', [Gallery\LoginSecurityController::class, 'trustCurrentDevice'])->middleware(['login_required:always']);
Route::post('/LoginSecurity::desktopProtection', [Gallery\LoginSecurityController::class, 'setDesktopProtection'])->middleware(['login_required:always']);
Route::post('/LoginSecurity::revoke/{id}', [Gallery\LoginSecurityController::class, 'revokeDevice'])->middleware(['login_required:always']);
ROUTES
fi

OPERATION_AUDIT_MARKER="// Codex operation audit events"
if ! grep -q "$OPERATION_AUDIT_MARKER" "$APP_DIR/routes/api_v2.php"; then
	cat >> "$APP_DIR/routes/api_v2.php" <<'ROUTES'

// Codex operation audit events
Route::get('/OperationAuditEvents', [Gallery\OperationAuditController::class, 'index'])->middleware(['login_required:always']);
ROUTES
fi

# Keep the Lychee API unchanged while mounting all custom endpoints from one
# route file. Remove routes from earlier installer versions before loading it.
ROUTES_FILE="$APP_DIR/routes/api_v2.php" GALLERY_ROUTE_FILE="$GALLERY_ROUTE_DIR/gallery.php" php <<'PHP'
<?php
$file = getenv('ROUTES_FILE');
$contents = file_get_contents($file);
$controllers = '(?:AlbumInviteController|AlbumPhotoFeedController|AccountLoginController|ActivityController|MotionPhotoController|LoginSecurityController|OperationAuditController)';
$contents = preg_replace('/^Route::(?:get|post|delete)\([^\n]*\[Gallery\\\\' . $controllers . '::class,[^\n]*\n/m', '', $contents) ?? $contents;
$require = "require_once __DIR__ . '/gallery.php';";
if (!str_contains($contents, $require)) {
	$contents .= "\n// Codex Gallery extension routes\n" . $require . "\n";
}
file_put_contents($file, $contents);
PHP

KERNEL_FILE="$APP_DIR/app/Http/Kernel.php"
if [ ! -f "$KERNEL_FILE" ]; then
	echo "Missing Laravel kernel: $KERNEL_FILE" >&2
	exit 1
fi

if ! grep -q 'LoginSecurityMiddleware' "$KERNEL_FILE"; then
	cp "$KERNEL_FILE" "$BACKUP_DIR/Kernel.php.$BACKUP_STAMP.bak"
	KERNEL_FILE="$KERNEL_FILE" php <<'PHP'
<?php
$file = getenv('KERNEL_FILE');
$contents = file_get_contents($file);
$class = "\\App\\Http\\Middleware\\LoginSecurityMiddleware::class,";
$apiGroupPattern = '/[\'\"]api[\'\"]\\s*=>\\s*\\[/';
if (preg_match($apiGroupPattern, $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
	fwrite(STDERR, "Unable to find the API middleware group in {$file}.\n");
	exit(1);
}
$apiGroupOffset = $match[0][1];
$marker = '\\Illuminate\\Cookie\\Middleware\\AddQueuedCookiesToResponse::class,';
$position = strpos($contents, $marker, $apiGroupOffset);
if ($position === false) {
	$marker = '\\App\\Http\\Middleware\\VerifyCsrfToken::class,';
	$position = strpos($contents, $marker, $apiGroupOffset);
}
if ($position === false) {
	fwrite(STDERR, "Unable to find API cookie middleware in {$file}.\n");
	exit(1);
}
$lineEnd = strpos($contents, "\n", $position);
if ($lineEnd === false) {
	fwrite(STDERR, "Unable to update {$file}.\n");
	exit(1);
}
$indentStart = strrpos(substr($contents, 0, $position), "\n") + 1;
$indent = substr($contents, $indentStart, $position - $indentStart);
$contents = substr_replace($contents, "\n" . $indent . $class, $lineEnd, 0);
file_put_contents($file, $contents);
PHP
fi

if ! grep -q 'OperationAuditMiddleware' "$KERNEL_FILE"; then
	cp "$KERNEL_FILE" "$BACKUP_DIR/Kernel.php.$BACKUP_STAMP.bak"
	KERNEL_FILE="$KERNEL_FILE" php <<'PHP'
<?php
$file = getenv('KERNEL_FILE');
$contents = file_get_contents($file);
$class = "\\App\\Http\\Middleware\\OperationAuditMiddleware::class,";
$apiGroupPattern = '/[\'\"]api[\'\"]\\s*=>\\s*\\[/';
if (preg_match($apiGroupPattern, $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
	fwrite(STDERR, "Unable to find the API middleware group in {$file}.\n");
	exit(1);
}
$apiGroupOffset = $match[0][1];
$marker = '\\App\\Http\\Middleware\\LoginSecurityMiddleware::class,';
$position = strpos($contents, $marker, $apiGroupOffset);
if ($position === false) {
	fwrite(STDERR, "Unable to find LoginSecurityMiddleware in the API middleware group in {$file}.\n");
	exit(1);
}
$lineEnd = strpos($contents, "\n", $position);
if ($lineEnd === false) {
	fwrite(STDERR, "Unable to update {$file}.\n");
	exit(1);
}
$indentStart = strrpos(substr($contents, 0, $position), "\n") + 1;
$indent = substr($contents, $indentStart, $position - $indentStart);
$contents = substr_replace($contents, "\n" . $indent . $class, $lineEnd, 0);
file_put_contents($file, $contents);
PHP
fi

if ! grep -q 'PerformanceTimingMiddleware' "$KERNEL_FILE"; then
	cp "$KERNEL_FILE" "$BACKUP_DIR/Kernel.php.$BACKUP_STAMP.bak"
	KERNEL_FILE="$KERNEL_FILE" php <<'PHP'
<?php
$file = getenv('KERNEL_FILE');
$contents = file_get_contents($file);
$class = "\\App\\Http\\Middleware\\PerformanceTimingMiddleware::class,";
$apiGroupPattern = '/[\'\"]api[\'\"]\\s*=>\\s*\\[/';
if (preg_match($apiGroupPattern, $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
	fwrite(STDERR, "Unable to find the API middleware group in {$file}.\n");
	exit(1);
}
$apiGroupOffset = $match[0][1];
$marker = '\\App\\Http\\Middleware\\OperationAuditMiddleware::class,';
$position = strpos($contents, $marker, $apiGroupOffset);
if ($position === false) {
	fwrite(STDERR, "Unable to find OperationAuditMiddleware in the API middleware group in {$file}.\n");
	exit(1);
}
$lineEnd = strpos($contents, "\n", $position);
if ($lineEnd === false) {
	fwrite(STDERR, "Unable to update {$file}.\n");
	exit(1);
}
$indentStart = strrpos(substr($contents, 0, $position), "\n") + 1;
$indent = substr($contents, $indentStart, $position - $indentStart);
$contents = substr_replace($contents, "\n" . $indent . $class, $lineEnd, 0);
file_put_contents($file, $contents);
PHP
fi

CONSOLE_KERNEL_FILE="$APP_DIR/app/Console/Kernel.php"
if [ -f "$CONSOLE_KERNEL_FILE" ]; then
	# Drop the retired daily activity report schedule left by older installs.
	sed -i '/gallery:activity-report/d' "$CONSOLE_KERNEL_FILE"
fi

cd "$APP_DIR"
# All Gallery schema changes are tracked in gallery-extension/database/migrations.
php artisan migrate --force --path=database/migrations/gallery-extension
php artisan optimize:clear >/dev/null 2>&1 || true

# Media optimization must not run during the upload request. Start one worker
# on install and container startup; the process check keeps repeat installs safe.
if ! ps -ef | grep '[q]ueue:work' >/dev/null 2>&1; then
	nohup php artisan queue:work --tries=3 --timeout=900 --sleep=3 >> "$APP_DIR/storage/logs/gallery-queue-worker.log" 2>&1 &
fi

if [ -d /config/www ]; then
	chown -R abc:abc /config/www 2>/dev/null || true
	chmod -R u+rwX,g+rwX /config/www 2>/dev/null || true
fi
chown -R abc:abc "$DEST_DIR" "$ADMIN_DEST_DIR" "$APP_DIR/bootstrap/cache" "$APP_DIR/storage" "$PHP_DIR" 2>/dev/null || true
chmod -R u+rwX,g+rwX "$DEST_DIR" "$ADMIN_DEST_DIR" "$APP_DIR/bootstrap/cache" "$APP_DIR/storage" "$PHP_DIR" 2>/dev/null || true
