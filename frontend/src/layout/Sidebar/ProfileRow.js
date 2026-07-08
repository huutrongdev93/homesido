import style from './Sidebar.module.scss';
import className from 'classnames/bind';
import {memo, useContext, useMemo, useState} from "react";
import Tippy from '@tippyjs/react/headless';
import {useSelector} from "react-redux";
import {Image, FontAwesomeIcon, Button, PopperWrapper, Icon} from "~/components";
import {currentUserSelector} from "~/reduxs/Auth/authSlice";
import {AppContext} from "~/context/AppProvider";
import {resolveUserAvatar, isLoginAs, exitLoginAs, logout} from "~/utils";
import {useCan} from "~/hooks";
import {LoginAsModal} from "../LoginAs";
import NotificationBell from "../NotificationBell/NotificationBell";

const cn = className.bind(style);

/**
 * Hàng hồ sơ: avatar + tên + chức vụ + chuông thông báo, kèm dropdown tài khoản
 * (đổi mật khẩu / login-as / đăng xuất) + LoginAsModal.
 *
 * Dùng chung cho Sidebar (chế độ người dùng) và AdminSidebar (chế độ quản trị)
 * để login-as / logout / thông báo hoạt động y hệt ở cả 2 chế độ.
 */
function ProfileRow({onNavigate, collapsed = false})
{
	const currentUser = useSelector(currentUserSelector);

	const {rolesData} = useContext(AppContext);

	const canLoginAs = useCan('login_as');

	const loginAsActive = isLoginAs();

	const [openLoginAs, setOpenLoginAs] = useState(false);

	// Đăng xuất chuẩn (utils/auth): revoke server + clear storage + full reload về /login.
	const handleLogout = () => { logout(); }

	const openLoginAsModal = () => { setOpenLoginAs(true); onNavigate && onNavigate(); }

	const renderAccountMenu = () => (
		<PopperWrapper className={cn('account-action')}>
			<Button leftIcon={Icon.user} className={cn('button')} to={'/account'} onClick={onNavigate}> Thông tin tài khoản</Button>
			<Button leftIcon={Icon.lock} className={cn('button')} to={'/account?tab=password'} onClick={onNavigate}> Đổi mật khẩu</Button>
			{/* Quay lại tài khoản gốc chỉ hiện khi đang mạo danh; không cần quyền */}
			{loginAsActive &&
				<Button leftIcon={Icon.arrowRotateLeft} onClick={exitLoginAs} className={cn('button')}> Quay lại tài khoản gốc</Button>
			}
			{/* Đăng nhập / chuyển sang tài khoản khác — quyền xét trên tài khoản gốc */}
			{(canLoginAs || loginAsActive) &&
				<Button leftIcon={Icon.userSwitch} onClick={openLoginAsModal} className={cn('button')}> Đăng nhập tài khoản khác</Button>
			}
			<hr />
			<Button leftIcon={Icon.logout} onClick={handleLogout} className={cn('button')}> Đăng xuất </Button>
		</PopperWrapper>
	)

	// Chức vụ: map role key của user sang tên trong rolesData
	const roleName = useMemo(() => {
		if (!currentUser?.role) return null;
		if (rolesData && !Array.isArray(rolesData)) return rolesData[currentUser.role] || currentUser.role;
		return currentUser.role;
	}, [currentUser, rolesData]);

	return (
		<>
			<div className={cn('profile-row')}>
				<Tippy interactive render={renderAccountMenu} placement={collapsed ? 'right' : 'bottom'} offset={[0, 8]}>
					<div className={cn('profile')}>
						<div className={cn('avatar')}>
							<Image src={resolveUserAvatar(currentUser)} alt={currentUser?.firstname} />
						</div>
						{!collapsed && (
							<>
								<div className={cn('profile-info')}>
									<p className={cn('profile-name')}>
										{currentUser?.lastname} {currentUser?.firstname}
									</p>
									{roleName && <p className={cn('profile-role')}>{roleName}</p>}
								</div>
								<FontAwesomeIcon icon="fa-light fa-chevron-down" className={cn('profile-caret')} />
							</>
						)}
					</div>
				</Tippy>
				{!collapsed && <NotificationBell onNavigate={onNavigate} />}
			</div>

			<LoginAsModal open={openLoginAs} onClose={() => setOpenLoginAs(false)} />
		</>
	);
}

export default memo(ProfileRow);
