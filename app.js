(function () {
	"use strict";

	var API = "/api/v2/";
	var DATA_CACHE_KEY = "chengzi-community-data-cache-v4";
	var DEFAULT_READ_TIMEOUT_MS = 8000;
	var SESSION_TIMEOUT_MS = 5000;
	var MAX_ACTIVITY_FILES = 20;
	var MAX_IMAGE_BYTES = 25 * 1024 * 1024;
	var MAX_VIDEO_BYTES = 250 * 1024 * 1024;
	var MAX_UPLOAD_BYTES = 500 * 1024 * 1024;
	var sessionPrimePromise = null;
	var state = {
		user: null,
		activityFiles: [],
		activityDraftTitle: "",
		activityDraftBody: "",
		composeReturnView: "activities-view",
		activityBusy: false,
		activityUploadRequest: null,
		activities: [],
		activitiesNextCursor: null,
		activitiesHasMore: false,
		activitiesLoadingMore: false,
		activitiesLoaded: false,
		activitiesRequest: null,
		activityComments: new Map(),
		activityCommentsRequests: new Map(),
		activityCommentReplyTarget: null,
		activityCommentBusy: false,
		cacheGeneration: 0,
		activitiesSignature: "",
		activitiesNeedsRevalidate: false,
		persistentCacheUserId: "",
		currentActivity: null,
		activityFilter: "all",
		activityScope: "all",
		registrationMode: "invite",
		setupRequired: false,
		deleteActivityId: null,
		deleteReturnLayer: null,
		focusReturn: {},
		authMode: "login",
	};

	function $(id) {
		return document.getElementById(id);
	}

	function csrfToken() {
		var cookie = document.cookie.split(";").find(function (row) {
			return /^\s*(X-)?[XC]SRF-TOKEN\s*=/.test(row);
		});
		if (cookie === undefined) {
			return "";
		}
		var encoded = cookie.split("=").slice(1).join("=").trim();
		try {
			return decodeURIComponent(encoded);
		} catch (_error) {
			return encoded;
		}
	}

	function headers(json) {
		var result = {
			Accept: "application/json",
			"Content-Type": "application/json",
			"X-Requested-With": "XMLHttpRequest"
		};
		var token = csrfToken();
		if (token) {
			result["X-XSRF-TOKEN"] = token;
		}
		return result;
	}

	function parseJson(response) {
		return response.text().then(function (text) {
			return text ? JSON.parse(text) : null;
		});
	}

	function fetchWithTimeout(url, options, timeoutMs) {
		if (!timeoutMs || !window.AbortController) {
			return fetch(url, options);
		}

		var controller = new AbortController();
		var requestOptions = Object.assign({}, options, { signal: controller.signal });
		var timeoutId = window.setTimeout(function () {
			controller.abort();
		}, timeoutMs);

		return fetch(url, requestOptions).catch(function (error) {
			if (controller.signal.aborted && error && error.name === "AbortError") {
				throw new Error("请求超时，请检查网络后重试");
			}
			throw error;
		}).finally(function () {
			window.clearTimeout(timeoutId);
		});
	}

	function api(path, options) {
		var opts = Object.assign({
			credentials: "same-origin",
			headers: headers(options && options.body !== undefined && !(options.body instanceof FormData))
		}, options || {});
		var method = String(opts.method || "GET").toUpperCase();
		var timeoutMs = opts.timeoutMs === undefined
			? (method === "GET" ? DEFAULT_READ_TIMEOUT_MS : 0)
			: opts.timeoutMs;
		delete opts.timeoutMs;

		if (opts.body && !(opts.body instanceof FormData) && typeof opts.body !== "string") {
			opts.body = JSON.stringify(opts.body);
		}
		if (opts.body instanceof FormData) {
			delete opts.headers["Content-Type"];
		}

		return fetchWithTimeout(API + path, opts, timeoutMs).then(function (response) {
			if (response.status === 401 || response.status === 419) {
				state.user = null;
				showLogin();
			}
			if (!response.ok) {
				return parseJson(response).catch(function () {
					return null;
				}).then(function (payload) {
					throw new Error(apiErrorMessage(payload, "请求失败：" + response.status));
				});
			}
			return parseJson(response).then(resource).catch(function () { return null; });
		});
	}

	function primeSession() {
		if (csrfToken()) {
			return Promise.resolve();
		}
		if (sessionPrimePromise) {
			return sessionPrimePromise;
		}
		sessionPrimePromise = fetchWithTimeout("/", {
			credentials: "same-origin",
			headers: {
				Accept: "text/html",
				"X-Requested-With": "XMLHttpRequest"
			}
		}, SESSION_TIMEOUT_MS).then(function () {
			return null;
		}).catch(function () {
			return null;
		}).finally(function () {
			sessionPrimePromise = null;
		});
		return sessionPrimePromise;
	}

	function resource(payload) {
		return payload && payload.data !== undefined ? payload.data : payload;
	}

	function apiErrorMessage(payload, fallback) {
		var source = payload && payload.error ? payload.error : payload;
		var details = source && (source.errors || source.details);
		if (details && typeof details === "object") {
			var keys = Object.keys(details);
			if (keys.length) {
				var value = details[keys[0]];
				return Array.isArray(value) ? String(value[0]) : String(value);
			}
		}
		return source && source.message ? String(source.message) : (typeof source === "string" ? source : fallback);
	}

	function showToast(message) {
		var el = $("toast");
		el.textContent = message;
		el.hidden = false;
		clearTimeout(showToast.timer);
		showToast.timer = setTimeout(function () {
			el.hidden = true;
		}, 3200);
	}

	function showOnly(viewId) {
		document.body.dataset.view = viewId;
		["session-loading", "login-view", "profile-view", "activities-view", "activity-compose-view"].forEach(function (id) {
			var view = $(id);
			if (view) {
				view.hidden = id !== viewId;
				if (id === viewId) {
					view.classList.remove("micro-view-enter");
					window.requestAnimationFrame(function () {
						view.classList.add("micro-view-enter");
					});
				}
			}
		});
		syncBottomNavigation(viewId);
	}

	function syncBottomNavigation(viewId) {
		var navigation = $("bottom-navigation");
		if (!navigation) {
			return;
		}
		var composeView = viewId === "activity-compose-view";
		var visible = !!state.user && viewId !== "session-loading" && viewId !== "login-view" && !composeView;
		navigation.hidden = !visible;
		var fab = $("bottom-fab");
		var fabVisible = false;
		if (fab) {
			fabVisible = visible && viewId === "activities-view";
			fab.hidden = !fabVisible;
			fab.setAttribute("aria-label", "发布动态");
		}
		navigation.classList.toggle("has-context-action", fabVisible);
		Array.prototype.forEach.call(navigation.querySelectorAll("[data-nav-view]"), function (button) {
			var isActive = button.dataset.navView === viewId;
			button.classList.toggle("is-active", isActive);
			if (isActive) {
				button.setAttribute("aria-current", "page");
			} else {
				button.removeAttribute("aria-current");
			}
		});
	}

	function showLogin() {
		clearSessionCaches();
		setAuthMode("login");
		showOnly("login-view");
		renderProfile();
	}

	function clearSessionCaches() {
		state.cacheGeneration += 1;
		state.activities = [];
		state.activitiesNextCursor = null;
		state.activitiesHasMore = false;
		state.activitiesLoaded = false;
		state.activitiesRequest = null;
		state.activitiesSignature = "";
		state.activitiesNeedsRevalidate = false;
		state.activityComments.clear();
		state.activityCommentsRequests.clear();
		state.activityCommentReplyTarget = null;
		state.activityCommentBusy = false;
		state.persistentCacheUserId = "";
		state.currentActivity = null;
		state.activityFilter = "all";
		state.activityScope = "all";
		state.deleteActivityId = null;
	}

	function pushUiLayer(layer) {
		state.focusReturn[layer] = document.activeElement;
		window.history.pushState({ communityLayer: layer }, "", window.location.href);
	}

	function restoreLayerFocus(layer) {
		var target = state.focusReturn[layer];
		delete state.focusReturn[layer];
		if (target && document.contains(target) && typeof target.focus === "function") {
			target.focus();
		}
	}

	function requestHistoryClose(layer, closeDirect) {
		if (window.history.state && window.history.state.communityLayer === layer) {
			window.history.back();
			return;
		}
		closeDirect();
	}

	function trapDialogFocus(event, dialog) {
		if (event.key !== "Tab" || !dialog || dialog.hidden) return false;
		var focusable = Array.prototype.slice.call(dialog.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')).filter(function (element) {
			return !element.hidden && element.offsetParent !== null;
		});
		if (!focusable.length) return false;
		var first = focusable[0];
		var last = focusable[focusable.length - 1];
		if (event.shiftKey && document.activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && document.activeElement === last) {
			event.preventDefault();
			first.focus();
		}
		return true;
	}

	function setUserStatus() {
		renderProfile();
	}

	function renderProfile() {
		var user = state.user || {};
		var name = user.display_name || user.name || user.username || "已登录";
		var username = user.username
			? (String(user.username).indexOf("@") === 0 ? String(user.username) : "@" + user.username)
			: (user.email || "");
		var initial = String(name).trim().charAt(0).toUpperCase() || "我";
		var nameElement = $("profile-name");
		var usernameElement = $("profile-username");
		var avatarElement = $("profile-avatar");
		if (nameElement) {
			nameElement.textContent = name;
		}
		if (usernameElement) {
			usernameElement.textContent = username;
			usernameElement.hidden = !username;
		}
		if (avatarElement) {
			avatarElement.textContent = initial;
		}
		var adminLink = $("profile-admin-link");
		if (adminLink) {
			adminLink.hidden = !([true, 1, "1"].indexOf(user.is_admin) >= 0 || [true, 1, "1"].indexOf(user.may_administrate) >= 0 || user.isAdmin === true);
		}
	}

	function escapeHtml(value) {
		return String(value == null ? "" : value).replace(/[&<>"']/g, function (ch) {
			return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" }[ch];
		});
	}

	function sameId(left, right) {
		return left !== null && left !== undefined && right !== null && right !== undefined && String(left) === String(right);
	}

	function dataSignature(value) {
		try {
			return JSON.stringify(value);
		} catch (_error) {
			return "";
		}
	}

	function activitySignature(activity) {
		var images = Array.isArray(activity && activity.images) ? activity.images : [];
		return dataSignature({
			id: activity && activity.id,
			title: activity && activity.title,
			body: activity && activity.body,
			created_at: activity && activity.created_at,
			updated_at: activity && activity.updated_at,
			image_count: activity && activity.image_count,
			comment_count: activity && activity.comment_count,
			can_delete: activity && activity.can_delete,
			images_pagination: activity && activity.images_pagination,
			images: images.map(function (image) {
				return {
					id: image && image.id,
					url: image && image.url,
					updated_at: image && image.updated_at,
					width: image && image.width,
					height: image && image.height,
					processing_status: image && image.processing_status
				};
			})
		});
	}

	function activitiesSignature(activities) {
		return dataSignature((activities || []).map(activitySignature));
	}

	function readDataCache() {
		try {
			var parsed = JSON.parse(window.localStorage.getItem(DATA_CACHE_KEY) || "null");
			return parsed && typeof parsed === "object" ? parsed : null;
		} catch (_error) {
			return null;
		}
	}

	function writeDataCache(cache) {
		try {
			window.localStorage.setItem(DATA_CACHE_KEY, JSON.stringify(cache));
		} catch (_error) {
			// Metadata caching is optional; quota or private-mode failures must not block the app.
		}
	}

	function persistentCacheSnapshot() {
		return {
			version: 4,
			userId: state.persistentCacheUserId,
			activities: state.activities,
			activitiesNextCursor: state.activitiesNextCursor,
			activitiesHasMore: state.activitiesHasMore,
			activitiesSignature: state.activitiesSignature
		};
	}

	function persistDataCache() {
		if (!state.persistentCacheUserId || state.activityScope !== "all" || state.activityFilter !== "all") {
			return;
		}
		writeDataCache(persistentCacheSnapshot());
	}

	function restoreDataCache(userId) {
		var cache = readDataCache();
		if (!cache || String(cache.userId) !== String(userId) || Number(cache.version) !== 4) {
			state.persistentCacheUserId = String(userId || "");
			return false;
		}
		state.persistentCacheUserId = String(userId);
		state.activities = Array.isArray(cache.activities) ? cache.activities : [];
		state.activitiesNextCursor = cache.activitiesNextCursor || null;
		state.activitiesHasMore = !!cache.activitiesHasMore;
		state.activitiesSignature = String(cache.activitiesSignature || activitiesSignature(state.activities));
		state.activitiesLoaded = state.activities.length > 0;
		state.activitiesNeedsRevalidate = state.activitiesLoaded;
		return state.activitiesLoaded;
	}

	function restoreAndRevalidateUserCache() {
		if (!state.user || !isLoggedIn(state.user)) {
			return;
		}
		restoreDataCache(state.user.id);
	}

	function isLoggedIn(user) {
		return !!(user && user.id !== null && user.id !== undefined);
	}

	function imageCacheKey(photo) {
		var parts = [
			photo && photo.id || "",
			photo && (photo.updated_at || photo.created_at || photo.taken_at) || ""
		].filter(Boolean);
		return encodeURIComponent(parts.join("-"));
	}

	function normalizeImageUrl(url) {
		if (!url) {
			return "";
		}
		var value = String(url).trim();
		if (!value || /^(?:data|blob):/i.test(value)) {
			return value;
		}
		if (value.indexOf("//") === 0) {
			return window.location.protocol + value;
		}
		try {
			var parsed = new URL(value, window.location.origin);
			var host = parsed.hostname.toLowerCase();
			if (host === "localhost" || host === "127.0.0.1" || host === "0.0.0.0") {
				return parsed.pathname + parsed.search + parsed.hash;
			}
			if (parsed.protocol === "http:" && window.location.protocol === "https:" && parsed.host === window.location.host) {
				return parsed.pathname + parsed.search + parsed.hash;
			}
			return parsed.href;
		} catch (_error) {
			return value;
		}
	}

	function cloudflareCacheImageUrl(url) {
		if (!url || /^(?:data|blob):/i.test(url)) {
			return url || "";
		}
		try {
			var parsed = new URL(url, window.location.origin);
			if (parsed.origin !== window.location.origin || parsed.pathname.indexOf("/image/") !== 0) {
				return url;
			}
			var imagePath = parsed.pathname.slice("/image/".length).replace(/^\/+/, "");
			if (!imagePath) {
				return url;
			}
			parsed.pathname = "/_cf_image/" + imagePath + ".jpg";
			if (/^https?:\/\//i.test(url)) {
				return parsed.href;
			}
			return parsed.pathname + parsed.search + parsed.hash;
		} catch (_error) {
			return url;
		}
	}

	function localImageUrl(url) {
		if (!url || /^(?:data|blob):/i.test(url)) {
			return url || "";
		}
		try {
			var parsed = new URL(url, window.location.origin);
			if (parsed.origin !== window.location.origin) {
				return url;
			}
			var path = parsed.pathname.replace(/^\/+/, "");
			if (path.indexOf("uploads/") !== 0) {
				return url;
			}
			path = path.slice("uploads/".length);
			if (!/^(?:original|medium2x|medium|small2x|small|thumb2x|thumb|import)\/[A-Za-z0-9._/-]+$/.test(path) || path.indexOf("..") !== -1) {
				return url;
			}
			parsed.pathname = "/Pictures/" + path;
			return /^(?:https?:)?\/\//i.test(url) ? parsed.href : parsed.pathname + parsed.search + parsed.hash;
		} catch (_error) {
			return url;
		}
	}

	function versionImageUrl(url, photo) {
		// Keep Lychee's generated URL and extension intact. Rewriting every
		// /image/ URL to a .jpg cache alias breaks formats that are not JPEG.
		var normalized = normalizeImageUrl(url);
		if (!normalized || /^(?:data|blob):/i.test(normalized)) {
			return normalized || "";
		}
		var key = imageCacheKey(photo);
		var localUrl = localImageUrl(normalized);
		return key ? localUrl + (localUrl.indexOf("?") === -1 ? "?" : "&") + "cg_v=" + key : localUrl;
	}

	// Animated GIFs must use Lychee's original file. Its generated variants and
	// the Cloudflare image-cache endpoint are JPEGs, so they only contain frame 1.
	function versionOriginalMediaUrl(url, photo) {
		var normalized = normalizeImageUrl(url);
		if (!normalized || /^(?:data|blob):/i.test(normalized)) {
			return normalized || "";
		}
		var key = imageCacheKey(photo);
		var localUrl = localImageUrl(normalized);
		return key ? localUrl + (localUrl.indexOf("?") === -1 ? "?" : "&") + "cg_v=" + key : localUrl;
	}

	function collectImageUrls(value, urls, depth) {
		if (!value || depth > 4) {
			return;
		}
		if (typeof value === "string") {
			if (/\.(?:avif|gif|jpe?g|png|webp|m4v|mov|mp4|ogv|webm)(?:[?#].*)?$/i.test(value) || /\/(?:uploads|image)\//i.test(value)) {
				urls.push(value);
			}
			return;
		}
		if (Array.isArray(value)) {
			value.forEach(function (item) {
				collectImageUrls(item, urls, depth + 1);
			});
			return;
		}
		if (typeof value === "object") {
			Object.keys(value).forEach(function (key) {
				if (/^(?:url|thumb|thumb2x|small|small2x|medium|medium2x|original|placeholder|src|href|video|video_url|download_url|original_url)$/i.test(key)) {
					collectImageUrls(value[key], urls, depth + 1);
				}
			});
		}
	}

	function isVideoUrl(url) {
		return /\.(?:m4v|mov|mp4|ogv|webm)(?:[?#].*)?$/i.test(String(url || ""));
	}

	function isVideoPhoto(photo) {
		if (!photo) {
			return false;
		}
		var values = [
			photo.type,
			photo.kind,
			photo.mime,
			photo.mime_type,
			photo.media_type,
			photo.file_type,
			photo.extension,
			photo.url
		];
		if (photo.size_variants && photo.size_variants.original) {
			values.push(photo.size_variants.original.url);
		}
		return values.some(function (value) {
			var text = String(value || "").toLowerCase();
			return text.indexOf("video") !== -1 || /\.(?:m4v|mov|mp4|ogv|webm)(?:[?#].*)?$/i.test(text);
		});
	}

	function isAnimatedImagePhoto(photo) {
		if (!photo || isVideoPhoto(photo)) {
			return false;
		}
		var values = [
			photo.type,
			photo.kind,
			photo.mime,
			photo.mime_type,
			photo.media_type,
			photo.file_type,
			photo.extension,
			photo.url
		];
		if (photo.size_variants && photo.size_variants.original) {
			values.push(photo.size_variants.original.url);
		}
		return values.some(function (value) {
			return /(?:image\/gif|\.gif)(?:[?#].*)?$/i.test(String(value || ""));
		});
	}

	function dedupeUrls(urls) {
		return urls.filter(function (url, index, all) {
			return url && all.indexOf(url) === index;
		});
	}

	function imageVariantUrls(photo, order) {
		var variants = photo.size_variants || {};
		var urls = [];
		for (var i = 0; i < order.length; i += 1) {
			var key = order[i];
			var variant = variants[key];
			if (variant && variant.url) {
				urls.push(versionImageUrl(variant.url, photo));
			}
			var direct = photo[key];
			if (typeof direct === "string") {
				urls.push(versionImageUrl(direct, photo));
			} else if (direct && direct.url) {
				urls.push(versionImageUrl(direct.url, photo));
			}
		}
		return dedupeUrls(urls);
	}

	function originalMediaUrls(photo) {
		var urls = [];
		var original = photo && photo.size_variants && photo.size_variants.original;
		if (original && original.url) {
			urls.push(versionOriginalMediaUrl(original.url, photo));
		}
		["original", "original_url", "download_url", "url"].forEach(function (key) {
			var value = photo && photo[key];
			if (typeof value === "string") {
				urls.push(versionOriginalMediaUrl(value, photo));
			} else if (value && value.url) {
				urls.push(versionOriginalMediaUrl(value.url, photo));
			}
		});
		return dedupeUrls(urls);
	}

	function thumbnailImageCandidates(photo) {
		var thumb = photo.thumb || {};
		return dedupeUrls(imageVariantUrls(photo, ["thumb2x", "thumb", "small2x", "small"]).concat([
			versionImageUrl(thumb.thumb2x, photo),
			versionImageUrl(thumb.thumb, photo),
			versionImageUrl(thumb.url, photo),
			versionImageUrl(thumb.placeholder, photo)
		]));
	}

	function previewImageCandidates(photo) {
		return imageVariantUrls(photo, ["medium", "medium2x", "small2x", "small"]);
	}

	function fallbackImageCandidates(photo) {
		var urls = imageVariantUrls(photo, ["original"]);
		if (photo.url) {
			urls.push(versionImageUrl(photo.url, photo));
		}
		var discovered = [];
		collectImageUrls(photo, discovered, 0);
		discovered.forEach(function (url) {
			urls.push(versionImageUrl(url, photo));
		});
		return dedupeUrls(urls);
	}

	function photoImageUrls(photo, full) {
		if (isAnimatedImagePhoto(photo)) {
			return dedupeUrls(originalMediaUrls(photo).concat(previewImageCandidates(photo), thumbnailImageCandidates(photo)));
		}
		return full
			? dedupeUrls(previewImageCandidates(photo).concat(fallbackImageCandidates(photo), thumbnailImageCandidates(photo)))
			: dedupeUrls(previewImageCandidates(photo).concat(thumbnailImageCandidates(photo)));
	}

	function imageFromPhoto(photo, full) {
		return photoImageUrls(photo, full)[0] || "";
	}

	function recordSiteEntryEvent() {
		return api("AccountLoginEvents", {
			method: "POST",
			body: {},
			timeoutMs: SESSION_TIMEOUT_MS
		}).catch(function () {
			return null;
		});
	}

	function recordSiteEntryInBackground() {
		window.setTimeout(function () {
			recordSiteEntryEvent();
		}, 0);
	}

	function activityImageUrl(image, full) {
		if (!image) {
			return "";
		}
		if (typeof image === "string") {
			var stringUrl = versionImageUrl(image, {});
			return isVideoUrl(stringUrl) ? "" : stringUrl;
		}
		var imageUrl = imageFromPhoto(image, full) || normalizeImageUrl(image.url || image.src || image.href || "");
		return isVideoUrl(imageUrl) ? "" : imageUrl;
	}

	function activityVideoUrl(activity, image) {
		var values = [];
		function add(value) {
			if (typeof value === "string") {
				values.push(value);
			} else if (value && typeof value.url === "string") {
				values.push(value.url);
			}
		}
		if (activity) {
			add(activity.video_url);
			add(activity.video);
		}
		if (image) {
			add(image.video_url);
			add(image.video);
			if (isVideoPhoto(image)) {
				add(image.url);
				add(image.original_url);
			}
		}
		var explicitVideo = !!((activity && (activity.video_url || activity.video)) || (image && (image.video_url || image.video)));
		return values.map(normalizeImageUrl).find(function (url) {
			return url && (explicitVideo || isVideoUrl(url) || (activity && /video/i.test(String(activity.type || activity.media_type || ""))));
		}) || "";
	}

	function activityMedia(activity, image) {
		var videoUrl = activityVideoUrl(activity, image);
		var imageUrl = activityImageUrl(image, false);
		var previewUrl = activityImageUrl(image, true) || imageUrl;
		return {
			videoUrl: videoUrl,
			imageUrl: imageUrl,
			previewUrl: previewUrl,
			isVideo: !!videoUrl
		};
	}

	function activityDetailImageUrl(image, media) {
		var originals = originalMediaUrls(image).filter(function (url) {
			return url && !isVideoUrl(url);
		});
		return originals[0] || (media && (media.imageUrl || media.previewUrl)) || "";
	}

	function activityImageMarkup(images) {
		var count = images.length;
		return images.map(function (image, index) {
			var media = activityMedia(null, image);
			var thumbnailUrl = media.imageUrl || media.previewUrl;
			var previewUrl = media.previewUrl || thumbnailUrl;
			if (media.isVideo) {
				return '<button class="activity-image activity-image-' + count + ' activity-image-video" type="button" data-action="open-activity-image" data-image-url="' + escapeHtml(media.videoUrl || previewUrl) + '" aria-label="预览第 ' + (index + 1) + ' 个媒体"><video src="' + escapeHtml(media.videoUrl) + '"' + (thumbnailUrl ? ' poster="' + escapeHtml(thumbnailUrl) + '"' : '') + ' muted playsinline preload="metadata"></video><span class="activity-video-mark" aria-hidden="true">▶</span></button>';
			}
			return '<button class="activity-image activity-image-' + count + '" type="button" data-action="open-activity-image" data-image-url="' + escapeHtml(previewUrl) + '" aria-label="预览第 ' + (index + 1) + ' 张照片"><img src="' + escapeHtml(thumbnailUrl) + '" alt="动态照片" loading="lazy"></button>';
		}).join("");
	}

	function activityText(activity, key) {
		return activity && activity[key] != null ? String(activity[key]) : "";
	}

	function activityCoverMarkup(activity) {
		var images = Array.isArray(activity && activity.images) ? activity.images : [];
		var media = activityMedia(activity, images[0]);
		var imageUrl = media.imageUrl || media.previewUrl;
		if (media.isVideo) {
			return '<div class="activity-cover activity-cover-video"><video src="' + escapeHtml(media.videoUrl) + '"' + (imageUrl ? ' poster="' + escapeHtml(imageUrl) + '"' : '') + ' muted playsinline loop autoplay preload="metadata"></video><span class="activity-video-mark" aria-hidden="true">▶</span></div>';
		}
		if (imageUrl) {
			return '<div class="activity-cover"><img src="' + escapeHtml(imageUrl) + '" alt="动态封面" loading="lazy"></div>';
		}
		return '<div class="activity-cover activity-cover-empty" aria-hidden="true"><span>暂无预览</span></div>';
	}

	function activityCardMarkup(activity) {
		var images = Array.isArray(activity.images) ? activity.images : [];
		var imageCount = Math.max(Number(activity.image_count || 0), images.length);
		var remaining = Math.max(0, imageCount - images.length);
		var displayName = activity.display_name || activity.username || "社区成员";
		var author = escapeHtml(displayName);
		var avatarInitial = escapeHtml(String(displayName).charAt(0));
		var title = activityText(activity, "title");
		var body = activityText(activity, "body");
		var commentCount = Math.max(0, Number(activity.comment_count || 0));
		var mediaCount = Math.max(imageCount, 0);
		var deleteButton = activity.can_delete
			? '<button class="activity-delete-trigger" type="button" data-action="request-delete-activity" data-activity-id="' + escapeHtml(activity.id) + '">删除</button>'
			: '';
		return activityCoverMarkup(activity) +
			'<div class="activity-card-content">' +
				(title ? '<h2 class="activity-title">' + escapeHtml(title) + '</h2>' : "") +
				(body ? '<p class="activity-body">' + escapeHtml(body).replace(/\n/g, "<br>") + '</p>' : "") +
				'<div class="activity-card-meta"><span>' + mediaCount + ' 个媒体</span>' + (commentCount ? '<span>' + commentCount + ' 条评论</span>' : '') + '</div>' +
				'<header class="activity-author"><span class="activity-avatar" aria-hidden="true">' + avatarInitial + '</span><strong>' + author + '</strong><time>' + escapeHtml(formatRelativeTime(activity.created_at)) + '</time>' + deleteButton + '</header>' +
				(remaining ? '<button class="activity-more-images" type="button" data-action="load-activity-images" data-activity-id="' + escapeHtml(activity.id) + '">加载更多</button>' : '') +
			'</div>';
	}

	function syncActivityFilterTabs() {
		Array.prototype.forEach.call(document.querySelectorAll(".activities-feed-tab"), function (tab) {
			var active = tab.dataset.filter === state.activityFilter;
			tab.classList.toggle("is-active", active);
			tab.setAttribute("aria-selected", active ? "true" : "false");
		});
	}

	function syncActivitiesHeader() {
		var title = document.querySelector(".activities-feed-title");
		var subtitle = document.querySelector(".activities-feed-subtitle");
		var back = $("activities-scope-back");
		var mine = state.activityScope === "mine";
		if (title) title.textContent = mine ? "我的动态" : "发现动态";
		if (subtitle) subtitle.textContent = mine ? "查看和管理你发布的内容。" : "看看大家正在分享的画面。";
		if (back) back.hidden = !mine;
	}

	function renderActivities(activities) {
		var feed = $("activities-feed");
		var empty = $("activities-empty");
		var loading = $("activities-loading");
		if (!feed || !empty || !loading) return;
		loading.hidden = true;
		var visibleActivities = activities || [];
		empty.hidden = visibleActivities.length !== 0;
		empty.textContent = state.activityScope === "mine"
			? "你还没有发布动态。"
			: (state.activityFilter === "video" ? "还没有视频动态。" : (state.activityFilter === "image" ? "还没有图片动态。" : "还没有动态。发布第一条，留下今天的画面。"));
		var existingCards = new Map();
		Array.prototype.forEach.call(feed.querySelectorAll(".activity-card"), function (card) {
			existingCards.set(String(card.dataset.activityId), card);
		});
		var fragment = document.createDocumentFragment();
		visibleActivities.forEach(function (activity) {
			var key = String(activity.id == null ? "" : activity.id);
			var signature = activitySignature(activity);
			var card = existingCards.get(key);
			if (!card || card.dataset.activitySignature !== signature) {
				card = document.createElement("article");
				card.className = "activity-card";
				card.dataset.action = "open-activity-detail";
				card.dataset.activityId = key;
				card.tabIndex = 0;
				card.innerHTML = activityCardMarkup(activity);
				card.dataset.activitySignature = signature;
			}
			fragment.appendChild(card);
		});
		feed.replaceChildren(fragment);
		$("activities-load-more").hidden = !state.activitiesHasMore;
		$("activities-load-more").disabled = state.activitiesLoadingMore;
		syncActivityFilterTabs();
	}

	function setActivityFilter(filter) {
		var nextFilter = ["all", "image", "video"].indexOf(filter) >= 0 ? filter : "all";
		if (nextFilter === state.activityFilter) {
			return;
		}
		state.activityFilter = nextFilter;
		resetActivityFeed();
		loadActivities(null, { force: true });
	}

	function resetActivityFeed() {
		state.cacheGeneration += 1;
		state.activities = [];
		state.activitiesNextCursor = null;
		state.activitiesHasMore = false;
		state.activitiesLoaded = false;
		state.activitiesRequest = null;
		state.activitiesSignature = "";
	}

	function loadActivities(cursor, options) {
		options = options || {};
		var firstPage = !cursor;
		showOnly("activities-view");
		syncActivitiesHeader();
		setUserStatus();
		if (firstPage && state.activitiesLoaded && !options.force) {
			renderActivities(state.activities);
			if (!state.activitiesNeedsRevalidate) {
				return Promise.resolve(state.activities);
			}
			state.activitiesNeedsRevalidate = false;
			options = { force: true, silent: true };
		}
		if (firstPage && state.activitiesRequest) {
			return state.activitiesRequest;
		}
		if (firstPage && !options.silent) {
			state.activities = [];
			state.activitiesNextCursor = null;
			$("activities-loading").hidden = false;
			$("activities-empty").hidden = true;
		}
		var generation = state.cacheGeneration;
		var query = "Activities?limit=15&type=" + encodeURIComponent(state.activityFilter) + "&scope=" + encodeURIComponent(state.activityScope);
		if (cursor) {
			query += "&cursor=" + encodeURIComponent(cursor);
		}
		var request = api(query).then(function (payload) {
			if (generation !== state.cacheGeneration) return;
			var data = resource(payload) || {};
			var incoming = Array.isArray(data.activities) ? data.activities : [];
			var seen = new Set(firstPage ? [] : state.activities.map(function (item) { return String(item.id); }));
			var nextActivities = firstPage ? [] : state.activities.slice();
			incoming.forEach(function (item) {
				var id = String(item.id);
				if (!seen.has(id)) {
					seen.add(id);
					nextActivities.push(item);
				}
			});
			var nextSignature = activitiesSignature(nextActivities);
			var changed = nextSignature !== state.activitiesSignature;
			state.activities = nextActivities;
			state.activitiesSignature = nextSignature;
			state.activitiesNextCursor = data.pagination && data.pagination.next_cursor || null;
			state.activitiesHasMore = !!(data.pagination && data.pagination.has_more && state.activitiesNextCursor);
			if (firstPage) state.activitiesLoaded = true;
			if (changed || !options.silent) renderActivities(state.activities);
			persistDataCache();
		}).catch(function (error) {
			if (generation !== state.cacheGeneration) return;
			$("activities-loading").hidden = true;
			showToast(error.message);
		});
		if (firstPage) {
			state.activitiesRequest = request.finally(function () {
				if (generation === state.cacheGeneration) state.activitiesRequest = null;
			});
			return state.activitiesRequest;
		}
		return request;
	}

	function loadMoreActivities() {
		if (!state.activitiesHasMore || state.activitiesLoadingMore) return;
		state.activitiesLoadingMore = true;
		renderActivities(state.activities);
		loadActivities(state.activitiesNextCursor).finally(function () {
			state.activitiesLoadingMore = false;
			renderActivities(state.activities);
		});
	}

	function loadActivityImages(activityId) {
		var activity = state.activities.find(function (item) { return sameId(item.id, activityId); });
		if (!activity || activity._imagesLoading || !(activity.images_pagination && activity.images_pagination.has_more)) return Promise.resolve();
		activity._imagesLoading = true;
		var cursor = activity.images_pagination.next_cursor;
		return api("Activities/" + encodeURIComponent(activityId) + "/Images?limit=9&cursor=" + encodeURIComponent(cursor)).then(function (payload) {
			var data = resource(payload) || {};
			var known = new Set((activity.images || []).map(function (image) { return String(image.id); }));
			(data.images || []).forEach(function (image) {
				if (!known.has(String(image.id))) {
					known.add(String(image.id));
					activity.images.push(image);
				}
			});
			activity.image_count = Number(data.image_count || activity.image_count);
			activity.images_pagination = data.pagination || { has_more: !!data.has_more, next_cursor: data.next_cursor || null };
			state.activitiesSignature = activitiesSignature(state.activities);
			persistDataCache();
			renderActivities(state.activities);
			if (state.currentActivity && sameId(state.currentActivity.id, activity.id)) {
				var frame = activityDetailElement("activity-detail-media", "[data-activity-detail-media]");
				var activeIndex = frame && frame.clientWidth ? Math.round(frame.scrollLeft / frame.clientWidth) : 0;
				renderActivityDetailMedia(activity, activeIndex);
				renderActivityDetailThumbs(activity, activeIndex);
			}
		}).catch(function (error) { showToast(error.message); }).finally(function () { activity._imagesLoading = false; });
	}

	function openActivityImage(imageUrl, skipHistory) {
		var modal = $("activity-image-modal");
		var image = $("activity-lightbox-image");
		if (!modal || !image || !imageUrl) return;
		if (!skipHistory) pushUiLayer("activity-image");
		image.src = imageUrl;
		modal.hidden = false;
		modal.classList.add("active");
		modal.setAttribute("aria-hidden", "false");
		document.body.classList.add("activity-preview-open");
		window.setTimeout(function () { $("activity-image-close").focus(); }, 0);
	}

	function closeActivityImage(fromHistory) {
		if (!fromHistory) {
			requestHistoryClose("activity-image", function () { closeActivityImage(true); });
			return;
		}
		var modal = $("activity-image-modal");
		var image = $("activity-lightbox-image");
		if (!modal) return;
		modal.classList.remove("active");
		modal.hidden = true;
		modal.setAttribute("aria-hidden", "true");
		if (image) image.removeAttribute("src");
		document.body.classList.remove("activity-preview-open");
		restoreLayerFocus("activity-image");
	}

	function activityDetailElement(id, selector) {
		var element = id ? $(id) : null;
		if (element) {
			return element;
		}
		return selector ? document.querySelector(selector) : null;
	}

	function renderActivityDetailMedia(activity, activeIndex) {
		var frame = activityDetailElement("activity-detail-media", "[data-activity-detail-media]");
		if (!frame) {
			return;
		}
		frame.replaceChildren();
		activeIndex = Math.max(0, Number(activeIndex || 0));
		var images = Array.isArray(activity && activity.images) ? activity.images : [];
		var mediaItems = images.length ? images : (activityVideoUrl(activity, null) ? [null] : []);
		if (!mediaItems.length) {
			var cover = document.createElement("div");
			cover.className = "activity-detail-empty";
			cover.textContent = "暂无媒体";
			frame.appendChild(cover);
			return;
		}
		mediaItems.forEach(function (image, index) {
			var media = activityMedia(index === 0 ? activity : null, image);
			var holder = document.createElement("div");
			holder.className = "activity-detail-media-item";
			if (media.isVideo) {
				var video = document.createElement("video");
				video.src = media.videoUrl;
				video.muted = true;
				video.autoplay = index === 0;
				video.loop = true;
				video.playsInline = true;
				video.controls = true;
				if (media.imageUrl) {
					video.poster = media.imageUrl;
				}
				holder.appendChild(video);
			} else {
				var imageElement = document.createElement("img");
				imageElement.src = activityDetailImageUrl(image, media);
				imageElement.alt = "动态照片";
				imageElement.loading = index === 0 ? "eager" : "lazy";
				holder.appendChild(imageElement);
			}
			frame.appendChild(holder);
		});
		window.requestAnimationFrame(function () {
			frame.scrollLeft = activeIndex * frame.clientWidth;
		});
	}

	function renderActivityDetailThumbs(activity, activeIndex) {
		var thumbs = activityDetailElement("activity-detail-thumbs", "[data-activity-detail-thumbs]");
		if (!thumbs) {
			return;
		}
		thumbs.replaceChildren();
		var images = Array.isArray(activity && activity.images) ? activity.images : [];
		var mediaItems = images.length ? images : (activityVideoUrl(activity, null) ? [null] : []);
		var frame = activityDetailElement("activity-detail-media", "[data-activity-detail-media]");
		activeIndex = Math.max(0, Number(activeIndex || 0));
		function setActiveThumb(index) {
			Array.prototype.forEach.call(thumbs.children, function (child, childIndex) {
				child.classList.toggle("is-active", childIndex === index);
			});
		}
		mediaItems.forEach(function (image, index) {
			var media = activityMedia(index === 0 ? activity : null, image);
			var button = document.createElement("button");
			button.type = "button";
			button.className = "activity-detail-thumb" + (index === activeIndex ? " is-active" : "");
			button.dataset.index = String(index);
			button.setAttribute("aria-label", "查看第 " + (index + 1) + " 个媒体");
			if (media.isVideo) {
				button.innerHTML = '<video src="' + escapeHtml(media.videoUrl) + '"' + (media.imageUrl ? ' poster="' + escapeHtml(media.imageUrl) + '"' : '') + ' muted playsinline preload="metadata"></video><span aria-hidden="true">▶</span>';
			} else {
				button.innerHTML = '<img src="' + escapeHtml(media.imageUrl || media.previewUrl) + '" alt="">';
			}
			button.addEventListener("click", function () {
				if (frame) {
					frame.scrollTo({ left: index * frame.clientWidth, behavior: "smooth" });
				}
				setActiveThumb(index);
			});
			thumbs.appendChild(button);
		});
		if (frame) {
			var scrollUpdateScheduled = false;
			if (frame._activityDetailThumbScrollHandler) {
				frame.removeEventListener("scroll", frame._activityDetailThumbScrollHandler);
			}
			frame._activityDetailThumbScrollHandler = function () {
				if (scrollUpdateScheduled) {
					return;
				}
				scrollUpdateScheduled = true;
				window.requestAnimationFrame(function () {
					scrollUpdateScheduled = false;
					if (frame.clientWidth) {
						var index = Math.round(frame.scrollLeft / frame.clientWidth);
						setActiveThumb(Math.max(0, Math.min(thumbs.children.length - 1, index)));
						if (index >= thumbs.children.length - 2 && activity.images_pagination && activity.images_pagination.has_more) {
							loadActivityImages(activity.id);
						}
					}
				});
			};
			frame.addEventListener("scroll", frame._activityDetailThumbScrollHandler, { passive: true });
		}
	}

	function activityCommentAuthor(comment) {
		return comment.display_name || comment.username || "社区成员";
	}

	function syncActivityCommentCount(activity) {
		if (!activity) {
			return;
		}
		Array.prototype.forEach.call(document.querySelectorAll(".activity-card"), function (card) {
			if (!sameId(card.dataset.activityId, activity.id)) {
				return;
			}
			var trigger = card.querySelector(".activity-comment-trigger");
			if (trigger) {
				var count = Number(activity.comment_count || 0);
				trigger.textContent = "评论" + (count ? " " + count : "");
			}
		});
	}

	function renderActivityReplyTarget() {
		var box = $("activity-reply-target");
		if (!box) {
			return;
		}
		var target = state.activityCommentReplyTarget;
		if (!target) {
			box.hidden = true;
			box.innerHTML = "";
			return;
		}
		box.hidden = false;
		box.innerHTML = '<span>回复' + escapeHtml(target.author) + '</span><button type="button" data-action="cancel-activity-reply" aria-label="取消回复">x</button>';
	}

	function renderActivityComments(activity, loadingMessage) {
		var list = $("activity-comment-list");
		var count = $("activity-comment-count");
		if (!list || !count || !activity) {
			return;
		}
		var key = String(activity.id);
		var comments = state.activityComments.get(key);
		count.textContent = (comments ? comments.length : Number(activity.comment_count || 0)) + " 条";
		if (loadingMessage) {
			list.innerHTML = '<div class="activity-comment-state">' + escapeHtml(loadingMessage) + '</div>';
			return;
		}
		if (!comments || !comments.length) {
			list.innerHTML = '<div class="activity-comment-state">还没有评论，来留下第一条吧。</div>';
			return;
		}
		list.innerHTML = "";
		comments.forEach(function (comment) {
			var author = activityCommentAuthor(comment);
			var parsed = parseReplyBody(comment.body || "");
			var item = document.createElement("div");
			item.className = "activity-comment-item";
			item.innerHTML =
				'<div class="activity-comment-avatar">' + escapeHtml(author.charAt(0) || "?") + '</div>' +
				'<div class="activity-comment-body">' +
				'<div class="activity-comment-head"><span class="activity-comment-author">' + escapeHtml(author) + '</span></div>' +
				'<div class="activity-comment-text">' +
				(parsed.replyTo ? '<span class="activity-comment-mention">回复' + escapeHtml(parsed.replyTo) + '：</span>' : "") +
				escapeHtml(parsed.text) +
				'</div>' +
				'<div class="activity-comment-actions"><span>' + escapeHtml(formatRelativeTime(comment.created_at)) + '</span><button type="button" data-action="reply-activity-comment" data-comment-author="' + escapeHtml(author) + '">回复</button></div>' +
				'</div>';
			list.appendChild(item);
		});
	}

	function loadActivityComments(activityId) {
		var key = String(activityId);
		if (state.activityComments.has(key)) {
			return Promise.resolve(state.activityComments.get(key));
		}
		if (state.activityCommentsRequests.has(key)) {
			return state.activityCommentsRequests.get(key);
		}
		var generation = state.cacheGeneration;
		var request = api("Activities/" + encodeURIComponent(activityId) + "/Comments").then(function (payload) {
			if (generation !== state.cacheGeneration) {
				return [];
			}
			var data = resource(payload) || {};
			var comments = Array.isArray(data) ? data : (data.comments || data.data || []);
			state.activityComments.set(key, Array.isArray(comments) ? comments : []);
			var activity = state.activities.find(function (item) { return sameId(item.id, activityId); });
			if (activity) {
				activity.comment_count = state.activityComments.get(key).length;
				syncActivityCommentCount(activity);
			}
			return state.activityComments.get(key);
		}).catch(function (error) {
			var activity = state.activities.find(function (item) { return sameId(item.id, activityId); });
			if (activity && state.currentActivity && sameId(state.currentActivity.id, activityId)) {
				renderActivityComments(activity, "评论加载失败，请稍后重试");
			}
			showToast(error.message);
			throw error;
		}).finally(function () {
			if (generation === state.cacheGeneration) {
				state.activityCommentsRequests.delete(key);
			}
		});
		state.activityCommentsRequests.set(key, request);
		return request;
	}

	function addActivityComment(text) {
		var activity = state.currentActivity;
		var body = String(text || "").trim();
		if (!activity || !body) {
			return Promise.resolve(false);
		}
		if (body.length > 500) {
			showToast("评论最多 500 个字符");
			return Promise.resolve(false);
		}
		var replyTarget = state.activityCommentReplyTarget;
		if (replyTarget && replyTarget.author) {
			body = "回复" + replyTarget.author + "：" + body;
		}
		if (body.length > 500) {
			showToast("评论最多 500 个字符");
			return Promise.resolve(false);
		}
		var button = $("activity-comment-form") && $("activity-comment-form").querySelector("button[type='submit']");
		state.activityCommentBusy = true;
		if (button) {
			button.disabled = true;
			button.textContent = "发送中...";
		}
		return api("Activities/" + encodeURIComponent(activity.id) + "/Comments", {
			method: "POST",
			body: { body: body }
		}).then(function (payload) {
			var data = resource(payload) || {};
			var comment = data.comment || data;
			if (!comment || (!comment.id && !comment.body)) {
				return false;
			}
			var key = String(activity.id);
			var comments = state.activityComments.get(key) || [];
			comments.push(comment);
			state.activityComments.set(key, comments);
			activity.comment_count = comments.length;
			syncActivityCommentCount(activity);
			state.activitiesSignature = activitiesSignature(state.activities);
			persistDataCache();
			state.activityCommentReplyTarget = null;
			renderActivityReplyTarget();
			renderActivityComments(activity);
			return true;
		}).catch(function (error) {
			showToast(error.message);
			return false;
		}).finally(function () {
			state.activityCommentBusy = false;
			if (button) {
				button.disabled = false;
				button.textContent = "发送";
			}
		});
	}

	function openActivityDetail(activityId, skipHistory) {
		var activity = state.activities.find(function (item) { return sameId(item.id, activityId); });
		var modal = activityDetailElement("activity-detail-modal", "[data-activity-detail-modal]");
		if (!activity || !modal) {
			return;
		}
		if (!skipHistory) pushUiLayer("activity-detail");
		state.currentActivity = activity;
		renderActivityDetailMedia(activity);
		renderActivityDetailThumbs(activity);
		var title = activityDetailElement("activity-detail-title", "[data-activity-detail-title]");
		var body = activityDetailElement("activity-detail-body", "[data-activity-detail-body]");
		var author = activityDetailElement("activity-detail-author-name", "[data-activity-detail-author-name]");
		var avatar = activityDetailElement("activity-detail-avatar", "[data-activity-detail-avatar]");
		var time = activityDetailElement("activity-detail-time", "[data-activity-detail-time]");
		if (title) {
			title.textContent = activityText(activity, "title");
			title.hidden = !activityText(activity, "title");
		}
		if (body) {
			body.innerHTML = escapeHtml(activityText(activity, "body")).replace(/\n/g, "<br>");
			body.hidden = !activityText(activity, "body");
		}
		if (author) {
			author.textContent = activity.display_name || activity.username || "社区成员";
		}
		if (avatar) {
			avatar.textContent = String(activity.display_name || activity.username || "社区成员").charAt(0);
		}
		if (time) {
			time.textContent = formatRelativeTime(activity.created_at);
		}
		var existingDelete = modal.querySelector(".activity-detail-delete");
		if (existingDelete) existingDelete.remove();
		if (activity.can_delete) {
			var deleteButton = document.createElement("button");
			deleteButton.type = "button";
			deleteButton.className = "activity-delete-trigger activity-detail-delete";
			deleteButton.dataset.action = "request-delete-activity";
			deleteButton.dataset.activityId = String(activity.id);
			deleteButton.textContent = "删除动态";
			modal.querySelector(".activity-detail-author").appendChild(deleteButton);
		}
		state.activityCommentReplyTarget = null;
		$("activity-comment-input").value = "";
		var activityCommentSubmit = $("activity-comment-form") && $("activity-comment-form").querySelector("button[type='submit']");
		if (activityCommentSubmit) {
			activityCommentSubmit.disabled = false;
			activityCommentSubmit.textContent = "发送";
		}
		renderActivityReplyTarget();
		renderActivityComments(activity, state.activityComments.has(String(activity.id)) ? "" : "正在加载评论...");
		loadActivityComments(activity.id).then(function () {
			if (state.currentActivity && sameId(state.currentActivity.id, activity.id)) {
				renderActivityComments(activity);
			}
		}).catch(function () {});
		modal.hidden = false;
		modal.classList.add("active");
		modal.setAttribute("aria-hidden", "false");
		document.body.classList.add("activity-detail-open");
		var closeButton = activityDetailElement("activity-detail-close", "[data-action='close-activity-detail']");
		window.setTimeout(function () { if (closeButton) closeButton.focus(); }, 0);
	}

	function closeActivityDetail(fromHistory) {
		if (!fromHistory) {
			requestHistoryClose("activity-detail", function () { closeActivityDetail(true); });
			return;
		}
		var modal = activityDetailElement("activity-detail-modal", "[data-activity-detail-modal]");
		if (!modal) {
			return;
		}
		modal.classList.remove("active");
		modal.hidden = true;
		modal.setAttribute("aria-hidden", "true");
		var frame = activityDetailElement("activity-detail-media", "[data-activity-detail-media]");
		if (frame) {
			Array.prototype.forEach.call(frame.querySelectorAll("video"), function (video) {
				video.pause();
				video.removeAttribute("src");
				video.load();
			});
			frame.replaceChildren();
		}
		state.currentActivity = null;
		state.activityCommentReplyTarget = null;
		document.body.classList.remove("activity-detail-open");
		restoreLayerFocus("activity-detail");
	}

	function requestDeleteActivity(activityId, skipHistory) {
		var activity = state.activities.find(function (item) { return sameId(item.id, activityId); });
		var dialog = $("activity-delete-dialog");
		if (!activity || !activity.can_delete || !dialog) return;
		state.deleteActivityId = activity.id;
		state.deleteReturnLayer = window.history.state && window.history.state.communityLayer || null;
		if (!skipHistory) pushUiLayer("activity-delete");
		dialog.hidden = false;
		dialog.setAttribute("aria-hidden", "false");
		window.setTimeout(function () {
			var cancel = dialog.querySelector('[data-action="cancel-delete-activity"]');
			if (cancel) cancel.focus();
		}, 0);
	}

	function closeDeleteActivity(fromHistory) {
		if (!fromHistory) {
			requestHistoryClose("activity-delete", function () { closeDeleteActivity(true); });
			return;
		}
		var dialog = $("activity-delete-dialog");
		if (dialog) {
			dialog.hidden = true;
			dialog.setAttribute("aria-hidden", "true");
		}
		state.deleteActivityId = null;
		state.deleteReturnLayer = null;
		restoreLayerFocus("activity-delete");
	}

	function confirmDeleteActivity() {
		var activityId = state.deleteActivityId;
		var returnLayer = state.deleteReturnLayer;
		var button = $("activity-delete-confirm");
		if (!activityId || !button) return;
		button.disabled = true;
		button.textContent = "删除中...";
		api("Activities/" + encodeURIComponent(activityId), { method: "DELETE", body: {} }).then(function () {
			state.activities = state.activities.filter(function (item) { return !sameId(item.id, activityId); });
			state.activitiesSignature = activitiesSignature(state.activities);
			persistDataCache();
			closeDeleteActivity(true);
			if (state.currentActivity && sameId(state.currentActivity.id, activityId)) closeActivityDetail(true);
			renderActivities(state.activities);
			showToast("动态已彻底删除");
			if (window.history.state && window.history.state.communityLayer === "activity-delete") {
				window.history.go(returnLayer === "activity-detail" ? -2 : -1);
			}
		}).catch(function (error) {
			showToast(error.message);
		}).finally(function () {
			button.disabled = false;
			button.textContent = "彻底删除";
		});
	}

	function renderActivityFilePreview() {
		var preview = $("activity-image-preview");
		var count = $("activity-image-count");
		if (!preview || !count) return;
		preview.innerHTML = "";
		state.activityFiles.forEach(function (file, index) {
			var item = document.createElement("div");
			item.className = "activity-preview-item";
			var isVideo = /^video\//.test(file.type || "");
			var media = document.createElement(isVideo ? "video" : "img");
			media.src = URL.createObjectURL(file);
			if (isVideo) {
				media.muted = true;
				media.playsInline = true;
				media.preload = "metadata";
				media.setAttribute("aria-label", "待发布视频");
				media.onloadedmetadata = function () { URL.revokeObjectURL(media.src); };
			} else {
				media.alt = "待发布照片";
				media.onload = function () { URL.revokeObjectURL(media.src); };
			}
			item.appendChild(media);
			var remove = document.createElement("button");
			remove.type = "button";
			remove.dataset.action = "remove-activity-image";
			remove.dataset.index = String(index);
			remove.setAttribute("aria-label", "移除图片或视频");
			remove.textContent = "×";
			item.appendChild(remove);
			preview.appendChild(item);
		});
		var totalBytes = state.activityFiles.reduce(function (sum, file) { return sum + Number(file.size || 0); }, 0);
		count.textContent = state.activityFiles.length ? "已选择 " + state.activityFiles.length + "/20 个，共 " + formatBytes(totalBytes) : "";
	}

	function formatBytes(bytes) {
		if (bytes >= 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(bytes >= 100 * 1024 * 1024 ? 0 : 1) + "MB";
		return Math.max(0, Math.round(bytes / 1024)) + "KB";
	}

	function validateActivityFiles(files) {
		var combined = state.activityFiles.concat(files || []);
		if (combined.length > MAX_ACTIVITY_FILES) return "一次最多选择 20 个文件。";
		var supported = /^(?:image\/(jpeg|png|webp|gif|heic|heif)|video\/(mp4|quicktime|webm))$/;
		for (var index = 0; index < combined.length; index += 1) {
			var file = combined[index];
			if (!supported.test(file.type || "")) return "“" + file.name + "”的格式不受支持。";
			if (/^image\//.test(file.type || "") && file.size > MAX_IMAGE_BYTES) return "图片“" + file.name + "”超过 25MB。";
			if (/^video\//.test(file.type || "") && file.size > MAX_VIDEO_BYTES) return "视频“" + file.name + "”超过 250MB。";
		}
		var total = combined.reduce(function (sum, file) { return sum + Number(file.size || 0); }, 0);
		return total > MAX_UPLOAD_BYTES ? "本次文件总大小超过 500MB。" : "";
	}

	function setUploadProgress(percent, visible) {
		var progress = $("activity-upload-progress");
		var bar = $("activity-upload-progress-bar");
		if (progress) {
			progress.hidden = !visible;
			progress.setAttribute("aria-label", "上传进度 " + Math.round(percent || 0) + "%");
			progress.setAttribute("aria-valuenow", String(Math.round(percent || 0)));
		}
		if (bar) bar.style.width = Math.max(0, Math.min(100, percent || 0)) + "%";
	}

	function openActivityComposer(skipHistory) {
		if (!skipHistory) pushUiLayer("compose");
		state.composeReturnView = "activities-view";
		var title = $("activity-title");
		var body = $("activity-body");
		if (title) title.value = state.activityDraftTitle;
		if (body) body.value = state.activityDraftBody;
		renderActivityFilePreview();
		showOnly("activity-compose-view");
		window.setTimeout(function () { if (title) title.focus(); }, 0);
	}

	function closeCompose(fromHistory) {
		if (state.activityBusy && state.activityUploadRequest) cancelActivityUpload();
		if (!fromHistory) {
			requestHistoryClose("compose", function () { closeCompose(true); });
			return;
		}
		var activityTitle = $("activity-title");
		var activityBody = $("activity-body");
		if (activityTitle) state.activityDraftTitle = activityTitle.value;
		if (activityBody) state.activityDraftBody = activityBody.value;
		showOnly(state.composeReturnView || "activities-view");
		restoreLayerFocus("compose");
	}

	function handleContextAdd() {
		openActivityComposer();
	}

	function submitActivityViaServer() {
		var form = new FormData();
		form.append("title", $("activity-title").value.trim());
		form.append("body", $("activity-body").value.trim());
		state.activityFiles.forEach(function (file) {
			form.append("images[]", file, file.name);
		});
		return new Promise(function (resolve, reject) {
			var request = new XMLHttpRequest();
			state.activityUploadRequest = request;
			request.open("POST", API + "Activities", true);
			request.withCredentials = true;
			request.setRequestHeader("Accept", "application/json");
			request.setRequestHeader("X-Requested-With", "XMLHttpRequest");
			var token = csrfToken();
			if (token) request.setRequestHeader("X-XSRF-TOKEN", token);
			request.upload.addEventListener("progress", function (event) {
				if (event.lengthComputable) setUploadProgress(event.loaded / event.total * 100, true);
			});
			request.addEventListener("load", function () {
				var payload = null;
				try { payload = request.responseText ? JSON.parse(request.responseText) : null; } catch (_error) {}
				if (request.status === 401 || request.status === 419) {
					state.user = null;
					showLogin();
				}
				if (request.status >= 200 && request.status < 300) {
					setUploadProgress(100, true);
					resolve(resource(payload));
				} else {
					reject(new Error(apiErrorMessage(payload, "上传失败：" + request.status)));
				}
			});
			request.addEventListener("error", function () { reject(new Error("上传失败，请检查网络后重试。")); });
			request.addEventListener("abort", function () {
				var error = new Error("上传已取消");
				error.cancelled = true;
				reject(error);
			});
			request.send(form);
		});
	}

	function submitActivity() {
		if (!state.activityFiles.length) { showToast("请至少选择一张图片或一个视频"); return Promise.resolve(); }
		var validationError = validateActivityFiles([]);
		if (validationError) { showToast(validationError); return Promise.resolve(); }
		state.activityBusy = true;
		var submit = $("activity-submit");
		submit.disabled = true;
		submit.textContent = "正在上传到服务器...";
		$("activity-upload-cancel").hidden = false;
		setUploadProgress(0, true);
		return submitActivityViaServer().then(function (payload) {
			var data = resource(payload) || {};
			var createdActivity = data.activity;
			state.activityFiles = [];
			state.activityDraftTitle = "";
			state.activityDraftBody = "";
			$("activity-form").reset();
			renderActivityFilePreview();
			showToast("动态已发布");
			if (createdActivity && createdActivity.id !== undefined) {
				resetActivityFeed();
				showOnly("activities-view");
				if (window.history.state && window.history.state.communityLayer === "compose") {
					window.history.replaceState({ communityLayer: null }, "", window.location.href);
				}
				return loadActivities(null, { force: true }).then(function () { return createdActivity; });
			}
			state.activitiesLoaded = false;
			return loadActivities(null, { force: true });
		}).catch(function (error) { if (!error.cancelled) showToast(error.message); }).finally(function () {
			state.activityBusy = false;
			state.activityUploadRequest = null;
			submit.disabled = false;
			submit.textContent = "发布";
			$("activity-upload-cancel").hidden = true;
			setUploadProgress(0, false);
		});
	}

	function cancelActivityUpload() {
		if (state.activityUploadRequest) state.activityUploadRequest.abort();
	}

	function loadProfile(skipHistory) {
		if (!skipHistory && document.body.dataset.view !== "profile-view") pushUiLayer("profile");
		showOnly("profile-view");
		setUserStatus();
	}

	function openMyActivities(skipHistory) {
		if (!skipHistory) pushUiLayer("my-activities");
		state.activityScope = "mine";
		state.activityFilter = "all";
		resetActivityFeed();
		loadActivities(null, { force: true });
	}

	function closeMyActivities(fromHistory) {
		if (!fromHistory) {
			requestHistoryClose("my-activities", function () { closeMyActivities(true); });
			return;
		}
		state.activityScope = "all";
		state.activityFilter = "all";
		resetActivityFeed();
		loadProfile(true);
		restoreLayerFocus("my-activities");
	}

	function openCommunityFeed() {
		var changed = state.activityScope !== "all";
		state.activityScope = "all";
		if (changed) {
			state.activityFilter = "all";
			resetActivityFeed();
		}
		loadActivities(null, changed ? { force: true } : {});
	}

function formatRelativeTime(value) {
        if (!value) {
            return "";
        }
        var date = new Date(String(value).replace(" ", "T"));
        if (Number.isNaN(date.getTime())) {
            return value;
        }
        var now = new Date();
        var todayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        var yesterdayStart = new Date(todayStart);
        yesterdayStart.setDate(yesterdayStart.getDate() - 1);
        var dateStart = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        var time = date.toLocaleTimeString("zh-CN", { hour: "2-digit", minute: "2-digit" });
        if (dateStart.getTime() === todayStart.getTime()) {
            return "今天 " + time;
        }
        if (dateStart.getTime() === yesterdayStart.getTime()) {
            return "昨天 " + time;
        }
        var mm = (date.getMonth() + 1).toString().padStart(2, "0");
        var dd = date.getDate().toString().padStart(2, "0");
        if (date.getFullYear() === now.getFullYear()) {
            return mm + "-" + dd;
        }
        return date.getFullYear() + "-" + mm + "-" + dd;
    }

	function parseReplyBody(body) {
		var match = String(body || "").match(/^回复(.+?)[:：]\s*([\s\S]*)$/);
		if (match) {
			return { replyTo: match[1], text: match[2] };
		}
		return { replyTo: "", text: String(body || "") };
	}

	function setAuthMode(mode) {
		state.authMode = state.setupRequired || mode === "register" ? "register" : "login";
		var isRegister = state.authMode === "register";
		var loginFields = $("auth-login-fields");
		var registerFields = $("auth-register-fields");
		var submit = $("auth-submit");
		if (loginFields) {
			loginFields.hidden = isRegister;
			Array.prototype.forEach.call(loginFields.querySelectorAll("input"), function (input) {
				input.required = !isRegister;
			});
		}
		if (registerFields) {
			registerFields.hidden = !isRegister;
			Array.prototype.forEach.call(registerFields.querySelectorAll("input"), function (input) {
				input.required = isRegister && input.id !== "register-invite-code";
			});
		}
		var inviteField = $("register-invite-field");
		var inviteInput = $("register-invite-code");
		var invitationRequired = isRegister && !state.setupRequired && state.registrationMode === "invite";
		if (inviteField) inviteField.hidden = !invitationRequired;
		if (inviteInput) inviteInput.required = invitationRequired;
		var authTabs = $("auth-tabs");
		if (authTabs) authTabs.hidden = state.setupRequired;
		Array.prototype.forEach.call(document.querySelectorAll(".auth-tab"), function (tab) {
			var active = tab.dataset.mode === state.authMode;
			tab.classList.toggle("is-active", active);
			tab.setAttribute("aria-selected", active ? "true" : "false");
		});
		$("auth-title").textContent = state.setupRequired ? "创建初始管理员" : (isRegister ? "注册社区账号" : "登录社区");
		$("auth-subtitle").textContent = state.setupRequired
			? "这是全新站点。该账号将拥有全部管理权限，请妥善保管密码。"
			: (isRegister ? "注册后即可发布动态、评论互动。" : "请输入账号后查看大家的动态。");
		if (submit) {
			submit.textContent = state.setupRequired ? "创建管理员" : (isRegister ? "注册" : "登录");
		}
		$("login-error").textContent = "";
	}

	function loadRegistrationStatus() {
		return api("Auth::registration", { timeoutMs: SESSION_TIMEOUT_MS }).then(function (payload) {
			var data = resource(payload) || {};
			state.registrationMode = data.mode === "open" ? "open" : "invite";
			state.setupRequired = data.setup_required === true || data.setup_required === 1 || data.setup_required === "1";
			setAuthMode(state.authMode);
		}).catch(function () {
			state.registrationMode = "invite";
			state.setupRequired = false;
			setAuthMode(state.authMode);
		});
	}

	function submitRegister() {
		var username = $("register-username").value.trim();
		var password = $("register-password").value;
		var confirm = $("register-password-confirm").value;
		var inviteCode = $("register-invite-code") ? $("register-invite-code").value.trim() : "";
		if (!username || !password || !confirm) {
			$("login-error").textContent = "请填写用户名和密码。";
			return;
		}
		if (password !== confirm) {
			$("login-error").textContent = "两次输入的密码不一致。";
			return;
		}
		if (!state.setupRequired && state.registrationMode === "invite" && !inviteCode) {
			$("login-error").textContent = "请输入有效邀请码。";
			return;
		}
		var wasSetup = state.setupRequired;
		primeSession().then(function () {
			return api("Auth::register", {
				method: "POST",
				body: {
					username: username,
					password: password,
					password_confirmation: confirm,
					invite_code: inviteCode
				}
			});
		}).then(function () {
			return api("Auth::user");
		}).then(function (payload) {
			state.user = resource(payload);
			if (!isLoggedIn(state.user)) {
				throw new Error("注册失败");
			}
			$("register-password").value = "";
			$("register-password-confirm").value = "";
			if ($("register-invite-code")) $("register-invite-code").value = "";
			state.setupRequired = false;
			restoreAndRevalidateUserCache();
			loadActivities();
			recordSiteEntryInBackground();
			if (wasSetup) showToast("初始管理员创建成功。");
		}).catch(function (error) {
			if (wasSetup && (error.status === 409 || error.status === 422)) {
				loadRegistrationStatus().finally(function () {
					$("login-error").textContent = error.message || "初始管理员已创建，请返回登录。";
				});
				return;
			}
			$("login-error").textContent = error.message;
		});
	}

	function bindEvents() {
		document.body.addEventListener("pointerdown", function (event) {
			var target = event.target.closest(".btn, .bottom-fab");
			if (!target || target.disabled || target.getAttribute("aria-disabled") === "true") {
				return;
			}
			var rect = target.getBoundingClientRect();
			var size = Math.max(rect.width, rect.height) * 1.15;
			var ripple = document.createElement("span");
			ripple.className = "micro-ripple";
			ripple.style.width = size + "px";
			ripple.style.height = size + "px";
			ripple.style.left = (event.clientX - rect.left - size / 2) + "px";
			ripple.style.top = (event.clientY - rect.top - size / 2) + "px";
			target.appendChild(ripple);
			window.setTimeout(function () { ripple.remove(); }, 700);
		});

		document.body.addEventListener("click", function (event) {
			if (event.target === $("activity-delete-dialog")) {
				closeDeleteActivity();
				return;
			}
			if (event.target === $("activity-image-modal")) {
				closeActivityImage();
				return;
			}
			if (event.target === $("activity-detail-modal")) {
				closeActivityDetail();
				return;
			}
			var target = event.target.closest("[data-action]");
			if (!target) return;
			var action = target.dataset.action;
			if (action === "context-add") {
				handleContextAdd();
			} else if (action === "set-auth-mode") {
				setAuthMode(target.dataset.mode);
			} else if (action === "home") {
				openCommunityFeed();
			} else if (action === "open-profile") {
				loadProfile();
			} else if (action === "open-activities") {
				openCommunityFeed();
			} else if (action === "open-my-activities") {
				openMyActivities();
			} else if (action === "close-my-activities") {
				closeMyActivities();
			} else if (action === "set-activity-filter") {
				setActivityFilter(target.dataset.filter);
			} else if (action === "open-activity-composer") {
				openActivityComposer();
			} else if (action === "close-compose") {
				closeCompose();
			} else if (action === "pick-activity-images") {
				$("activity-images").click();
			} else if (action === "remove-activity-image") {
				state.activityFiles.splice(Number(target.dataset.index), 1);
				renderActivityFilePreview();
			} else if (action === "load-activity-images") {
				loadActivityImages(target.dataset.activityId);
			} else if (action === "load-more-activities") {
				loadMoreActivities();
			} else if (action === "open-activity-detail") {
				openActivityDetail(target.dataset.activityId);
			} else if (action === "close-activity-detail") {
				closeActivityDetail();
			} else if (action === "open-activity-image") {
				openActivityImage(target.dataset.imageUrl);
			} else if (action === "close-activity-image") {
				closeActivityImage();
			} else if (action === "request-delete-activity") {
				requestDeleteActivity(target.dataset.activityId);
			} else if (action === "cancel-delete-activity") {
				closeDeleteActivity();
			} else if (action === "confirm-delete-activity") {
				confirmDeleteActivity();
			} else if (action === "cancel-activity-upload") {
				cancelActivityUpload();
			} else if (action === "logout") {
				api("Auth::logout", { method: "POST", body: {} }).finally(function () {
					state.user = null;
					showLogin();
				});
			}
		});

		$("login-form").addEventListener("submit", function (event) {
			event.preventDefault();
			$("login-error").textContent = "";
			if (state.authMode === "register") {
				submitRegister();
				return;
			}
			primeSession().then(function () {
				return api("Auth::login", {
				method: "POST",
				body: {
					username: $("login-username").value.trim(),
					password: $("login-password").value,
					remember_me: $("login-remember").checked
				}
				});
			}).then(function () {
				return api("Auth::user");
			}).then(function (payload) {
				state.user = resource(payload);
				if (!isLoggedIn(state.user)) {
					throw new Error("登录失败");
				}
				$("login-password").value = "";
				restoreAndRevalidateUserCache();
				loadActivities();
				recordSiteEntryInBackground();
			}).catch(function (error) {
				$("login-error").textContent = error.message;
			});
		});

		if ($("activity-form")) {
			$("activity-form").addEventListener("submit", function (event) { event.preventDefault(); submitActivity(); });
			$("activity-title").addEventListener("input", function () { state.activityDraftTitle = this.value; });
			$("activity-body").addEventListener("input", function () { state.activityDraftBody = this.value; });
			$("activity-images").addEventListener("change", function (event) {
				var files = Array.prototype.slice.call(event.target.files || []);
				var error = validateActivityFiles(files);
				if (error) {
					showToast(error);
				} else {
					state.activityFiles = state.activityFiles.concat(files);
				}
				event.target.value = "";
				renderActivityFilePreview();
			});
		}

		$("activity-comment-form").addEventListener("submit", function (event) {
			event.preventDefault();
			if (state.activityCommentBusy) {
				return;
			}
			var input = $("activity-comment-input");
			addActivityComment(input.value).then(function (sent) {
				if (sent) {
					input.value = "";
					input.focus();
				}
			});
		});

		$("activity-comment-list").addEventListener("click", function (event) {
			var replyButton = event.target.closest("button[data-action='reply-activity-comment']");
			if (!replyButton) {
				return;
			}
			state.activityCommentReplyTarget = { author: replyButton.dataset.commentAuthor || "社区成员" };
			renderActivityReplyTarget();
			var input = $("activity-comment-input");
			input.placeholder = "回复" + state.activityCommentReplyTarget.author + "...";
			input.focus();
		});

		$("activity-comment-form").addEventListener("click", function (event) {
			var cancelButton = event.target.closest("button[data-action='cancel-activity-reply']");
			if (!cancelButton) {
				return;
			}
			event.preventDefault();
			state.activityCommentReplyTarget = null;
			renderActivityReplyTarget();
			$("activity-comment-input").placeholder = "说点什么...";
		});

		document.addEventListener("keydown", function (event) {
			if (trapDialogFocus(event, $("activity-delete-dialog"))) return;
			if (trapDialogFocus(event, $("activity-image-modal"))) return;
			var detailForFocus = activityDetailElement("activity-detail-modal", "[data-activity-detail-modal]");
			if (trapDialogFocus(event, detailForFocus)) return;
			if (event.key === "Escape") {
				if ($("activity-delete-dialog") && !$("activity-delete-dialog").hidden) { closeDeleteActivity(); return; }
				if ($("activity-image-modal") && !$("activity-image-modal").hidden) { closeActivityImage(); return; }
				var detailModal = activityDetailElement("activity-detail-modal", "[data-activity-detail-modal]");
				if (detailModal && !detailModal.hidden) { closeActivityDetail(); return; }
				if (document.body.dataset.view === "activity-compose-view") { closeCompose(); return; }
				if (state.activityScope === "mine") { closeMyActivities(); return; }
			}
			var activityCardTarget = event.target && event.target.closest ? event.target.closest("[data-action='open-activity-detail']") : null;
			if ((event.key === "Enter" || event.key === " ") && activityCardTarget && event.target.tagName !== "BUTTON") {
				event.preventDefault();
				openActivityDetail(activityCardTarget.dataset.activityId);
			}
		});

		window.addEventListener("popstate", function () {
			if ($("activity-delete-dialog") && !$("activity-delete-dialog").hidden) { closeDeleteActivity(true); return; }
			if ($("activity-image-modal") && !$("activity-image-modal").hidden) { closeActivityImage(true); return; }
			var detailModal = activityDetailElement("activity-detail-modal", "[data-activity-detail-modal]");
			if (detailModal && !detailModal.hidden) { closeActivityDetail(true); return; }
			if (document.body.dataset.view === "activity-compose-view") { closeCompose(true); return; }
			if (state.activityScope === "mine") { closeMyActivities(true); return; }
			if (document.body.dataset.view === "profile-view") { openCommunityFeed(); }
		});
	}

	function init() {
		document.body.classList.add("amicro-enabled");
		bindEvents();

		// Keep the recovery shell visible until the CSRF session is ready, then
		// resolve the current user. Calling both in parallel causes a first-load 419.
		primeSession().then(function () {
			return Promise.all([api("Auth::user", { timeoutMs: SESSION_TIMEOUT_MS }), loadRegistrationStatus()]);
		}).then(function (payload) {
			state.user = resource(payload[0]);
			if (isLoggedIn(state.user)) {
				restoreAndRevalidateUserCache();
				loadActivities();
				recordSiteEntryInBackground();
				return;
			}
			showLogin();
		}).catch(function () {
			showLogin();
		});
	}

	window.addEventListener("DOMContentLoaded", init);
})();
