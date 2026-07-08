import {useContext, useMemo, useState} from "react";
import {App as AntdApp, Table} from "antd";
import dayjs from "dayjs";
import {AppContext} from "~/context/AppProvider";
import {useCan} from "~/hooks";
import {Button, PageHeader, FontAwesomeIcon} from "~/components";
import {DateRangeField} from "~/components/Forms";
import {useGetReportQuery} from "~/reduxs/api/reportApiSlice";
import {exportReport} from "~/api/reportFileApi";
import {fmtMoney} from "~/features/Deal/dealUtils";
import style from "../style/Report.module.scss";

const toMap = (arr = []) => arr.reduce((m, o) => ({...m, [o.value]: o.label}), {});

// Thanh CSS đơn giản (không dùng thư viện chart) — width theo tỉ lệ value/max.
function Bar({label, value, max, display, color}) {
	const pct = max > 0 ? Math.round((value / max) * 100) : 0;
	return (
		<div className={style.barRow}>
			<span className={style.barLabel}>{label}</span>
			<span className={style.barTrack}>
				<span className={style.barFill} style={{width: `${pct}%`, background: color}} />
			</span>
			<span className={style.barVal}>{display ?? value}</span>
		</div>
	);
}

function Report() {

	const {notification} = AntdApp.useApp();
	const {appData} = useContext(AppContext);

	const stageMap = useMemo(() => toMap(appData?.customer?.pipeline_stages || []), [appData]);
	const dealStatusMap = useMemo(() => toMap(appData?.deal?.statuses || []), [appData]);
	const canViewAll = useCan('report_view_all');

	const [range, setRange] = useState(null); // [dayjs, dayjs] | null
	const [exporting, setExporting] = useState(false);

	const params = useMemo(() => {
		if (range && range[0] && range[1]) {
			return {from: range[0].format('YYYY-MM-DD'), to: range[1].format('YYYY-MM-DD')};
		}
		return {};
	}, [range]);

	const {data, isFetching} = useGetReportQuery(params, {refetchOnMountOrArgChange: true});

	const onExport = async () => {
		setExporting(true);
		try {
			await exportReport(params);
		} catch (e) {
			notification.error({message: 'Lỗi', description: 'Xuất Excel thất bại, vui lòng thử lại.'});
		} finally {
			setExporting(false);
		}
	};

	const funnel = data?.funnel;
	const sales = data?.sales;
	const sources = data?.sources || [];
	const team = data?.team || [];

	const stageMax = useMemo(() => Math.max(1, ...Object.values(funnel?.by_stage || {})), [funnel]);
	const monthMax = useMemo(() => Math.max(1, ...(sales?.monthly || []).map((m) => m.value)), [sales]);

	const sourceCols = [
		{title: 'Nguồn', dataIndex: 'name', key: 'name'},
		{title: 'Tổng khách', dataIndex: 'total', key: 'total', width: 120, align: 'right'},
		{title: 'Đã chốt', dataIndex: 'won', key: 'won', width: 100, align: 'right'},
	];

	const teamCols = [
		{title: 'Nhân viên', dataIndex: 'name', key: 'name'},
		{title: 'Khách', dataIndex: 'customers', key: 'customers', width: 80, align: 'right'},
		{title: 'Chốt', dataIndex: 'won', key: 'won', width: 70, align: 'right'},
		{title: 'Giao dịch', dataIndex: 'deals', key: 'deals', width: 90, align: 'right'},
		{title: 'Hoàn tất', dataIndex: 'completed', key: 'completed', width: 90, align: 'right'},
		{title: 'Doanh thu', dataIndex: 'revenue', key: 'revenue', width: 120, align: 'right', render: (v) => fmtMoney(v)},
		{title: 'Hoa hồng', dataIndex: 'commission', key: 'commission', width: 120, align: 'right', render: (v) => fmtMoney(v)},
	];

	return (
		<div className="container">
			<PageHeader
				icon="fa-light fa-chart-line"
				title="Báo cáo"
				subtitle="Phễu chuyển đổi · nguồn khách · doanh số · hiệu suất nhóm"
				actions={(
					<Button outline loading={exporting} leftIcon={<FontAwesomeIcon icon="fa-light fa-file-export" />} onClick={onExport}>
						Xuất Excel
					</Button>
				)}
			/>

			<div className={`${style.filterBar} form`}>
				<DateRangeField value={range} onChange={(v) => setRange(v)} placeholder={['Từ ngày', 'Đến ngày']} />
				{data?.scope && <span className={style.scopeTag}>{data.scope === 'own' ? 'Của tôi' : 'Toàn sàn'}</span>}
			</div>

			{/* KPI */}
			<div className={style.kpiRow}>
				<div className={style.kpi}><span>Doanh thu (hoàn tất)</span><b>{fmtMoney(sales?.revenue)}</b></div>
				<div className={style.kpi}><span>Đang chốt (cọc/HĐ)</span><b>{fmtMoney(sales?.pipeline_value)}</b></div>
				<div className={style.kpi}><span>Hoa hồng</span><b>{fmtMoney(sales?.commission)}</b></div>
				<div className={style.kpi}><span>Tỉ lệ chốt</span><b>{funnel?.won_rate ?? 0}%</b></div>
			</div>

			<div className={style.grid2}>
				{/* Phễu khách */}
				<div className="app-card">
					<h3 className={style.cardTitle}>Phễu khách hàng ({funnel?.total ?? 0})</h3>
					<div className={style.funnel}>
						{Object.entries(funnel?.by_stage || {}).map(([stage, count]) => (
							<Bar key={stage} label={stageMap[stage] || stage} value={count} max={stageMax} color="#2563eb" />
						))}
					</div>
				</div>

				{/* Doanh thu 6 tháng */}
				<div className="app-card">
					<h3 className={style.cardTitle}>Doanh thu 6 tháng</h3>
					<div className={style.funnel}>
						{(sales?.monthly || []).map((m) => (
							<Bar key={m.month} label={dayjs(m.month + '-01').format('MM/YYYY')} value={m.value} max={monthMax}
								display={fmtMoney(m.value)} color="#16a34a" />
						))}
					</div>
				</div>
			</div>

			<div className={style.grid2}>
				{/* Nguồn khách */}
				<div className="app-card">
					<h3 className={style.cardTitle}>Nguồn khách</h3>
					<Table rowKey="id" size="small" columns={sourceCols} dataSource={sources} loading={isFetching}
						pagination={false} locale={{emptyText: 'Chưa có dữ liệu'}} />
				</div>

				{/* Doanh số theo giai đoạn */}
				<div className="app-card">
					<h3 className={style.cardTitle}>Doanh số theo giai đoạn</h3>
					<Table rowKey="key" size="small" loading={isFetching} pagination={false}
						locale={{emptyText: 'Chưa có dữ liệu'}}
						columns={[
							{title: 'Giai đoạn', dataIndex: 'label', key: 'label'},
							{title: 'Số GD', dataIndex: 'count', key: 'count', width: 90, align: 'right'},
							{title: 'Giá trị', dataIndex: 'value', key: 'value', width: 120, align: 'right', render: (v) => fmtMoney(v)},
						]}
						dataSource={Object.entries(sales?.by_status || {}).map(([k, v]) => ({
							key: k, label: dealStatusMap[k] || k, count: v.count, value: v.value,
						}))}
					/>
				</div>
			</div>

			{/* Hiệu suất nhóm — chỉ hiện với quyền toàn sàn */}
			{canViewAll && (
				<div className="app-card">
					<h3 className={style.cardTitle}>Hiệu suất nhân viên</h3>
					<Table rowKey="user_id" size="small" columns={teamCols} dataSource={team} loading={isFetching}
						pagination={false} locale={{emptyText: 'Chưa có dữ liệu'}} />
				</div>
			)}
		</div>
	);
}

export default Report;
