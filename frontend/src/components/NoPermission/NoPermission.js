import className from "classnames/bind";
import Button from "../Button";
import FontAwesomeIcon from "../FontAwesome";
import style from "./NoPermission.module.scss";

const cn = className.bind(style);

/**
 * Màn "Không có quyền truy cập" — hiển thị khi user gõ thẳng URL của chức năng
 * chưa được cấp quyền (thay vì để trang trống rồi nhận lỗi API khó hiểu).
 */
function NoPermission() {
	return (
		<div className="container">
			<div className={cn('wrap')}>
				<div className={cn('icon')}>
					<FontAwesomeIcon icon="fa-light fa-lock-keyhole" />
				</div>
				<h2 className={cn('title')}>Không có quyền truy cập</h2>
				<p className={cn('desc')}>
					Tài khoản của bạn chưa được cấp quyền sử dụng chức năng này.
					<br />
					Liên hệ quản trị viên nếu bạn cần quyền truy cập.
				</p>
				<Button to="/" primary leftIcon={<FontAwesomeIcon icon="fa-light fa-house" />}>
					Về trang chủ
				</Button>
			</div>
		</div>
	);
}

export default NoPermission;
