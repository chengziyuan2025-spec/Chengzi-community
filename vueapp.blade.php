<!DOCTYPE HTML>
<html
	@if(app()->getLocale() == 'ar' || app()->getLocale() == 'fa')
	dir="rtl"
	@else
	dir="ltr"
	@endif
	lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
	<meta name="generator" content="Chengzi v7">
	<title>cw小窝</title>
	<link rel="apple-touch-icon" href="/custom-gallery/apple-touch-icon.png">
	<link rel="preload" href="/custom-gallery/app.js?v=__ASSET_VERSION__" as="script">
	<link rel="preload" href="/custom-gallery/styles.css?v=__ASSET_VERSION__" as="style">
	<link rel="stylesheet" href="/custom-gallery/styles.css?v=__ASSET_VERSION__">
</head>
@if(resolve(\App\Repositories\ConfigManager::class)->getValueAsBool('dark_mode_enabled'))
<body class="antialiased dark">
@else
<body class="antialiased">
@endif
	<main>
		<section id="session-loading" class="session-loading" aria-live="polite">
			<span class="session-spinner" aria-hidden="true"></span>
			<span class="sr-only">正在恢复会话</span>
		</section>

		<section id="login-view" class="login-shell" hidden>
			<form id="login-form" class="login-panel">
				<h1>登录相册</h1>
				<p>请输入 Chengzi 账号后访问相册内容。</p>
				<label>
					<span>用户名</span>
					<input id="login-username" name="username" autocomplete="username" required>
				</label>
				<label>
					<span>密码</span>
					<input id="login-password" name="password" type="password" autocomplete="current-password" required>
				</label>
				<label class="check-row">
					<input id="login-remember" name="remember_me" type="checkbox">
					<span>保持登录</span>
				</label>
				<button class="btn btn-primary" type="submit">登录</button>
				<p id="login-error" class="form-error" role="alert"></p>
			</form>
		</section>

		<section id="profile-view" hidden>
			<div class="profile-page animate-up">
				<div class="profile-heading">
					<h1 class="view-title">我的</h1>
				</div>
				<section class="profile-card" aria-label="账号信息">
					<div id="profile-avatar" class="profile-avatar" aria-hidden="true"></div>
					<div class="profile-copy">
						<strong id="profile-name"></strong>
						<span id="profile-username"></span>
					</div>
				</section>
				<section class="profile-stats" aria-label="相册统计">
					<div class="profile-stat"><strong id="stat-albums">--</strong><span>个相册</span></div>
					<div class="profile-stat"><strong id="stat-photos">--</strong><span>张照片</span></div>
				</section>
				<button class="btn btn-danger profile-logout" type="button" data-action="logout">退出登录</button>
			</div>
		</section>

		<section id="activities-view" hidden>
			<nav class="content-switcher animate-up" aria-label="内容切换" role="tablist">
				<button class="content-switcher-item" type="button" data-action="open-activities" data-switch-view="activities-view" role="tab">动态</button>
				<button class="content-switcher-item" type="button" data-action="open-albums" data-switch-view="albums-view" role="tab">相册</button>
			</nav>
			<div id="activities-loading" class="activity-loading">正在加载动态...</div>
			<div id="activities-feed" class="activities-feed"></div>
			<button id="activities-load-more" class="activity-load-more" type="button" data-action="load-more-activities" hidden>加载更多动态</button>
			<div id="activities-empty" class="empty-state" hidden>还没有动态。发布第一条，留下今天的画面。</div>
		</section>

		<section id="albums-view" hidden>
			<nav class="content-switcher animate-up" aria-label="内容切换" role="tablist">
				<button class="content-switcher-item" type="button" data-action="open-activities" data-switch-view="activities-view" role="tab">动态</button>
				<button class="content-switcher-item" type="button" data-action="open-albums" data-switch-view="albums-view" role="tab">相册</button>
			</nav>
			<div class="view-header animate-up">
				<div>
					<h1 class="view-title">全部相册</h1>
				</div>
				<div class="toolbar">
					<button class="btn" type="button" data-action="toggle-join-code" hidden>加入共享相册</button>
				</div>
			</div>
			<form id="join-album-form" class="share-code-panel animate-up" hidden>
				<label>
					<span>共享码</span>
					<input id="join-code-input" name="code" maxlength="32" autocomplete="off" inputmode="latin" placeholder="例如 wbch234">
				</label>
				<button class="btn btn-primary" type="submit">加入</button>
			</form>
			<div class="grid-container" id="albums-grid"></div>
			<div class="empty-state" id="albums-empty" hidden>还没有可访问的相册。</div>
		</section>

		<section id="activity-compose-view" class="compose-view" hidden>
			<div class="compose-page-shell">
				<div class="compose-topline">
					<button class="back-link compose-back" type="button" data-action="close-compose" aria-label="返回动态"><span aria-hidden="true">&larr;</span></button>
					<p class="compose-kicker">新动态</p>
				</div>
				<div class="compose-heading">
					<h1 class="compose-title">分享此刻</h1>
				</div>
				<section id="activity-composer" class="activity-composer compose-form-surface">
					<form id="activity-form">
						<label><span>标题</span><input id="activity-title" name="title" maxlength="120" autocomplete="off" placeholder="添加标题"></label>
						<label><span>正文</span><textarea id="activity-body" name="body" maxlength="5000" rows="3" placeholder="写点什么"></textarea></label>
						<input id="activity-images" type="file" accept="image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,video/mp4,video/quicktime,video/webm" multiple hidden>
						<div id="activity-image-preview" class="activity-image-preview"></div>
						<div class="activity-form-actions"><button class="btn" type="button" data-action="pick-activity-images">添加照片</button><span id="activity-image-count" class="card-meta" aria-live="polite"></span><button id="activity-submit" class="btn btn-primary" type="submit">发布</button></div>
					</form>
				</section>
			</div>
		</section>

		<section id="album-create-view" class="compose-view" hidden>
			<div class="compose-page-shell compose-page-shell-compact">
				<button class="back-link compose-back" type="button" data-action="close-compose" aria-label="返回相册"><span aria-hidden="true">&larr;</span></button>
				<div class="compose-heading">
					<p class="eyebrow">新相册</p>
					<h1 class="compose-title">创建一个新空间</h1>
					<p class="compose-subtitle">为即将发生的故事，先留出一个位置。</p>
				</div>
				<form id="create-album-form" class="compose-form-surface album-create-form">
					<label><span>相册名称</span><input id="create-album-title" name="title" maxlength="100" autocomplete="off" placeholder="输入新相册名称"></label>
					<div class="compose-form-actions"><span class="card-meta">名称最多 100 个字符</span><button class="btn btn-primary" type="submit">创建相册</button></div>
				</form>
			</div>
		</section>


		<section id="photos-view" hidden>
			<div class="photos-titlebar">
				<button class="back-link" type="button" data-action="open-albums" aria-label="返回相册"><span aria-hidden="true">&larr;</span></button>
				<h1 class="view-title" id="current-album-title">相册</h1>
				<div class="toolbar">
					<button class="btn" type="button" data-action="generate-share-code" hidden>生成共享码</button>
					<button class="btn btn-primary" type="button" data-action="pick-files" hidden>上传照片</button>
					<button class="btn btn-danger" type="button" data-action="delete-album" hidden>删除相册</button>
					<input type="file" id="file-input" accept="image/*,video/*" multiple>
				</div>
			</div>
			<div id="delete-album-confirm" class="share-code-panel" hidden>
				<span>确定要删除这个相册吗？其内的照片也会被删除。</span>
				<button class="btn btn-danger" type="button" data-action="confirm-delete-album">确认删除</button>
				<button class="btn" type="button" data-action="cancel-delete-album">取消</button>
			</div>
			<div id="share-code-panel" class="share-code-panel" hidden>
				<div>
					<span class="share-code-label">共享码</span>
					<strong id="share-code-value" class="share-code-value"></strong>
				</div>
				<button class="btn" type="button" data-action="copy-share-code">复制</button>
			</div>
			<div id="upload-status" class="upload-status" hidden></div>
			<section id="new-photos-section" class="new-photos-section" aria-labelledby="new-photos-title" hidden>
				<div class="new-photos-heading">
					<div><p class="eyebrow">新上传的照片</p><h2 id="new-photos-title">对方新上传</h2></div>
					<p id="new-photo-count"></p>
				</div>
				<div class="grid-container new-photos-grid" id="new-photos-grid"></div>
			</section>
			<div class="grid-container" id="photos-grid"></div>
			<button id="photos-load-more" class="activity-load-more" type="button" data-action="load-more-photos" hidden>加载更多照片</button>
			<div class="empty-state" id="photos-empty" hidden>这个相册还没有照片。</div>
		</section>
	</main>

	<nav id="bottom-navigation" class="bottom-navigation" aria-label="主导航" hidden>
		<button class="bottom-navigation-item" type="button" data-action="open-activities" data-nav-view="activities-view">
			<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/></svg>
			<span>首页</span>
		</button>
		<button id="bottom-fab" class="bottom-fab" type="button" data-action="context-add" aria-label="发布动态">
			<span aria-hidden="true">+</span>
		</button>
		<button class="bottom-navigation-item" type="button" data-action="open-profile" data-nav-view="profile-view">
			<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="12" cy="8" r="3.5"/><path d="M4.5 21a7.5 7.5 0 0 1 15 0"/></svg>
			<span>我的</span>
		</button>
	</nav>

	<div id="activity-image-modal" class="activity-image-modal" role="dialog" aria-modal="true" aria-label="动态照片预览" aria-hidden="true" hidden>
		<button id="activity-image-close" class="activity-image-close" type="button" data-action="close-activity-image" aria-label="关闭预览">&times;</button>
		<img id="activity-lightbox-image" alt="动态照片预览">
	</div>

	<div id="activity-detail-modal" class="activity-detail-modal" role="dialog" aria-modal="true" aria-label="动态详情" aria-hidden="true" hidden>
		<article class="activity-detail-panel">
			<button id="activity-detail-close" class="back-link activity-detail-back" type="button" data-action="close-activity-detail" aria-label="返回动态"><span aria-hidden="true">&larr;</span></button>
			<div id="activity-detail-media" class="activity-detail-media"></div>
			<div class="activity-detail-copy">
				<div class="activity-detail-author"><span id="activity-detail-avatar" class="activity-avatar" aria-hidden="true"></span><div><strong id="activity-detail-author-name"></strong><time id="activity-detail-time"></time></div></div>
				<h2 id="activity-detail-title" class="activity-detail-title"></h2>
				<p id="activity-detail-body" class="activity-detail-body"></p>
				<div id="activity-detail-thumbs" class="activity-detail-thumbs" aria-label="动态图片"></div>
				<section class="activity-detail-comments" aria-label="动态评论">
					<div class="activity-comments-header"><strong>评论</strong><span id="activity-comment-count"></span></div>
					<div id="activity-comment-list" class="activity-comment-list"></div>
					<form class="activity-comment-input-area" id="activity-comment-form">
						<div class="activity-reply-target" id="activity-reply-target" hidden></div>
						<input type="text" id="activity-comment-input" maxlength="500" autocomplete="off" placeholder="说点什么...">
						<button class="btn btn-primary" type="submit">发送</button>
					</form>
				</section>
			</div>
		</article>
	</div>

	<div class="modal-overlay" id="photo-modal" aria-hidden="true">
		<div class="lightbox-content" role="dialog" aria-modal="true" aria-label="照片预览">
			<button class="close-btn" type="button" data-action="close-photo" aria-label="关闭">x</button>
			<div class="lightbox-img">
				<img id="lightbox-image" src="" alt="">
			</div>
			<section class="photo-note-panel" aria-label="照片标题">
				<form class="photo-title-form" id="photo-title-form">
					<input type="text" id="photo-title-input" maxlength="100" autocomplete="off" placeholder="标题" aria-label="照片标题">
				</form>
				<div class="photo-note-meta">
					<span id="photo-note-date"></span>
				</div>
			</section>
			<aside class="lightbox-sidebar">
				<div class="lightbox-header">
					<strong id="lightbox-title">评论区</strong>
					<span id="comment-count"></span>
				</div>
				<div class="comment-list" id="comment-list"></div>
				<form class="comment-input-area" id="comment-form">
					<div class="reply-target" id="reply-target" hidden></div>
					<input type="text" id="comment-input" maxlength="500" autocomplete="off" placeholder="说点什么...">
					<button class="btn btn-primary" type="submit">发送</button>
				</form>
			</aside>
		</div>
	</div>

	<div class="toast" id="toast" hidden></div>
	<script src="/custom-gallery/app.js?v=__ASSET_VERSION__" defer></script>
</body>
</html>
