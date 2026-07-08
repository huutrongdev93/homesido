import { Navigate } from 'react-router-dom';
import { getAuthorization } from "~/utils/auth";
import { AdminLayout } from "~/layout";
import Home from "~/features/Home/pages";
import PageNotFound from "../features/NotFound/pages";
import Account from "~/features/Account/pages/Account";
import Permission from "~/features/Permission/pages/Permission";
import Customer from "~/features/Customer/pages/Customer";
import Property from "~/features/Property/pages/Property";
import CareToday from "~/features/Care/pages/CareToday";

/**
 * `cap`: quyền yêu cầu để vào route (string hoặc mảng = có 1 trong số đó; root luôn qua).
 * Không khai báo = ai đăng nhập cũng vào được. App.js bọc <RequireCap> khi route có cap
 * → không có quyền thì hiện màn NoPermission thay vì vào trang rồi nhận lỗi API.
 *
 * `layout`: ghi đè layout mặc định (DefaultLayout). Route quản trị dùng AdminLayout.
 */
export const privateRoutes = [
	{ path: "/", component: Home },
	{ path: "*", component: PageNotFound },
	// ===== KINH DOANH (chế độ người dùng — DefaultLayout) =====
	{ path: "/care", component: CareToday, cap: 'customer_view' },
	{ path: "/customers", component: Customer, cap: 'customer_view' },
	{ path: "/properties", component: Property, cap: 'property_view' },
	// Hồ sơ cá nhân — 1 trang /account với tabs (info | password)
	{ path: "/account", component: Account },
	// Route cũ → redirect về /account (giữ link/bookmark không gãy).
	// Path KHÔNG prefix REACT_APP_HOMEPAGE — Router đã có basename (App.js).
	{ path: "/profile", component: () => <Navigate to="/account" replace /> },
	{ path: "/account/password", component: () => <Navigate to="/account?tab=password" replace /> },
	// ===== CHẾ ĐỘ QUẢN TRỊ (/admin/*) — layout AdminLayout với sidebar riêng =====
	{ path: "/admin", component: () => <Navigate to="/admin/permission" replace />, layout: AdminLayout },
	{ path: "/admin/permission", component: Permission, cap: 'permission', layout: AdminLayout },
	// Route cũ → redirect sang khu quản trị mới
	{ path: "/permission", component: () => <Navigate to="/admin/permission" replace /> },
];

export function PrivateRoutes({children}) {
	// Chưa đăng nhập → về trang login; ngược lại render route.
	// Check token THẬT (parse + field accessToken) chứ không chỉ "key tồn tại" —
	// tránh giá trị rác kiểu '{}' trong storage được coi là phiên hợp lệ.
	const isLoggedIn = Boolean(getAuthorization()?.accessToken);

	if (!isLoggedIn) return <Navigate to="/login" />;

	return children;
}
