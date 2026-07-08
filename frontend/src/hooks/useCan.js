import {AppContext} from "../context/AppProvider";
import {useContext} from "react";

function useCan(permission) {
	const {permissions} = useContext(AppContext);
	// Siêu quản trị (administrator của app, hoặc root master framework) có toàn bộ quyền.
	if (permissions && (permissions.administrator || permissions.root)) return true;
	return typeof permissions !== 'undefined' && typeof permissions[permission] !== 'undefined' && permissions[permission] === true;
}

export default useCan;