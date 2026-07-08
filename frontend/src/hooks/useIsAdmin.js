import {useContext} from "react";
import {AppContext} from "~/context/AppProvider";

/**
 * Quản trị viên = siêu quản trị (administrator của app / root master framework),
 * hoặc có cap quản trị (permission).
 *
 * Lưu ý: caps chỉ nằm trong AppContext.permissions (không suy từ currentUser.role ở Redux).
 * Module quản trị mới bổ sung cap của mình vào điều kiện dưới.
 */
function useIsAdmin()
{
	const {permissions} = useContext(AppContext);

	if (!permissions) return false;

	return Boolean(permissions.administrator || permissions.root || permissions.permission === true);
}

export default useIsAdmin;
