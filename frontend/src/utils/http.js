import axios from "axios";

import {memoizedRefreshToken} from "./refreshToken";
import {apiBaseURL, homePrefix, tstore} from "./tenant";

const axiosConfig = {
	// Multi-tenant: chèn /{key} vào base khi bật (apiBaseURL). 1-sàn = REACT_APP_SERVICE_URL như cũ.
	baseURL: apiBaseURL(),
	headers: {
		'Content-Type': 'application/json',
	}
}

const request = axios.create(axiosConfig)

// Các endpoint xác thực: 401/422 ở đây là lỗi đăng nhập thật (sai mật khẩu,
// refresh token hết hạn...) chứ KHÔNG phải access token hết hạn → không refresh.
const AUTH_ENDPOINTS = ['auth/login', 'auth/refresh'];

const isAuthEndpoint = (url = '') => AUTH_ENDPOINTS.some((path) => url.includes(path));

// Đọc access_token an toàn — giá trị hỏng trong storage không được làm gãy request.
// tstore: key namespaced theo tenant (2 sàn cùng trình duyệt không đè token nhau).
const getStoredToken = () => {
	try {
		return JSON.parse(tstore.get('access_token') || 'null');
	} catch (e) {
		return null;
	}
};

request.interceptors.request.use(
	async (config) => {

		const storedToken = getStoredToken();

		const loginAsToken = tstore.get('access_token_as');

		if (storedToken?.accessToken) {
			config.headers = {
				...config.headers,
				"Authorization": `Bearer ${storedToken.accessToken}`,
			};

			// Chỉ gửi khi đang mạo danh — tránh gửi chuỗi "null" làm header rác.
			if (loginAsToken) {
				config.headers.loginAsToken = loginAsToken;
			} else {
				delete config.headers.loginAsToken;
			}
		}

		return config;
	},
	(error) => Promise.reject(error)
);

request.interceptors.response.use(
	(response) => response.data,
	async (error) => {
		const config = error?.config;

		// Token mạo danh (login-as) hỏng/hết hạn: backend trả 409 LOGIN_AS_EXPIRED thay vì
		// âm thầm rơi về tài khoản gốc. Xoá trạng thái mạo danh (key theo utils/loginAs.js)
		// rồi reload để AppProvider nạp lại danh tính gốc — KHÔNG đá về /login vì phiên gốc còn.
		if (error?.response?.status === 409 && error?.response?.data?.message === 'LOGIN_AS_EXPIRED') {
			tstore.remove('access_token_as');
			tstore.remove('login_as_user');
			window.location.reload();
			return Promise.reject(error);
		}

		const storedToken = getStoredToken();

		// Chỉ thử refresh khi: lỗi 401, chưa retry, KHÔNG phải endpoint auth, và
		// đang thực sự có access token (tức request của người đã đăng nhập bị hết hạn).
		const shouldRefresh = error?.response?.status === 401
			&& !config?.sent
			&& !isAuthEndpoint(config?.url || '')
			&& Boolean(storedToken?.accessToken);

		if (shouldRefresh) {

			config.sent = true;

			const result = await memoizedRefreshToken();

			// Chỉ retry request gốc khi refresh THÀNH CÔNG.
			if (result?.accessToken) {
				config.headers = {
					...config.headers,
					Authorization: `Bearer ${result.accessToken}`,
				};

				return request(config);
			}

			// Refresh THẤT BẠI = phiên chết hẳn → thoát phiên toàn cục: xoá storage
			// (refreshToken đã clear, clear lại cho chắc) + full reload về /login.
			// Không làm vậy thì app đứng nguyên, polling spam 401 tới khi user tự F5.
			tstore.clear();
			window.location.assign(homePrefix() + '/login');
		}

		return Promise.reject(error);
	}
);


export default request;
