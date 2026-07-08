import style from '../style/Account.module.scss';
import className from 'classnames/bind';
import {Tabs} from "antd";
import {globalNavigate} from "../../../routes/GlobalHistory";
import {
	Button,
	Icon
} from "~/components";
import {logout} from "~/utils";

const cn = className.bind(style);

function AccountHeader({active, onChange}) {

	const itemsTab = [
		{key: 'info', label: 'Thông tin'},
		{key: 'password', label: 'Mật khẩu'},
	];

	// Đăng xuất chuẩn (utils/auth): revoke server + clear storage + full reload về /login.
	const handleLogout = () => { logout(); };

	// Trang /account gộp tabs → đổi tab tại chỗ (onChange); fallback điều hướng cũ.
	// Path KHÔNG prefix REACT_APP_HOMEPAGE — Router đã có basename (App.js).
	const handleTabChange = (key) => {
		if (onChange) {
			onChange(key);
			return;
		}
		globalNavigate(`/account${key === 'info' ? '' : `?tab=${key}`}`);
	};

	return (
		<section className={cn('page-head')}>
			<div>
				<h1 className={cn('page-title')}>Hồ sơ cá nhân</h1>
				<p className={cn('page-sub')}>Quản lý thông tin cá nhân và mật khẩu đăng nhập</p>
			</div>
			<Button background red leftIcon={Icon.logout} onClick={handleLogout}>Đăng xuất</Button>

			<div className={cn('page-tabs')}>
				<Tabs activeKey={active} items={itemsTab} onChange={handleTabChange} />
			</div>
		</section>
	);
}

export default AccountHeader;
