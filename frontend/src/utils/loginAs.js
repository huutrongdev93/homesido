/**
 * Trạng thái "đăng nhập vào tài khoản khác" (login as / impersonation).
 *
 * Cơ chế: phiên GỐC giữ nguyên trong `access_token` (Authorization). Khi mạo danh,
 * token của tài khoản đích lưu ở `access_token_as` và được http.js gửi qua header
 * `loginAsToken`; backend (JwtLoginAs) lấy đó làm tài khoản hiệu lực. Nhờ Authorization
 * không đổi nên luôn có thể QUAY LẠI tài khoản gốc hoặc CHUYỂN sang tài khoản khác.
 *
 * Đổi danh tính → reload trang để AppProvider nạp lại current + permissions + xóa cache.
 */
import request from "./http";
import {tstore, homePrefix} from "./tenant";

const TOKEN_KEY = 'access_token_as';
const USER_KEY  = 'login_as_user';

/** Đang mạo danh một tài khoản khác? */
export function isLoginAs() {
	return Boolean(tstore.get(TOKEN_KEY));
}

/** Thông tin tài khoản đang mạo danh (để hiển thị banner), hoặc null. */
export function getLoginAsUser() {
	try {
		const raw = tstore.get(USER_KEY);
		return raw ? JSON.parse(raw) : null;
	} catch (e) {
		return null;
	}
}

/** Lưu token + thông tin tài khoản đích (không reload). */
export function setLoginAs(token, user) {
	tstore.set(TOKEN_KEY, token);
	tstore.set(USER_KEY, JSON.stringify(user || {}));
}

/** Xóa trạng thái mạo danh (không reload). */
export function clearLoginAs() {
	tstore.remove(TOKEN_KEY);
	tstore.remove(USER_KEY);
}

/** Đưa trình duyệt về trang chủ (kèm router base path + tenant key) và reload toàn bộ app. */
function reloadHome() {
	window.location.assign(homePrefix() + '/');
}

/** Bắt đầu / chuyển sang mạo danh tài khoản đích rồi reload. */
export function enterLoginAs(token, user) {
	setLoginAs(token, user);
	reloadHome();
}

/** Quay lại tài khoản gốc rồi reload. */
export async function exitLoginAs() {
	// Revoke token mạo danh phía server (interceptor http.js tự gắn header loginAsToken
	// từ storage — vì vậy phải gọi TRƯỚC khi clearLoginAs, và chờ xong mới reload để
	// request không bị hủy non khi rời trang). Lỗi cũng nuốt — không chặn việc quay lại.
	try {
		await request.post('auth/login-as/exit');
	} catch (e) { /* revoke lỗi vẫn thoát mạo danh ở local */ }

	clearLoginAs();
	reloadHome();
}
