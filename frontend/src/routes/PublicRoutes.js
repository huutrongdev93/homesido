import Login from "~/features/Auth/pages/Login";
import {EmptyLayout} from "~/layout";

export const publicRoutes = [
	{ path: "/login", component: Login, layout: EmptyLayout },
];
