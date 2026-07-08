import {useContext} from "react";
import {AppContext} from "~/context/AppProvider";
import NoPermission from "~/components/NoPermission/NoPermission";

/**
 * Gate quyền ở tầng ROUTE: route khai báo `cap` (string hoặc mảng = có 1 trong số đó
 * là được; siêu quản trị administrator/root luôn qua) → không có quyền thì render màn
 * NoPermission thay vì để user vào trang rồi nhận lỗi API.
 *
 * AppProvider chặn render toàn app tới khi permissions nạp xong nên ở đây không có
 * trạng thái "đang tải quyền".
 */
function RequireCap({cap, children}) {

	const {permissions} = useContext(AppContext);

	const caps = Array.isArray(cap) ? cap : [cap];

	const allowed = Boolean(permissions?.administrator || permissions?.root)
		|| caps.some((c) => typeof permissions !== 'undefined' && permissions?.[c] === true);

	if (!allowed) return <NoPermission />;

	return children;
}

export default RequireCap;
