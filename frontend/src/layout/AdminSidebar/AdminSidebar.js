import style from './AdminSidebar.module.scss';
import className from 'classnames/bind';
import {memo, useMemo} from "react";
import {Menu} from "antd";
import {useLocation, Link} from "react-router-dom";
import {Image, FontAwesomeIcon} from "~/components";
import ProfileRow from "../Sidebar/ProfileRow";
import findActiveKey from "../Sidebar/findActiveKey";
import {AdminNavData} from "./AdminNavData";
import images from "../../assets/images";

const cn = className.bind(style);

/**
 * Sidebar CHẾ ĐỘ QUẢN TRỊ: badge nhận diện + hồ sơ + nút về trang người dùng
 * + menu quản trị. Không có plan-box/support card như sidebar user.
 */
function AdminSidebar({onNavigate, collapsed = false})
{
	const location = useLocation();

	const items = AdminNavData();

	const moduleActive = useMemo(
		() => findActiveKey(items, location.pathname, 'admin-permission'),
		[location.pathname, items]
	);

	return (
		<div className={cn('sidebar', {collapsed})}>
			{/* Logo + badge chế độ */}
			<div className={cn('logo')}>
				<Image src={images.logo} alt="Base App" />
				{!collapsed && <span className={cn('logo-badge')}>Quản trị hệ thống</span>}
			</div>

			<ProfileRow onNavigate={onNavigate} collapsed={collapsed} />

			{/* Cửa quay về chế độ người dùng */}
			<Link to="/" className={cn('mode-switch')} onClick={onNavigate} title="Về trang người dùng">
				<FontAwesomeIcon icon="fa-light fa-arrow-left" />
				{!collapsed && <span>Về trang người dùng</span>}
			</Link>

			{/* Menu quản trị (phẳng, không submenu) */}
			<div className={cn('menu')}>
				<Menu
					mode="inline"
					inlineCollapsed={collapsed}
					selectedKeys={[moduleActive]}
					items={items}
					onClick={onNavigate}
					style={{borderInlineEnd: 'none', background: 'transparent'}}
				/>
			</div>
		</div>
	);
}

export default memo(AdminSidebar);
