import AppShell from "../AppShell/AppShell";
import Sidebar from "../Sidebar";

/**
 * Layout chế độ NGƯỜI DÙNG: AppShell + Sidebar menu người dùng.
 * (Chế độ quản trị dùng AdminLayout với AdminSidebar riêng.)
 */
function DefaultLayout({children})
{
	return (
		<AppShell variant="user" renderSidebar={(props) => <Sidebar {...props} />}>
			{children}
		</AppShell>
	);
}

export default DefaultLayout;
