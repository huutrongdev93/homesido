import FontAwesomeIcon from "~/components/FontAwesome";
import {Link} from "react-router-dom";
import {useCan, useIsAdmin} from "~/hooks";

/**
 * Danh sách menu điều hướng CHẾ ĐỘ NGƯỜI DÙNG, chia section (type:'group'):
 * TỔNG QUAN · TÀI KHOẢN.
 *
 * Module nghiệp vụ mới tự thêm mục (kèm kiểm tra quyền qua useCan) vào section
 * phù hợp — hoặc tạo section mới. Menu quản trị nằm riêng ở AdminNavData (AdminSidebar).
 *
 * NavBarData được gọi trong render của Sidebar nên dùng hook useCan ở đây hợp lệ.
 */
export const NavBarData = () => {

	const canPermission = useCan('permission');
	const canCustomer = useCan('customer_view');
	const canProperty = useCan('property_view');
	const canMatching = useCan('matching_view');
	const canAppointment = useCan('appointment_view');
	const canDeal = useCan('deal_view');
	const isAdmin = useIsAdmin();

	// ===== TỔNG QUAN =====
	const overview = [
		{
			key: 'home',
			label: <Link to="/">Dashboard</Link>,
			title: 'Dashboard',
			to: '/',
			icon: <FontAwesomeIcon icon="fa-light fa-house" />,
			count: 0,
		},
	];

	// ===== KINH DOANH =====
	const business = [];
	if (canCustomer) {
		business.push({
			key: 'care',
			label: <Link to="/care">Cần chăm hôm nay</Link>,
			title: 'Cần chăm hôm nay',
			to: '/care',
			icon: <FontAwesomeIcon icon="fa-light fa-calendar-heart" />,
			count: 0,
		});
	}
	if (canCustomer) {
		business.push({
			key: 'customers',
			label: <Link to="/customers">Khách hàng</Link>,
			title: 'Khách hàng',
			to: '/customers',
			icon: <FontAwesomeIcon icon="fa-light fa-users" />,
			count: 0,
		});
	}
	if (canProperty) {
		business.push({
			key: 'properties',
			label: <Link to="/properties">Bất động sản</Link>,
			title: 'Bất động sản',
			to: '/properties',
			icon: <FontAwesomeIcon icon="fa-light fa-building" />,
			count: 0,
		});
	}
	if (canAppointment) {
		business.push({
			key: 'appointments',
			label: <Link to="/appointments">Lịch hẹn dẫn khách</Link>,
			title: 'Lịch hẹn dẫn khách',
			to: '/appointments',
			icon: <FontAwesomeIcon icon="fa-light fa-calendar-clock" />,
			count: 0,
		});
	}
	if (canDeal) {
		business.push({
			key: 'deals',
			label: <Link to="/deals">Giao dịch</Link>,
			title: 'Giao dịch',
			to: '/deals',
			icon: <FontAwesomeIcon icon="fa-light fa-file-signature" />,
			count: 0,
		});
	}
	if (canMatching) {
		business.push({
			key: 'matching',
			label: <Link to="/matching">Khớp lệnh</Link>,
			title: 'Khớp lệnh',
			to: '/matching',
			icon: <FontAwesomeIcon icon="fa-light fa-arrows-repeat" />,
			count: 0,
		});
	}

	// ===== TÀI KHOẢN =====
	const account = [
		{
			key: 'profile',
			label: <Link to="/account">Hồ sơ cá nhân</Link>,
			title: 'Hồ sơ cá nhân',
			to: '/account',
			icon: <FontAwesomeIcon icon="fa-light fa-id-card" />,
			count: 0,
		},
	];

	// Cửa sang CHẾ ĐỘ QUẢN TRỊ — chỉ hiện với quản trị viên (menu quản trị
	// nằm riêng trong AdminLayout, không trộn vào menu của user).
	if (isAdmin) {
		account.push({
			key: 'goto-admin',
			label: <Link to={canPermission ? '/admin/permission' : '/admin'}>Quản trị hệ thống</Link>,
			title: 'Quản trị hệ thống',
			to: '/admin',
			icon: <FontAwesomeIcon icon="fa-light fa-shield-halved" />,
			className: 'menu-admin-link',
		});
	}

	// Ghép section — bỏ section rỗng.
	const sections = [
		{key: 'g-overview', label: 'Tổng quan', children: overview},
		{key: 'g-business', label: 'Kinh doanh', children: business},
		{key: 'g-account', label: 'Tài khoản', children: account},
	];

	return sections
		.filter((s) => s.children.length > 0)
		.map((s) => ({type: 'group', ...s}));
}
