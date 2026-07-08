import request from "~/utils/http";

const authApi = {
	token : async (username, password) => {
		const url = 'auth/login';
		return await request.post(url, {username, password});
	},
	current : async () => {
		const url = 'auth/current';
		return await request.get(url);
	},
	// Revoke access + refresh token phía server khi đăng xuất
	logout : async () => {
		const url = 'auth/logout';
		return await request.post(url);
	},
	update : async (params) => {
		const url = 'auth/update';
		return await request.post(url, params);
	},
	password : async (params) => {
		const url = 'auth/password';
		return await request.post(url, params);
	},
	// Danh sách tài khoản có thể đăng nhập vào (impersonation)
	loginAsCandidates : async (keyword = '') => {
		const url = keyword ? `auth/login-as/candidates?keyword=${encodeURIComponent(keyword)}` : 'auth/login-as/candidates';
		return await request.get(url);
	},
	// Phát hành token cho tài khoản đích → dùng làm loginAsToken
	loginAs : async (userId) => {
		const url = 'auth/login-as';
		return await request.post(url, {user_id: userId});
	},
};

export default authApi;