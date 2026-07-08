import {createContext, useEffect, useState} from "react";
import {useDispatch} from "react-redux";
import {
	checkAuthorization,
	apiError,
	handleRequest,
	isLoginAs,
	getLoginAsUser,
	clearLoginAs
} from "~/utils";
import {Loading} from "../components";
import {useViewport} from "~/hooks";
import {authApi, utilsApi} from "../api";
import {authActions} from "~/reduxs/Auth/authSlice";

const AppContext = createContext(undefined);

// Parse JSON từ localStorage an toàn — giá trị hỏng (vd "undefined") trả null thay vì throw.
const safeParse = (raw) => {
	try {
		return raw ? JSON.parse(raw) : null;
	} catch (e) {
		return null;
	}
};

// APP GLOBAL DATA
function AppProvider({ children }) {

	const dispatch = useDispatch();

	const [loading, setLoading] = useState(true);

	const [loginAsUsers, setLoginAsUsers] = useState([]);

	const [rolesData, setRolesData] = useState([]);

	const [permissions, setPermissions] = useState([]);

	const [appData, setAppData] = useState([]);

	const [userLogin, setUserLogin] = useState(false);

	useEffect(() => {
		if(checkAuthorization()) {
			(async () => {

				let [errorUser, responseUser] = await handleRequest(authApi.current());

				let messageUser = apiError(`Lấy thông tin tài khoản thất bại`, errorUser, responseUser);

				if (!messageUser && responseUser.data?.user)
				{
					// An toàn: nếu token mạo danh đã hết hạn (BE âm thầm trả về tài khoản gốc)
					// thì xóa trạng thái mạo danh treo để banner không hiển thị sai.
					if (isLoginAs() && getLoginAsUser()?.id !== responseUser.data.user.id)
					{
						clearLoginAs();
					}

					setPermissions(responseUser.data.permissions);

					dispatch(authActions.loginSuccess(responseUser.data?.user));

					/* Các Thông tin khác */
					let utilitiesDataKey = localStorage.getItem('utilities-key');

					if (responseUser.data.utilitiesKey === null || responseUser.data.utilitiesKey !== utilitiesDataKey)
					{
						let [error, response] = await handleRequest(utilsApi.gets());

						if (!apiError(`Load thông tin thất bại`, error, response))
						{
							// Fallback về [] để không bao giờ lưu chuỗi "undefined" vào storage
							// (JSON.stringify(undefined) trả undefined → setItem lưu "undefined"
							// → lần đọc sau JSON.parse throw → trắng trang).
							const roles = response.data?.roles ?? [];
							const data  = response.data?.data ?? [];

							// Chỉ lưu key khi có giá trị thật — tránh lưu "undefined"/"null".
							if (responseUser.data?.utilitiesKey) {
								localStorage.setItem('utilities-key', responseUser.data.utilitiesKey);
							}
							localStorage.setItem('rolesData', JSON.stringify(roles));
							localStorage.setItem('appData', JSON.stringify(data));
							setAppData(data);
							setRolesData(roles);
						}
					}
					else
					{
						setAppData(safeParse(localStorage.getItem('appData')) ?? []);
						setRolesData(safeParse(localStorage.getItem('rolesData')) ?? []);
					}
				}
				else
				{
					localStorage.clear();
					dispatch(authActions.logout());
				}
				setLoading(false);
			})();
		}
		else {
			setLoading(false);
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [userLogin]);

	// Responsive: cờ breakpoint từ matchMedia (useViewport)
	const {isMobile, isTablet, isDesktop} = useViewport();
	const tableHeight = isTablet ? '50vh' : '60vh';

	if (loading) return <Loading />

	return (
		<AppContext.Provider value={{
			isMobile,
			isDesktop,
			isTablet,
			loginAsUsers,
			setLoginAsUsers,
			userLogin,
			setUserLogin,
			tableHeight,
			rolesData,
			appData,
			permissions,
		}}>
			{children}
		</AppContext.Provider>
	)
}

export { AppContext };
export default AppProvider;