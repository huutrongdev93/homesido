import request from "~/utils/http";

const roleApi = {
	// Danh sách chức vụ [key => tên]
	gets : async () => {
		const url = 'role';
		return await request.get(url);
	},
	// Thêm chức vụ mới {name}
	add : async (params) => {
		const url = 'role';
		return await request.post(url, params);
	},
	// Quyền hiện tại của 1 chức vụ
	permissionByRole : async (role) => {
		const url = `role/${role}`;
		return await request.get(url);
	},
	// Toàn bộ quyền (gom nhóm)
	permissionList : async () => {
		const url = 'role/permission';
		return await request.get(url);
	},
	// Cập nhật quyền của 1 chức vụ {role, capabilities}
	permissionUpdate : async (params) => {
		const url = `role/permission/${params.role}`;
		return await request.put(url, params);
	},
	// Xóa chức vụ (chỉ khi chưa có user nào dùng)
	delete : async (role) => {
		const url = `role/${role}`;
		return await request.delete(url);
	},
};

export default roleApi;
