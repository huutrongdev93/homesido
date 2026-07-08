import {useContext, useMemo} from "react";
import {Link} from "react-router-dom";
import {useSelector} from "react-redux";
import dayjs from "dayjs";
import 'dayjs/locale/vi';
import className from "classnames/bind";
import {Spin} from "antd";
import {FontAwesomeIcon} from "~/components";
import {useCan, useIsAdmin} from "~/hooks";
import {AppContext} from "~/context/AppProvider";
import {currentUserSelector} from "~/reduxs/Auth/authSlice";
import {useGetDashboardQuery} from "~/reduxs/api/dashboardApiSlice";
import style from '../style/Home.module.scss'

dayjs.locale('vi');

const cn = className.bind(style);

const toMap = (arr = []) => arr.reduce((m, o) => ({...m, [o.value]: o.label}), {});

// Tone màu cho từng trạng thái BĐS (khớp bảng .statIcon trong Home.module.scss).
const STATUS_TONE = {available: 'success', deposited: 'warning', sold: 'primary', rented: 'purple', inactive: 'danger'};

/**
 * Dashboard — trang chủ. Số liệu tổng hợp (GET api/dashboard, đã áp data-scope):
 * KPI tháng + "cần chăm hôm nay" + phễu khách theo giai đoạn + kho BĐS theo trạng thái.
 * Không có quyền xem khách (endpoint 403) → chỉ hiện lời chào + lối tắt.
 */
function Home() {
	const currentUser = useSelector(currentUserSelector);
	const isAdmin = useIsAdmin();
	const {appData} = useContext(AppContext);

	const canProperty = useCan('property_view');

	const stages = useMemo(() => appData?.customer?.pipeline_stages || [], [appData]);
	const statuses = useMemo(() => appData?.property?.statuses || [], [appData]);
	const stageMap = useMemo(() => toMap(stages), [stages]);
	const statusMap = useMemo(() => toMap(statuses), [statuses]);

	const {data, isLoading} = useGetDashboardQuery();

	const kpis = data ? [
		{to: '/care', icon: 'fa-light fa-calendar-heart', tone: data.care.overdue > 0 ? 'danger' : 'warning',
			value: data.care.today, label: 'Cần chăm hôm nay',
			sub: data.care.overdue > 0 ? `${data.care.overdue} việc quá hạn` : 'Không có việc quá hạn'},
		{to: '/customers', icon: 'fa-light fa-user-plus', tone: 'primary',
			value: data.month.new_customers, label: 'Khách mới tháng này', sub: `Tổng ${data.customers.total} khách`},
		{icon: 'fa-light fa-comments', tone: 'purple',
			value: data.month.interactions, label: 'Tương tác tháng này', sub: 'Cuộc gọi / tin nhắn / gặp'},
		{to: '/customers', icon: 'fa-light fa-snowflake', tone: 'danger',
			value: data.customers.cold, label: 'Khách nguội', sub: 'Lâu chưa tương tác'},
	] : [];

	const quickActions = [
		{to: '/account', icon: 'fa-light fa-id-card', tone: 'primary', label: 'Hồ sơ cá nhân', desc: 'Cập nhật thông tin & mật khẩu'},
		isAdmin && {to: '/admin/permission', icon: 'fa-light fa-user-lock', tone: 'purple', label: 'Phân quyền', desc: 'Gán quyền cho từng chức vụ'},
	].filter(Boolean);

	const stageMax = data ? Math.max(1, ...stages.map((s) => data.customers.by_stage[s.value] || 0)) : 1;
	const statusMax = data ? Math.max(1, ...statuses.map((s) => data.properties.by_status[s.value] || 0)) : 1;

	return (
		<div className="container">
			<div className={cn('dashboard')}>
				<div className={cn('dashboardHeader')}>
					<div>
						<h1 className={cn('dashboardTitle')}>
							Xin chào, {currentUser?.lastname} {currentUser?.firstname}
						</h1>
						<div className={cn('dashboardSub')}>{dayjs().format('dddd, DD/MM/YYYY')}</div>
					</div>
					{data?.scope === 'all' && (
						<span className={cn('scopeBadge')}>
							<FontAwesomeIcon icon="fa-light fa-globe" /> Toàn sàn
						</span>
					)}
				</div>

				{isLoading && <div className={cn('loading')}><Spin /></div>}

				{data && (
					<>
						{/* ── KPI ── */}
						<div className={cn('kpiGrid')}>
							{kpis.map((k, i) => {
								const inner = (
									<div className={cn('statTop')}>
										<span className={cn('statIcon', k.tone)}><FontAwesomeIcon icon={k.icon} /></span>
										<div className={cn('statMain')}>
											<div className={cn('kpiValue')}>{k.value}</div>
											<div className={cn('quickLabel')}>{k.label}</div>
											<div className={cn('statLabel')}>{k.sub}</div>
										</div>
									</div>
								);
								return k.to
									? <Link key={i} to={k.to} className={cn('statCard', 'statCardLink')}>{inner}</Link>
									: <div key={i} className={cn('statCard')}>{inner}</div>;
							})}
						</div>

						{/* ── Phễu khách + Kho BĐS ── */}
						<div className={cn('panels')}>
							<section className={cn('panel')}>
								<div className={cn('panelHead')}>
									<h3><FontAwesomeIcon icon="fa-light fa-filter" /> Phễu khách hàng</h3>
									<Link to="/customers" className={cn('panelLink')}>Xem tất cả</Link>
								</div>
								{stages.map((s) => {
									const val = data.customers.by_stage[s.value] || 0;
									return (
										<div key={s.value} className={cn('bar')}>
											<span className={cn('barLabel')}>{stageMap[s.value] || s.value}</span>
											<div className={cn('barTrack')}>
												<div className={cn('barFill', 'primary')} style={{width: `${(val / stageMax) * 100}%`}} />
											</div>
											<span className={cn('barValue')}>{val}</span>
										</div>
									);
								})}
							</section>

							{canProperty && (
								<section className={cn('panel')}>
									<div className={cn('panelHead')}>
										<h3><FontAwesomeIcon icon="fa-light fa-warehouse" /> Kho bất động sản</h3>
										<Link to="/properties" className={cn('panelLink')}>Xem tất cả</Link>
									</div>
									{statuses.map((s) => {
										const val = data.properties.by_status[s.value] || 0;
										return (
											<div key={s.value} className={cn('bar')}>
												<span className={cn('barLabel')}>{statusMap[s.value] || s.value}</span>
												<div className={cn('barTrack')}>
													<div className={cn('barFill', STATUS_TONE[s.value] || 'primary')} style={{width: `${(val / statusMax) * 100}%`}} />
												</div>
												<span className={cn('barValue')}>{val}</span>
											</div>
										);
									})}
									<div className={cn('panelFoot')}>Tổng {data.properties.total} bất động sản</div>
								</section>
							)}
						</div>
					</>
				)}

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
