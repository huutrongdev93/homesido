import {authApi} from "~/api";

export function checkAuthorization () {
	// attempt to grab the token from localstorage
	const storedToken = localStorage.getItem('access_token');
	// if it exists
	return !!storedToken;
}

export function getAuthorization () {
	// attempt to grab the token from localstorage
	const storedToken = localStorage.getItem('access_token');

	// if it exists
	if (storedToken) {
		try {
			// parse it down into an object
			return JSON.parse(storedToken)
		} catch (e) {
			// giá trị hỏng (vd chuỗi "undefined") → coi như chưa đăng nhập
			return {};
		}
	}

	return {};
}

/**
 * Đăng xuất chuẩn — dùng chung cho mọi nút "Đăng xuất":
 * 1. Revoke token phía server (nuốt lỗi — offline vẫn thoát được). Chờ tối đa 1.5s:
 *    nếu clear storage + điều hướng ngay thì request revoke bị hủy non (interceptor
 *    chưa kịp đọc token / trình duyệt abort request khi rời trang).
 * 2. Xoá toàn bộ localStorage (token gốc + token mạo danh + cache utilities).
 * 3. Full reload về /login để reset sạch Redux/RTK Query cache/AppProvider —
 *    tránh phiên sau dùng nhầm permissions/cache của tài khoản trước.
 */
export async function logout () {
	try {
		await Promise.race([
			authApi.logout(),
			new Promise((resolve) => setTimeout(resolve, 1500)),
		]);
	} catch (e) { /* revoke lỗi cũng vẫn đăng xuất local */ }

	localStorage.clear();

	window.location.assign((process.env.REACT_APP_HOMEPAGE || '') + '/login');
}
