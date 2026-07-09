import Login from "~/features/Auth/pages/Login";
import PublicProperty from "~/features/PublicProperty/pages/PublicProperty";
import {EmptyLayout} from "~/layout";

export const publicRoutes = [
	{ path: "/login", component: Login, layout: EmptyLayout },
	// Trang công khai 1 BĐS — gửi link khách xem, không cần đăng nhập (layout null = không sidebar).
	{ path: "/p/:code", component: PublicProperty, layout: null },
];
