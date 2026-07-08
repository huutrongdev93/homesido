import {FontAwesomeIcon} from "../index";

/**
 * Bộ icon JSX dùng nhanh (FontAwesome) — dùng làm `leftIcon`/nội dung nút, banner...
 * Chỉ giữ các icon đang được dùng trong base; thêm entry mới khi cần.
 */
const Icon = {
	user: <FontAwesomeIcon icon="fa-light fa-user" />,
	lock: <FontAwesomeIcon icon="fa-light fa-lock" />,
	logout: <FontAwesomeIcon icon="fa-light fa-arrow-right-from-bracket" />,
	plus: <FontAwesomeIcon icon="fa-light fa-plus" />,
	switch: <FontAwesomeIcon icon="fa-light fa-exchange" />,
	userSwitch: <FontAwesomeIcon icon="fa-light fa-users-gear" />,
	fingerprint: <FontAwesomeIcon icon="fa-light fa-fingerprint" />,
	arrowRotateLeft: <FontAwesomeIcon icon="fa-thin fa-arrow-rotate-left" />,
}

export default Icon;
