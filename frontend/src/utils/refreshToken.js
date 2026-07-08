import mem from "mem";
import axios from "axios";

const request = axios.create({
	baseURL: process.env.REACT_APP_SERVICE_URL,
	headers: {
		'Content-Type': 'application/json',
	}
})

const refreshToken = async () => {

	const token = localStorage.getItem("reload_token");

	try {

		const response = await request.post("/auth/refresh", { refresh_token: token});

		// Body không có token mới = phiên chết thật → xoá sạch và DỪNG (không được
		// ghi token undefined trở lại storage — sẽ tạo phiên "ma" không thoát được).
		if (!response?.data?.accessToken) {
			localStorage.clear();
			return null;
		}

		const accessToken = {
			accessToken   : response.data.accessToken,
			expires       : response.data.expires,
		}

		localStorage.setItem('access_token', JSON.stringify(accessToken));

		localStorage.setItem('reload_token', response.data.refreshToken);

		return accessToken;

	}
	catch (err) {
		// Chống race đa tab với refresh-token rotation: nếu reload_token trong storage
		// ĐÃ KHÁC token mình vừa gửi nghĩa là tab khác đã refresh xong và ghi token mới
		// → dùng access_token hiện có thay vì clear (clear sẽ xoá nhầm token mới của tab kia).
		const currentToken = localStorage.getItem("reload_token");

		if (currentToken && currentToken !== token) {
			try {
				const stored = JSON.parse(localStorage.getItem('access_token'));
				if (stored?.accessToken) return stored;
			} catch (e) { /* storage hỏng → rơi xuống clear */ }
		}

		localStorage.clear();
		return null;
	}
};

const maxAge = 10000;

export const memoizedRefreshToken = mem(refreshToken, {
	maxAge,
});