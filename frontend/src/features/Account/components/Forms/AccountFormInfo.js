import {useContext, useState} from "react";
import className from "classnames/bind";
import dayjs from "dayjs";
import {Button, FontAwesomeIcon} from "~/components";
import {resolveUserAvatar} from "~/utils/avatar";
import {AppContext} from "~/context/AppProvider";
import Meta from "../Meta";
import AccountFormEditModal from "./AccountFormEditModal";
import style from "../../style/Account.module.scss";

const cn = className.bind(style);

const fmtDate = (v) => (v ? dayjs(v).format('DD/MM/YYYY') : '');

/**
 * Card thông tin tài khoản: cột trái avatar, cột phải lưới thông tin.
 */
function AccountFormInfo({currentUser}) {

	const {rolesData} = useContext(AppContext);
	const [editOpen, setEditOpen] = useState(false);

	const roleName = (() => {
		const role = currentUser?.role;
		if (!role) return '';
		if (rolesData && !Array.isArray(rolesData)) return rolesData[role] || role;
		return role;
	})();

	const fullName = [currentUser?.lastname, currentUser?.firstname].filter(Boolean).join(' ')
		|| currentUser?.name || currentUser?.username;

	return (
		<>
			<section className={cn('card')}>
				<div className={cn('card-head')} style={{display: 'flex', alignItems: 'center'}}>
					<span className={cn('card-head-icon')}>
						<i className="fa-light fa-id-card" />
					</span>
					<h2 className={cn('card-title')}>Thông tin tài khoản</h2>
					<span style={{marginLeft: 'auto'}}>
						<Button small outline onClick={() => setEditOpen(true)}
							leftIcon={<FontAwesomeIcon icon="fa-light fa-user-pen" />}>
							Chỉnh sửa
						</Button>
					</span>
				</div>

				<div className={cn('card-body')}>
					<div className={cn('avatar-col')}>
						<img className={cn('avatar')} src={resolveUserAvatar(currentUser)} alt={fullName} />
						<p className={cn('avatar-name')}>{fullName}</p>
						{roleName && <span className={cn('avatar-role')}>{roleName}</span>}
					</div>

					<div className={cn('info-grid')}>
						<Meta icon="fa-light fa-user-tag" label="Chức vụ">
							{roleName}
						</Meta>

						<Meta icon="fa-light fa-at" label="Tên đăng nhập">
							{currentUser?.username}
						</Meta>

						<Meta icon="fa-light fa-envelope" label="Email">
							{currentUser?.email}
						</Meta>

						<Meta icon="fa-light fa-phone" label="Số điện thoại">
							{currentUser?.phone}
						</Meta>

						<Meta icon="fa-light fa-location-dot" label="Địa chỉ">
							{currentUser?.address}
						</Meta>

						<Meta icon="fa-light fa-calendar-day" label="Ngày tham gia">
							{fmtDate(currentUser?.created)}
						</Meta>
					</div>
				</div>

				<p style={{margin: '4px 18px 16px', fontSize: 13, color: '#6b7280'}}>
					<i className="fa-light fa-circle-info" style={{marginRight: 6}} />
					Bạn tự sửa được họ tên, số điện thoại và địa chỉ (nút “Chỉnh sửa”). Cần thay đổi email
					hoặc tên đăng nhập? Vui lòng liên hệ quản trị viên. Đổi mật khẩu ở tab “Đổi mật khẩu”.
				</p>
			</section>

			<AccountFormEditModal open={editOpen} currentUser={currentUser} onCancel={() => setEditOpen(false)} />
		</>
	);
}

export default AccountFormInfo;
