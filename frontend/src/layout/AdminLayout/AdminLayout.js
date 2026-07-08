import {Navigate} from "react-router-dom";
import AppShell from "../AppShell/AppShell";
import AdminSidebar from "../AdminSidebar/AdminSidebar";
import {useIsAdmin} from "~/hooks";

/**
 * Layout CHẾ ĐỘ QUẢN TRỊ cho các route /admin/*.
 *
 * Guard ở đây chỉ là UX (đẩy user thường về trang chủ); hàng rào quyền
 * thực sự vẫn là RequireCap theo cap của từng route.
 */
function AdminLayout({children})
{
	const isAdmin = useIsAdmin();

	if (!isAdmin) return <Navigate to="/" replace />;

	return (
		<AppShell variant="admin" renderSidebar={(props) => <AdminSidebar {...props} />}>
			{children}
		</AppShell>
	);
}

export default AdminLayout;
