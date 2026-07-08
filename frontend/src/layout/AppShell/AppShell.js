import style from './AppShell.module.scss';
import className from 'classnames/bind';
import {useContext, useState} from "react";
import {Drawer} from 'antd';
import {Image, FontAwesomeIcon} from "~/components";
import {AppContext} from "~/context/AppProvider";
import {useSidebarCollapsed} from "~/hooks";
import images from "../../assets/images";
import {LoginAsBanner} from "../LoginAs";

const cn = className.bind(style);

/**
 * Khung shell dùng chung cho DefaultLayout (chế độ người dùng) và
 * AdminLayout (chế độ quản trị).
 *
 * @param renderSidebar ({collapsed, onNavigate}) => sidebar của chế độ tương ứng
 * @param variant 'user' | 'admin' — đổi accent nhẹ + nhãn "Quản trị" ở topbar mobile
 */
function AppShell({renderSidebar, variant = 'user', children})
{
	const {isMobile, isTablet} = useContext(AppContext);

	const [openDrawer, setOpenDrawer] = useState(false);

	// Tablet mặc định thu gọn thành icon-rail; desktop mở rộng; user toggle được (persist).
	const {collapsed, toggle} = useSidebarCollapsed(isTablet);

	const closeDrawer = () => setOpenDrawer(false);

	return (
		<div className={cn('wrapper', variant)}>
			{/* Sidebar cố định bên trái (desktop / tablet) */}
			{!isMobile && (
				<aside className={cn('sider', {collapsed})}>
					<div className={cn('sider-inner')}>
						{renderSidebar({collapsed})}
					</div>
					<button
						className={cn('collapse-btn')}
						onClick={toggle}
						title={collapsed ? 'Mở rộng menu' : 'Thu gọn menu'}
					>
						<FontAwesomeIcon icon={collapsed ? 'fa-light fa-angles-right' : 'fa-light fa-angles-left'} />
						{!collapsed && <span>Thu gọn</span>}
					</button>
				</aside>
			)}

			{/* Sidebar dạng drawer trên mobile */}
			{isMobile && (
				<Drawer
					placement="left"
					width={288}
					open={openDrawer}
					onClose={closeDrawer}
					closable={false}
					styles={{body: {padding: 0}}}
				>
					{renderSidebar({collapsed: false, onNavigate: closeDrawer})}
				</Drawer>
			)}

			{/* Nội dung bên phải */}
			<main className={cn('main')}>
				{isMobile && (
					<div className={cn('topbar')}>
						<button className={cn('hamburger')} onClick={() => setOpenDrawer(true)}>
							<FontAwesomeIcon icon="fa-light fa-bars" />
						</button>
						<Image src={images.logo} alt="Base App" className={cn('topbar-logo')} />
						{variant === 'admin' && <span className={cn('topbar-badge')}>Quản trị</span>}
					</div>
				)}
				<div className={cn('content')}>
					<LoginAsBanner />
					{children}
				</div>
			</main>
		</div>
	);
}

export default AppShell;
