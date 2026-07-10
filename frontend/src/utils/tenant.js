/**
 * Multi-tenant phía FE (GĐ4 Bước 0) — path-based: `domain.com/{key}/...`.
 *
 * Mọi tenant CHUNG origin ⇒ chung localStorage & phải chèn `/{key}` vào API base + router basename.
 * Module này là NGUỒN DUY NHẤT tính "tenant key" (segment đầu của URL) + các helper suy ra từ nó.
 *
 * BẬT/TẮT bằng env `REACT_APP_MULTI_TENANT`:
 *  - !== 'true' (mặc định)  → 1-sàn: key rỗng ⇒ baseURL/basename/localStorage y HỆT trước đây
 *    (tương thích ngược 100%, không cần đổi gì khi deploy đơn lẻ).
 *  - === 'true'             → segment đầu path = tenant key; storage namespace theo key; API gọi
 *    `${origin}/${key}/api`; router basename `${HOMEPAGE}/${key}`.
 *
 * Key đọc MỘT LẦN lúc load (SPA giữ nguyên segment đầu nhờ basename khi điều hướng nội bộ).
 * Xem docs/features/multi-tenant.md §"Frontend".
 */

const ENABLED = String(process.env.REACT_APP_MULTI_TENANT).toLowerCase() === 'true';

// Segment đầu KHÔNG phải tenant (đồng bộ với RESERVED của backend TenantResolver).
const RESERVED = [
	'api', 'p', 'uploads', 'storage', 'portal', 'admin', 'static',
	'assets', 'schedule-run', 'login', 'www', 'app',
	'favicon.ico', 'robots.txt', 'serviceworker.js', 'index.php',
];

const SLUG_RE = /^[a-z0-9-]{3,30}$/;

function parseKey() {
	if (!ENABLED) return '';

	// HOMEPAGE (subpath deploy) đứng trước key — bỏ nó ra trước khi lấy segment tenant.
	let path = window.location.pathname || '/';
	const home = process.env.REACT_APP_HOMEPAGE || '';
	if (home && path.toLowerCase().startsWith(home.toLowerCase())) {
		path = path.slice(home.length);
	}

	const seg = (path.split('/').filter(Boolean)[0] || '').toLowerCase();

	if (!seg || RESERVED.includes(seg) || !SLUG_RE.test(seg)) return '';

	return seg;
}

const TENANT_KEY = parseKey();

/** Tenant key hiện tại ('' khi 1-sàn / chưa bật multi-tenant). */
export function getTenantKey() {
	return TENANT_KEY;
}

export function isMultiTenant() {
	return TENANT_KEY !== '';
}

/** Multi-tenant CÓ BẬT không (theo env), độc lập với việc URL hiện tại có key hay chưa. */
export function isTenancyEnabled() {
	return ENABLED;
}

/**
 * Base URL API cho axios: chèn `/{key}` ngay trước `/api` cuối của REACT_APP_SERVICE_URL.
 * '/api' → '/{key}/api';  'http://host/api' → 'http://host/{key}/api'.
 */
export function apiBaseURL() {
	const base = process.env.REACT_APP_SERVICE_URL || '/api';
	if (!TENANT_KEY) return base;
	return base.replace(/\/api\/?$/i, `/${TENANT_KEY}/api`);
}

/** basename cho React Router: `${HOMEPAGE}/${key}` (hoặc HOMEPAGE|'/' khi 1-sàn). */
export function routerBasename() {
	const home = process.env.REACT_APP_HOMEPAGE || '';
	if (!TENANT_KEY) return home || '/';
	return `${home}/${TENANT_KEY}`;
}

/**
 * Tiền tố cho điều hướng FULL-URL (window.location.assign/reload) & link công khai —
 * những chỗ KHÔNG đi qua Router basename nên phải tự chèn key. '' khi 1-sàn.
 */
export function homePrefix() {
	const home = process.env.REACT_APP_HOMEPAGE || '';
	return TENANT_KEY ? `${home}/${TENANT_KEY}` : home;
}

// ── Login trung tâm (`domain.com/login` có ô "Mã sàn") — thao tác theo key TÙY Ý, không phải key URL ──

/** Base URL API cho MỘT key bất kỳ (dùng ở trang login trung tâm để gọi đúng sàn người dùng nhập). */
export function apiBaseForKey(key) {
	const base = process.env.REACT_APP_SERVICE_URL || '/api';
	if (!key) return base;
	return base.replace(/\/api\/?$/i, `/${key}/api`);
}

/** Tiền tố full-URL cho MỘT key bất kỳ (chuyển hướng vào sàn sau khi login trung tâm). */
export function homePrefixForKey(key) {
	const home = process.env.REACT_APP_HOMEPAGE || '';
	return key ? `${home}/${key}` : home;
}

/** Ghi phiên (token) vào namespace CỦA SÀN ĐÍCH — để sau khi reload vào `/{key}/` app đọc được. */
export function saveTenantSession(key, {accessToken, expires, refreshToken}) {
	localStorage.setItem(`${key}:access_token`, JSON.stringify({accessToken, expires}));
	localStorage.setItem(`${key}:reload_token`, refreshToken);
}

// Danh sách "sàn gần đây" trên trình duyệt này (KHÔNG namespaced — dùng chung để prefill mã sàn).
const RECENT_TENANTS_KEY = 'recent_tenants';
const RECENT_MAX = 5;

export function getRecentTenants() {
	try {
		const list = JSON.parse(localStorage.getItem(RECENT_TENANTS_KEY) || '[]');
		return Array.isArray(list) ? list : [];
	} catch (e) {
		return [];
	}
}

export function rememberTenant(key) {
	if (!key) return;
	const list = getRecentTenants().filter((k) => k !== key);
	list.unshift(key);
	localStorage.setItem(RECENT_TENANTS_KEY, JSON.stringify(list.slice(0, RECENT_MAX)));
}

// ── localStorage namespaced theo tenant ─────────────────────────────────────────────
// Hai sàn mở cùng trình duyệt phải KHÔNG đè token/cache của nhau → key = `${tenant}:${key}`.

function nsKey(key) {
	return TENANT_KEY ? `${TENANT_KEY}:${key}` : key;
}

/** Wrapper localStorage theo tenant — DÙNG THAY cho localStorage trực tiếp ở mọi nơi có key nghiệp vụ. */
export const tstore = {
	get: (key) => localStorage.getItem(nsKey(key)),
	set: (key, value) => localStorage.setItem(nsKey(key), value),
	remove: (key) => localStorage.removeItem(nsKey(key)),

	/**
	 * Xoá CHỈ khóa của tenant hiện tại (thay cho localStorage.clear() vốn xoá cả sàn khác đang mở).
	 * Ở chế độ 1-sàn giữ nguyên hành vi clear-toàn-bộ.
	 */
	clear: () => {
		if (!TENANT_KEY) {
			localStorage.clear();
			return;
		}
		const prefix = `${TENANT_KEY}:`;
		Object.keys(localStorage)
			.filter((k) => k.startsWith(prefix))
			.forEach((k) => localStorage.removeItem(k));
	},
};
