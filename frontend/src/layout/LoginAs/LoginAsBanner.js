import {useState} from "react";
import className from "classnames/bind";
import {Button, Icon} from "~/components";
import {isLoginAs, getLoginAsUser, exitLoginAs} from "~/utils";
import LoginAsModal from "./LoginAsModal";
import style from "./LoginAs.module.scss";

const cn = className.bind(style);

/**
 * Thanh cảnh báo hiển thị khi đang mạo danh tài khoản khác. Cho phép chuyển sang
 * tài khoản khác hoặc quay lại tài khoản gốc. Ẩn hoàn toàn khi không mạo danh.
 */
function LoginAsBanner() {

	const [openModal, setOpenModal] = useState(false);

	if (!isLoginAs()) return null;

	const user = getLoginAsUser() || {};
	const role = user.role_name || user.role;

	return (
		<div className={cn('banner')}>
			<div className={cn('banner-text')}>
				{Icon.fingerprint}
				<span>
					Bạn đang đăng nhập với tư cách <strong>{user.fullname || user.username}</strong>
					{role ? <em> ({role})</em> : null}
				</span>
			</div>
			<div className={cn('banner-actions')}>
				<Button small white outline leftIcon={Icon.switch} onClick={() => setOpenModal(true)}>
					Chuyển tài khoản
				</Button>
				<Button small white leftIcon={Icon.arrowRotateLeft} onClick={exitLoginAs}>
					Quay lại tài khoản gốc
				</Button>
			</div>

			<LoginAsModal open={openModal} onClose={() => setOpenModal(false)} />
		</div>
	);
}

export default LoginAsBanner;
