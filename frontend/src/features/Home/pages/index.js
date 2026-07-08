import {Link} from "react-router-dom";
import {useSelector} from "react-redux";
import dayjs from "dayjs";
import 'dayjs/locale/vi';
import className from "classnames/bind";
import {FontAwesomeIcon} from "~/components";
import {useIsAdmin} from "~/hooks";
import {currentUserSelector} from "~/reduxs/Auth/authSlice";
import style from '../style/Home.module.scss'

dayjs.locale('vi');

const cn = className.bind(style);

/**
 * Dashboard — trang chủ của base source.
 * Chào người dùng + các lối tắt cơ bản (hồ sơ, quản trị). Module nghiệp vụ mới
 * bổ sung thẻ lối tắt của mình vào mảng quickActions.
 */
function Home() {
	const currentUser = useSelector(currentUserSelector);
	const isAdmin = useIsAdmin();

	const quickActions = [
		{to: '/account', icon: 'fa-light fa-id-card', tone: 'primary', label: 'Hồ sơ cá nhân', desc: 'Cập nhật thông tin & mật khẩu'},
		isAdmin && {to: '/admin/permission', icon: 'fa-light fa-user-lock', tone: 'purple', label: 'Phân quyền', desc: 'Gán quyền cho từng chức vụ'},
	].filter(Boolean);

	return (
		<div className="container">
			<div className={cn('dashboard')}>
				<div className={cn('dashboardHeader')}>
					<div>
						<h1 className={cn('dashboardTitle')}>
							Xin chào, {currentUser?.lastname} {currentUser?.firstname}
						</h1>
						<div className={cn('dashboardSub')}>
							{dayjs().format('dddd, DD/MM/YYYY')}
						</div>
					</div>
				</div>

				{quickActions.length > 0 && (
					<div className={cn('quickGrid')}>
						{quickActions.map((a) => (
							<Link key={a.to} to={a.to} className={cn('statCard', 'statCardLink')}>
								<div className={cn('statTop')}>
									<span className={cn('statIcon', a.tone)}><FontAwesomeIcon icon={a.icon} /></span>
									<div className={cn('statMain')}>
										<div className={cn('quickLabel')}>{a.label}</div>
										<div className={cn('statLabel')}>{a.desc}</div>
									</div>
								</div>
							</Link>
						))}
					</div>
				)}
			</div>
		</div>
	);
}

export default Home;
