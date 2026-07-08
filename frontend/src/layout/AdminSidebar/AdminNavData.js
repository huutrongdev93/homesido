import FontAwesomeIcon from "~/components/FontAwesome";
import {Link} from "react-router-dom";
import {useCan} from "~/hooks";

/**
 * Menu điều hướng CHẾ ĐỘ QUẢN TRỊ (/admin/*).
 *
 * Gọi trong render của AdminSidebar nên dùng hook useCan ở đây hợp lệ.
 * Module quản trị mới tự thêm mục (kèm kiểm tra quyền qua useCan) vào đây.
 */
export const AdminNavData = () => {

	const canPermission = useCan('permission');

	const items = [];

	if (canPermission) {
		items.push({
			key: 'admin-permission',
			label: <Link to="/admin/permission">Phân quyền</Link>,
			title: 'Phân quyền',
			to: '/admin/permission',
			icon: <FontAwesomeIcon icon="fa-light fa-user-lock" />,
		});
		items.push({
			key: 'admin-catalog',
			label: <Link to="/admin/catalog">Cấu hình danh mục</Link>,
			title: 'Cấu hình danh mục',
			to: '/admin/catalog',
			icon: <FontAwesomeIcon icon="fa-light fa-sliders" />,
		});
	}

	return items;
}
