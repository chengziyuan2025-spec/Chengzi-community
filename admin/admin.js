(function () {
	"use strict";

	var API = "/api/v2/";
	var state = {
		user: null,
		loading: false,
		securityLoading: false,
		security: null,
		registrationLoading: false,
		registration: { mode: "invite", invites: [] },
		auditLoading: false,
		audit: { page: 1, perPage: 50, total: 0, lastPage: 1 }
	};
	function $(id) { return document.getElementById(id); }

	function csrfToken() {
		var cookie = document.cookie.split(";").find(function (row) {
			return /^\s*(X-)?[XC]SRF-TOKEN\s*=/.test(row);
		});
		if (cookie === undefined) { return ""; }
		var encoded = cookie.split("=").slice(1).join("=").trim();
		try { return decodeURIComponent(encoded); } catch (_error) { return encoded; }
	}

	function headers() {
		var result = { Accept: "application/json", "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest" };
		var token = csrfToken();
		if (token) { result["X-XSRF-TOKEN"] = token; }
		return result;
	}

	function resource(payload) { return payload && payload.data !== undefined ? payload.data : payload; }
	function apiErrorMessage(payload, fallback) {
		var source = payload && payload.error ? payload.error : payload;
		var details = source && (source.errors || source.details);
		if (details && typeof details === "object") {
			var key = Object.keys(details)[0];
			if (key) { return Array.isArray(details[key]) ? String(details[key][0]) : String(details[key]); }
		}
		return source && source.message ? String(source.message) : (typeof source === "string" ? source : fallback);
	}

	function parseJson(response) {
		return response.text().then(function (text) { return text ? JSON.parse(text) : null; });
	}

	function api(path, options) {
		var opts = Object.assign({ credentials: "same-origin", headers: headers() }, options || {});
		if (opts.body && typeof opts.body !== "string") { opts.body = JSON.stringify(opts.body); }
		return fetch(API + path, opts).then(function (response) {
			return parseJson(response).catch(function () { return null; }).then(function (payload) {
				if (!response.ok) {
					var error = new Error(apiErrorMessage(payload, "请求失败：" + response.status));
					error.status = response.status;
					throw error;
				}
				return resource(payload);
			});
		});
	}

	function primeSession() {
		if (csrfToken()) { return Promise.resolve(); }
		return fetch("/", {
			credentials: "same-origin",
			headers: { Accept: "text/html", "X-Requested-With": "XMLHttpRequest" }
		}).catch(function () { return null; });
	}

	function isAdministrator(user) {
		return Boolean(user && (user.may_administrate === true || user.may_administrate === 1 || user.may_administrate === "1"));
	}

	function setVisible(id) {
		["admin-login", "admin-denied", "admin-dashboard"].forEach(function (sectionId) {
			$(sectionId).hidden = sectionId !== id;
		});
	}

	function setUser(user) {
		var target = $("admin-user");
		var name = user && (user.display_name || user.username);
		target.hidden = !name;
		target.textContent = name || "";
	}

	function setStatus(message, isError) {
		var target = $("admin-status");
		target.textContent = message || "";
		target.classList.toggle("is-error", Boolean(isError));
	}

	function setSecurityStatus(message, isError) {
		var target = $("security-status");
		target.textContent = message || "";
		target.classList.toggle("is-error", Boolean(isError));
	}

	function setAuditStatus(message, isError) {
		var target = $("audit-status-message");
		target.textContent = message || "";
		target.classList.toggle("is-error", Boolean(isError));
	}

	function setRegistrationStatus(message, isError) {
		var target = $("registration-status");
		target.textContent = message || "";
		target.classList.toggle("is-error", Boolean(isError));
	}

	function showLogin(message) {
		state.user = null;
		setUser(null);
		$("admin-login-error").textContent = message || "";
		setVisible("admin-login");
	}

	function showDenied() { setUser(state.user); setVisible("admin-denied"); }
	function showDashboard() { setUser(state.user); setVisible("admin-dashboard"); }

	function formatDateTime(value) {
		if (!value) { return "未知时间"; }
		var date = new Date(String(value).replace(" ", "T"));
		if (Number.isNaN(date.getTime())) { return String(value); }
		return date.toLocaleString("zh-CN", { year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit", second: "2-digit" });
	}

	function isTrue(value) { return value === true || value === 1 || value === "1"; }

	function securitySnapshot(payload) {
		var data = resource(payload) || {};
		return {
			trustedDeviceOnlyEnabled: isTrue(data.trusted_device_only_enabled !== undefined ? data.trusted_device_only_enabled : data.desktop_protection_enabled),
			currentDevice: data.current_device || {},
			devices: Array.isArray(data.devices) ? data.devices : []
		};
	}

	function deviceName(device) {
		return device.label || device.user_agent || "未命名设备";
	}

	function deviceType(device) {
		return isTrue(device.is_desktop) ? "电脑" : "移动设备";
	}

	function currentDeviceMessage(device) {
		if (!device || !device.id) { return "未能识别当前设备，刷新页面后再试。"; }
		return isTrue(device.is_trusted_device !== undefined ? device.is_trusted_device : device.is_trusted_desktop) ? "当前设备已受信任，可以在限制开启后登录。" : "当前设备尚未受信任。";
	}

	function renderTrustedDevices(snapshot) {
		var list = $("security-trusted-devices");
		var trustedDevices = snapshot.devices.filter(function (device) { return isTrue(device.is_trusted_device !== undefined ? device.is_trusted_device : device.is_trusted_desktop); });
		list.replaceChildren();
		$("security-device-count").textContent = trustedDevices.length ? (trustedDevices.length + " 个") : "暂无";
		if (!trustedDevices.length) {
			var empty = document.createElement("p");
			empty.className = "empty-state";
			empty.textContent = "还没有已信任的设备。";
			list.appendChild(empty);
			return;
		}
		trustedDevices.forEach(function (device) {
			var row = document.createElement("article");
			var copy = document.createElement("div");
			var name = document.createElement("p");
			var details = document.createElement("p");
			var lastSeen = document.createElement("p");
			var revoke = document.createElement("button");
			row.className = "trusted-device-row";
			copy.className = "trusted-device-copy";
			name.className = "trusted-device-name";
			name.textContent = deviceName(device);
			details.className = "trusted-device-meta";
			details.textContent = device.user_agent && device.label ? device.user_agent : deviceType(device);
			lastSeen.className = "trusted-device-meta";
			lastSeen.textContent = "最近活动：" + formatDateTime(device.last_seen_at || device.created_at);
			copy.appendChild(name);
			copy.appendChild(details);
			copy.appendChild(lastSeen);
			if (String(device.id) === String(snapshot.currentDevice.id)) {
				var current = document.createElement("p");
				current.className = "trusted-device-current";
				current.textContent = "当前设备";
				copy.appendChild(current);
			}
			revoke.className = "button";
			revoke.type = "button";
			revoke.textContent = "取消信任";
			revoke.addEventListener("click", function () { revokeDevice(device.id, revoke); });
			row.appendChild(copy);
			row.appendChild(revoke);
			list.appendChild(row);
		});
	}

	function renderSecurity(snapshot) {
		var current = snapshot.currentDevice;
		var trust = $("security-trust-current");
		$("security-current-device").textContent = currentDeviceMessage(current);
		var currentTrusted = isTrue(current.is_trusted_device !== undefined ? current.is_trusted_device : current.is_trusted_desktop);
		trust.disabled = state.securityLoading || !current.id || currentTrusted;
		trust.textContent = currentTrusted ? "当前设备已信任" : "信任当前设备";
		$("security-trusted-device-only").checked = snapshot.trustedDeviceOnlyEnabled;
		$("security-trusted-device-only").disabled = state.securityLoading;
		$("security-refresh").disabled = state.securityLoading;
		renderTrustedDevices(snapshot);
	}

	function eventDetail(label, value) {
		var row = document.createElement("div");
		var key = document.createElement("dt");
		var content = document.createElement("dd");
		key.textContent = label;
		content.textContent = value || "未知";
		row.appendChild(key);
		row.appendChild(content);
		return row;
	}

	function renderEvents(events) {
		var list = $("entry-events");
		list.replaceChildren();
		if (!events.length) {
			var empty = document.createElement("p");
			empty.className = "empty-state";
			empty.textContent = "还没有进入记录。";
			list.appendChild(empty);
			return;
		}
		events.forEach(function (event) {
			var item = document.createElement("article");
			var heading = document.createElement("h2");
			var username = document.createElement("p");
			var details = document.createElement("dl");
			item.className = "event-item";
			heading.textContent = event.display_name || event.username || "账号";
			username.className = "event-username";
			username.textContent = event.username || "";
			details.appendChild(eventDetail("进入时间", formatDateTime(event.logged_in_at)));
			details.appendChild(eventDetail("IP 地址", event.ip_address));
			details.appendChild(eventDetail("浏览器", event.user_agent));
			item.appendChild(heading);
			item.appendChild(username);
			item.appendChild(details);
			list.appendChild(item);
		});
	}

	function positiveInteger(value, fallback) {
		var parsed = Number(value);
		return Number.isInteger(parsed) && parsed > 0 ? parsed : fallback;
	}

	function auditSnapshot(payload) {
		var candidates = [payload, resource(payload)];
		var events = [];
		var pagination = {};
		candidates.forEach(function (candidate) {
			if (Array.isArray(candidate) && !events.length) { events = candidate; return; }
			if (!candidate || typeof candidate !== "object") { return; }
			if (Array.isArray(candidate.events)) { events = candidate.events; }
			else if (Array.isArray(candidate.data) && !events.length) { events = candidate.data; }
			if (candidate.pagination && typeof candidate.pagination === "object") { pagination = candidate.pagination; }
			if (candidate.data && typeof candidate.data === "object" && !Array.isArray(candidate.data)) {
				if (Array.isArray(candidate.data.events)) { events = candidate.data.events; }
				if (candidate.data.pagination && typeof candidate.data.pagination === "object") { pagination = candidate.data.pagination; }
			}
		});
		return {
			events: events,
			pagination: {
				page: positiveInteger(pagination.page, state.audit.page),
				perPage: positiveInteger(pagination.per_page, state.audit.perPage),
				total: Math.max(0, Number(pagination.total) || 0),
				lastPage: positiveInteger(pagination.last_page, 1)
			}
		};
	}

	function compactMetadata(metadata) {
		if (metadata === undefined || metadata === null || metadata === "") { return "无"; }
		var value = metadata;
		if (typeof value === "string") {
			try { value = JSON.parse(value); } catch (_error) { return value; }
		}
		try { return JSON.stringify(value); } catch (_error) { return "无法读取"; }
	}

	function auditFilters() {
		return {
			action: $("audit-action").value.trim(),
			actor: $("audit-actor").value.trim(),
			status: $("audit-status").value
		};
	}

	function auditDetail(label, value) {
		return eventDetail(label, value === undefined || value === null || value === "" ? "未知" : String(value));
	}

	function auditActor(event) {
		var name = event.display_name || event.username;
		if (name && event.username && name !== event.username) { return name + " (" + event.username + ")"; }
		return name || "未登录访客";
	}

	function renderAuditEvents(events) {
		var list = $("audit-events");
		list.replaceChildren();
		if (!events.length) {
			var empty = document.createElement("p");
			empty.className = "empty-state";
			empty.textContent = "没有符合条件的操作日志。";
			list.appendChild(empty);
			return;
		}
		events.forEach(function (event) {
			event = event && typeof event === "object" ? event : {};
			var item = document.createElement("article");
			var heading = document.createElement("div");
			var action = document.createElement("h3");
			var timestamp = document.createElement("p");
			var actor = document.createElement("p");
			var details = document.createElement("dl");
			item.className = "audit-event-item";
			heading.className = "audit-event-heading";
			action.textContent = event.action || "未知操作";
			timestamp.className = "audit-event-time";
			timestamp.textContent = formatDateTime(event.created_at);
			actor.className = "audit-event-actor";
			actor.textContent = "账号：" + auditActor(event);
			details.appendChild(auditDetail("结果", event.status));
			details.appendChild(auditDetail("请求", (event.method || "未知") + " " + (event.route || "未知")));
			details.appendChild(auditDetail("IP 地址", event.ip_address));
			details.appendChild(auditDetail("浏览器", event.user_agent));
			details.appendChild(auditDetail("元数据", compactMetadata(event.metadata)));
			heading.appendChild(action);
			heading.appendChild(timestamp);
			item.appendChild(heading);
			item.appendChild(actor);
			item.appendChild(details);
			list.appendChild(item);
		});
	}

	function renderAuditPagination() {
		var audit = state.audit;
		var page = Math.min(audit.page, audit.lastPage);
		$("audit-page-indicator").textContent = "第 " + page + " / " + audit.lastPage + " 页，共 " + audit.total + " 条";
		$("audit-previous").disabled = state.auditLoading || page <= 1;
		$("audit-next").disabled = state.auditLoading || page >= audit.lastPage;
		$("audit-refresh").disabled = state.auditLoading;
		$("audit-filter-submit").disabled = state.auditLoading;
	}

	function loadAuditEvents(page) {
		if (state.auditLoading) { return Promise.resolve(); }
		state.auditLoading = true;
		state.audit.page = positiveInteger(page, state.audit.page);
		renderAuditPagination();
		setAuditStatus("正在加载操作日志...");
		var params = new URLSearchParams({ page: String(state.audit.page), per_page: String(state.audit.perPage) });
		var filters = auditFilters();
		Object.keys(filters).forEach(function (key) {
			if (filters[key]) { params.set(key, filters[key]); }
		});
		return api("OperationAuditEvents?" + params.toString()).then(function (payload) {
			var snapshot = auditSnapshot(payload);
			state.audit = snapshot.pagination;
			renderAuditEvents(snapshot.events);
			setAuditStatus(snapshot.events.length ? "已加载 " + snapshot.events.length + " 条操作日志。" : "");
		}).catch(function (error) {
			if (error.status === 401) { showLogin("登录状态已失效，请重新登录。"); }
			else if (error.status === 403) { showDenied(); }
			else { setAuditStatus(error.message, true); }
		}).finally(function () {
			state.auditLoading = false;
			renderAuditPagination();
		});
	}

	function loadEvents() {
		if (state.loading) { return Promise.resolve(); }
		state.loading = true;
		$("admin-refresh").disabled = true;
		setStatus("正在加载进入记录...");
		return api("AccountLoginEvents").then(function (payload) {
			var data = resource(payload) || {};
			var events = Array.isArray(data) ? data : (data.events || data.data || []);
			renderEvents(events);
			setStatus(events.length ? "已加载 " + events.length + " 条记录。" : "");
		}).catch(function (error) {
			if (error.status === 401) { showLogin("登录状态已失效，请重新登录。"); }
			else if (error.status === 403) { showDenied(); }
			else { setStatus(error.message, true); }
		}).finally(function () {
			state.loading = false;
			$("admin-refresh").disabled = false;
		});
	}

	function loadSecurity(message) {
		if (state.securityLoading) { return Promise.resolve(); }
		state.securityLoading = true;
		$("security-refresh").disabled = true;
		$("security-trust-current").disabled = true;
		$("security-trusted-device-only").disabled = true;
		if (!message) { setSecurityStatus("正在读取安全设置..."); }
		return api("LoginSecurity").then(function (payload) {
			state.security = securitySnapshot(payload);
			renderSecurity(state.security);
			setSecurityStatus(message || "");
		}).catch(function (error) {
			if (error.status === 401) { showLogin("登录状态已失效，请重新登录。"); }
			else if (error.status === 403) { showDenied(); }
			else { setSecurityStatus(error.message, true); }
		}).finally(function () {
			state.securityLoading = false;
			if (state.security) { renderSecurity(state.security); }
		});
	}

	function runSecurityAction(control, request, successMessage) {
		control.disabled = true;
		setSecurityStatus("正在保存安全设置...");
		return request().then(function () {
			return loadSecurity(successMessage);
		}).catch(function (error) {
			if (error.status === 401) { showLogin("登录状态已失效，请重新登录。"); }
			else if (error.status === 403) { showDenied(); }
			else { setSecurityStatus(error.message, true); }
		}).finally(function () {
			if (!state.securityLoading && state.security) { renderSecurity(state.security); }
			else if (!state.securityLoading) { control.disabled = false; }
		});
	}

	function trustCurrentDevice(control) {
		runSecurityAction(control, function () {
			return api("LoginSecurity::trust", { method: "POST", body: {} });
		}, "当前设备已加入信任列表。");
	}

	function setTrustedDeviceProtection(control) {
		var enabled = control.checked;
		runSecurityAction(control, function () {
			return api("LoginSecurity::deviceProtection", { method: "POST", body: { enabled: enabled } });
		}, enabled ? "已开启可信设备登录限制。" : "已关闭可信设备登录限制。");
	}

	function revokeDevice(id, control) {
		if (id === undefined || id === null || id === "") { return; }
		runSecurityAction(control, function () {
			return api("LoginSecurity::revoke/" + encodeURIComponent(String(id)), { method: "POST", body: {} });
		}, "已取消该设备的信任。");
	}

	function inviteStatusLabel(invite) {
		return { available: "可使用", used: "已使用", expired: "已过期", revoked: "已撤销" }[invite.status] || invite.status || "未知";
	}

	function renderRegistration() {
		var registration = state.registration;
		var mode = $("registration-mode");
		mode.value = registration.mode === "open" ? "open" : "invite";
		mode.disabled = state.registrationLoading;
		$("registration-create-invite").disabled = state.registrationLoading;
		$("registration-refresh").disabled = state.registrationLoading;
		var list = $("registration-invites");
		list.replaceChildren();
		if (!registration.invites.length) {
			var empty = document.createElement("p");
			empty.className = "empty-state";
			empty.textContent = "还没有生成邀请码。";
			list.appendChild(empty);
			return;
		}
		registration.invites.forEach(function (invite) {
			var row = document.createElement("article");
			var copy = document.createElement("div");
			var title = document.createElement("strong");
			var meta = document.createElement("p");
			row.className = "registration-invite-row";
			title.textContent = "邀请码 #" + invite.id + " · " + inviteStatusLabel(invite);
			meta.textContent = "创建：" + formatDateTime(invite.created_at) + "；过期：" + formatDateTime(invite.expires_at) + (invite.used_at ? "；使用：" + formatDateTime(invite.used_at) : "");
			copy.appendChild(title);
			copy.appendChild(meta);
			row.appendChild(copy);
			if (invite.status === "available") {
				var revoke = document.createElement("button");
				revoke.type = "button";
				revoke.className = "button";
				revoke.textContent = "撤销";
				revoke.addEventListener("click", function () { revokeInvite(invite.id, revoke); });
				row.appendChild(revoke);
			}
			list.appendChild(row);
		});
	}

	function loadRegistration(message) {
		if (state.registrationLoading) { return Promise.resolve(); }
		state.registrationLoading = true;
		renderRegistration();
		if (!message) { setRegistrationStatus("正在读取注册设置..."); }
		return Promise.all([api("RegistrationSettings"), api("RegistrationInvites")]).then(function (payloads) {
			state.registration.mode = payloads[0] && payloads[0].mode === "open" ? "open" : "invite";
			state.registration.invites = payloads[1] && Array.isArray(payloads[1].invites) ? payloads[1].invites : [];
			setRegistrationStatus(message || "");
		}).catch(function (error) {
			if (error.status === 401) { showLogin("登录状态已失效，请重新登录。"); }
			else if (error.status === 403) { showDenied(); }
			else { setRegistrationStatus(error.message, true); }
		}).finally(function () {
			state.registrationLoading = false;
			renderRegistration();
		});
	}

	function saveRegistrationMode(control) {
		var requestedMode = control.value === "open" ? "open" : "invite";
		state.registrationLoading = true;
		renderRegistration();
		setRegistrationStatus("正在保存注册模式...");
		api("RegistrationSettings", { method: "POST", body: { mode: requestedMode } }).then(function (payload) {
			state.registration.mode = payload.mode === "open" ? "open" : "invite";
			setRegistrationStatus("注册模式已更新。");
		}).catch(function (error) {
			setRegistrationStatus(error.message, true);
		}).finally(function () {
			state.registrationLoading = false;
			renderRegistration();
		});
	}

	function createInvite(control) {
		control.disabled = true;
		setRegistrationStatus("正在生成邀请码...");
		api("RegistrationInvites", { method: "POST", body: {} }).then(function (payload) {
			var invite = payload && payload.invite || {};
			$("registration-code-value").textContent = invite.code || "";
			$("registration-new-code").hidden = !invite.code;
			return loadRegistration("邀请码已生成，请立即复制保存。");
		}).catch(function (error) { setRegistrationStatus(error.message, true); }).finally(function () { control.disabled = false; });
	}

	function revokeInvite(id, control) {
		control.disabled = true;
		api("RegistrationInvites/" + encodeURIComponent(id), { method: "DELETE", body: {} }).then(function () {
			return loadRegistration("邀请码已撤销。");
		}).catch(function (error) { setRegistrationStatus(error.message, true); control.disabled = false; });
	}

	function copyInviteCode() {
		var code = $("registration-code-value").textContent;
		if (!code) { return; }
		navigator.clipboard.writeText(code).then(function () { setRegistrationStatus("邀请码已复制。"); }).catch(function () { setRegistrationStatus("复制失败，请手动选择邀请码。", true); });
	}

	function loadCurrentUser() {
		return api("Auth::user").then(function (payload) {
			state.user = resource(payload);
			if (!isAdministrator(state.user)) { showDenied(); return; }
			showDashboard();
			return Promise.all([loadEvents(), loadSecurity(), loadRegistration(), loadAuditEvents(1)]);
		}).catch(function (error) {
			if (error.status === 401 || error.status === 419) { showLogin(); return; }
			showLogin(error.message);
		});
	}

	function logout() {
		return api("Auth::logout", { method: "POST", body: {} }).catch(function () { return null; }).finally(function () { showLogin(); });
	}

	function bindEvents() {
		$("admin-login-form").addEventListener("submit", function (event) {
			event.preventDefault();
			var submit = $("admin-login-submit");
			submit.disabled = true;
			$("admin-login-error").textContent = "";
			primeSession().then(function () {
				return api("Auth::login", { method: "POST", body: {
					username: $("admin-username").value.trim(),
					password: $("admin-password").value,
					remember_me: $("admin-remember").checked
				} });
			}).then(function () {
				$("admin-password").value = "";
				return loadCurrentUser();
			}).catch(function (error) {
				$("admin-password").value = "";
				$("admin-login-error").textContent = error.message;
			}).finally(function () { submit.disabled = false; });
		});
		$("admin-refresh").addEventListener("click", loadEvents);
		$("audit-refresh").addEventListener("click", function () { loadAuditEvents(state.audit.page); });
		$("audit-filters").addEventListener("submit", function (event) { event.preventDefault(); loadAuditEvents(1); });
		$("audit-previous").addEventListener("click", function () { loadAuditEvents(state.audit.page - 1); });
		$("audit-next").addEventListener("click", function () { loadAuditEvents(state.audit.page + 1); });
		$("security-refresh").addEventListener("click", function () { loadSecurity(); });
		$("security-trust-current").addEventListener("click", function (event) { trustCurrentDevice(event.currentTarget); });
		$("security-trusted-device-only").addEventListener("change", function (event) { setTrustedDeviceProtection(event.currentTarget); });
		$("registration-refresh").addEventListener("click", function () { loadRegistration(); });
		$("registration-mode").addEventListener("change", function (event) { saveRegistrationMode(event.currentTarget); });
		$("registration-create-invite").addEventListener("click", function (event) { createInvite(event.currentTarget); });
		$("registration-copy-code").addEventListener("click", copyInviteCode);
		$("admin-logout").addEventListener("click", logout);
		$("admin-denied-logout").addEventListener("click", logout);
	}

	function init() { bindEvents(); renderAuditPagination(); primeSession().then(loadCurrentUser); }
	document.addEventListener("DOMContentLoaded", init);
})();
