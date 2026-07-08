import style from './Sidebar.module.scss';
import className from 'classnames/bind';
import {memo, useMemo, useState} from "react";
import {Menu} from "antd";
import {useLocation} from "react-router-dom";
import {Image, FontAwesomeIcon} from "~/components";
import ProfileRow from "./ProfileRow";
import {NavBarData} from "./NavBarData";
import findActiveKey from "./findActiveKey";
import images from "../../assets/images";

const cn = className.bind(style);

// Số hotline hỗ trợ (điều chỉnh theo dự án).
const HOTLINE = '1900 0000';

/**
 * Sidebar CHẾ ĐỘ NGƯỜI DÙNG: logo → hồ sơ (ProfileRow) → menu điều hướng →
 * khung hỗ trợ. Thu gọn (collapsed) = icon-rail.
 */
function Sidebar({onNavigate, collapsed = false})
{
	const location = useLocation();

	// Trạng thái mở/thu gọn các nhóm menu (submenu). Module nghiệp vụ mới có thể
	// thêm key mặc định mở vào mảng này.
	const [openKeys, setOpenKeys] = useState([]);

	const navBarList = NavBarData();

	const moduleActive = useMemo(
		() => findActiveKey(navBarList, location.pathname),
		[location.pathname, navBarList]
	);

	return (
		<div className={cn('sidebar', {collapsed})}>
			{/* Logo */}
			<div className={cn('logo')}>
				<Image src={images.logo} alt="Base App" />
			</div>

			{/* Hồ sơ: avatar - tên - chức vụ + chuông thông báo (thu gọn: chỉ còn avatar) */}
			<ProfileRow onNavigate={onNavigate} collapsed={collapsed} />

			{/* Menu điều hướng. Gotcha antd: khi inlineCollapsed không được truyền
			    controlled openKeys (antd cảnh báo + tự quản popup submenu). */}
			<div className={cn('menu')}>
				<Menu
					mode="inline"
					inlineCollapsed={collapsed}
					selectedKeys={[moduleActive]}
					{...(!collapsed && {openKeys, onOpenChange: setOpenKeys})}
					items={navBarList}
					onClick={onNavigate}
					style={{borderInlineEnd: 'none', background: 'transparent'}}
				/>
			</div>

			{/* Khung hỗ trợ */}
			{!collapsed && (
				<div className={cn('support')}>
					<div className={cn('support-inner')}>
						<div className={cn('support-icon')}>
							<FontAwesomeIcon icon="fa-light fa-headset" />
						</div>
						<p className={cn('support-title')}>Bạn cần hỗ trợ?</p>
						<p className={cn('support-desc')}>Liên hệ hotline để được trợ giúp</p>
						<a href={`tel:${HOTLINE.replace(/\s/g, '')}`} className={cn('support-hotline')}>
							<FontAwesomeIcon icon="fa-light fa-phone-volume" />
							<span>{HOTLINE}</span>
						</a>
					</div>
				</div>
			)}
		</div>
	);
}

export default memo(Sidebar);
