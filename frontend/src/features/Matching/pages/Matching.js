import {useContext, useMemo, useState} from "react";
import {App as AntdApp, Table, Tag, Tabs, Empty} from "antd";
import {AppContext} from "~/context/AppProvider";
import {useCan} from "~/hooks";
import {PageHeader, FontAwesomeIcon, Button} from "~/components";
import {DebounceSelect} from "~/components/Forms";
import request from "~/utils/http";
import {rtkErrorMessage} from "~/reduxs/api/apiSlice";
import {
	useGetMatchOverviewQuery,
	useGetSuggestedPropertiesQuery,
	useGetMatchingCustomersQuery,
	useSendPropertyToCustomerMutation,
} from "~/reduxs/api/matchingApiSlice";
import SendMatchModal from "../components/SendMatchModal";
import {fmtPrice, matchScoreColor} from "../matchUtils";
import style from "../style/Matching.module.scss";

const STAGE_COLORS = {
	new: 'default', contacting: 'processing', potential: 'gold',
	negotiating: 'orange', won: 'success', lost: 'error',
};

const toMap = (arr = []) => arr.reduce((m, o) => ({...m, [o.value]: o.label}), {});

// Tìm khách/BĐS cho DebounceSelect — dùng endpoint list sẵn có (không cần route riêng).
const searchCustomers = async (keyword) => {
	const body = await request({url: 'customer', method: 'get', params: {keyword, pageSize: 20}});
	return (body?.data?.items || []).map((c) => ({
		value: c.id,
		label: `${c.full_name}${c.phone ? ' — ' + c.phone : ''}`,
	}));
};
const searchProperties = async (keyword) => {
	const body = await request({url: 'property', method: 'get', params: {keyword, pageSize: 20}});
	return (body?.data?.items || []).map((p) => ({
		value: p.id,
		label: `${p.code} — ${p.title}`,
	}));
};

// Chip lý do khớp (nhãn VN từ BE).
const Reasons = ({items = []}) => (
	<div className={style.reasons}>
		{items.map((r, i) => <span key={i} className={style.reasonChip}>{r}</span>)}
	</div>
);

function Matching() {

	const {notification} = AntdApp.useApp();
	const {appData} = useContext(AppContext);

	const propertyTypeMap = useMemo(() => toMap(appData?.property?.property_types || []), [appData]);
	const stageMap = useMemo(() => toMap(appData?.customer?.pipeline_stages || []), [appData]);

	const canSend = useCan('matching_send');

	// ── Tab 0: "Cơ hội của tôi" — khách của tôi ↔ BĐS khớp (màn hình đích của thông báo đẩy) ──
	const {data: overview = [], isFetching: loadingOverview} = useGetMatchOverviewQuery();

	// ── Tab 1: tìm BĐS cho khách ──
	const [customerId, setCustomerId] = useState(null);
	const {data: properties = [], isFetching: loadingProps} =
		useGetSuggestedPropertiesQuery(customerId, {skip: !customerId});

	// ── Tab 2: tìm khách cho BĐS ──
	const [propertyId, setPropertyId] = useState(null);
	const {data: customers = [], isFetching: loadingCusts} =
		useGetMatchingCustomersQuery(propertyId, {skip: !propertyId});

	// ── Gửi SP ──
	const [sendPropertyToCustomer, {isLoading: sending}] = useSendPropertyToCustomerMutation();
	const [send, setSend] = useState(null);   // {customerId, propertyId, demand_id, subtitle}

	const submitSend = async (note) => {
		try {
			await sendPropertyToCustomer({
				customerId: send.customerId,
				propertyId: send.propertyId,
				demand_id: send.demand_id || 0,
				note,
			}).unwrap();
			notification.success({message: 'Thành công', description: 'Đã gửi sản phẩm cho khách'});
			setSend(null);
		} catch (e) {
			notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Gửi sản phẩm thất bại')});
		}
	};

	// Nút hành động dùng chung (đã gửi → tag; chưa gửi + có quyền → nút gửi).
	const sendCell = (alreadySent, onSend) => {
		if (alreadySent) return <Tag color="success">Đã gửi</Tag>;
		if (!canSend) return null;
		return (
			<Button small outline leftIcon={<FontAwesomeIcon icon="fa-light fa-paper-plane" />} onClick={onSend}>
				Gửi
			</Button>
		);
	};

	// Cột bảng "Cơ hội của tôi": mỗi dòng = 1 cặp khách ↔ BĐS phù hợp.
	const overviewColumns = [
		{
			title: '', key: 'thumbnail', width: 56,
			render: (_, r) => (
				<div className={style.thumbCell}>
					{r.thumbnail ? <img src={r.thumbnail} alt="" /> : <FontAwesomeIcon icon="fa-light fa-image" />}
				</div>
			),
		},
		{
			title: 'Khách hàng', key: 'customer', width: 200,
			render: (_, r) => (
				<div>
					<div style={{fontWeight: 600}}>{r.customer_name}</div>
					<div style={{fontSize: 12, opacity: 0.65}}>{r.customer_phone || '—'}</div>
					{r.pipeline_stage
						? <Tag color={STAGE_COLORS[r.pipeline_stage] || 'default'} style={{marginTop: 4}}>
							{stageMap[r.pipeline_stage] || r.pipeline_stage}
						</Tag>
						: null}
				</div>
			),
		},
		{
			title: 'Bất động sản phù hợp', key: 'property',
			render: (_, r) => (
				<div>
					<div style={{fontWeight: 600}}>{r.code} — {r.title}</div>
					<div style={{fontSize: 12, opacity: 0.65}}>
						{(propertyTypeMap[r.property_type] || r.property_type || '—')} · {fmtPrice(r.price)}
					</div>
				</div>
			),
		},
		{
			title: 'Khớp', dataIndex: 'score', key: 'score', width: 70, align: 'center',
			render: (v) => <Tag color={matchScoreColor(v || 0)}>{v || 0}</Tag>,
		},
		{title: 'Lý do', dataIndex: 'reasons', key: 'reasons', render: (v) => <Reasons items={v} />},
		{
			title: '', key: 'actions', width: 90, align: 'right',
			render: (_, r) => sendCell(r.already_sent, () => setSend({
				customerId: r.customer_id, propertyId: r.property_id, demand_id: r.demand_id,
				subtitle: `${r.customer_name} ← ${r.code} — ${r.title}`,
			})),
		},
	];

	const propColumns = [
		{
			title: '', key: 'thumbnail', width: 56,
			render: (_, r) => (
				<div className={style.thumbCell}>
					{r.thumbnail ? <img src={r.thumbnail} alt="" /> : <FontAwesomeIcon icon="fa-light fa-image" />}
				</div>
			),
		},
		{title: 'Mã', dataIndex: 'code', key: 'code', width: 120},
		{title: 'Tiêu đề', dataIndex: 'title', key: 'title'},
		{
			title: 'Loại', dataIndex: 'property_type', key: 'property_type', width: 110,
			render: (v) => propertyTypeMap[v] || v || '—',
		},
		{
			title: 'Giá', dataIndex: 'price', key: 'price', width: 100, align: 'right',
			render: (v) => fmtPrice(v),
		},
		{
			title: 'Khớp', dataIndex: 'score', key: 'score', width: 70, align: 'center',
			render: (v) => <Tag color={matchScoreColor(v || 0)}>{v || 0}</Tag>,
		},
		{title: 'Lý do', dataIndex: 'reasons', key: 'reasons', render: (v) => <Reasons items={v} />},
		{
			title: '', key: 'actions', width: 90, align: 'right',
			render: (_, r) => sendCell(r.already_sent, () => setSend({
				customerId, propertyId: r.id, demand_id: r.demand_id, subtitle: `${r.code} — ${r.title}`,
			})),
		},
	];

	const custColumns = [
		{title: 'Khách hàng', dataIndex: 'full_name', key: 'full_name'},
		{title: 'Điện thoại', dataIndex: 'phone', key: 'phone', width: 130},
		{
			title: 'Giai đoạn', dataIndex: 'pipeline_stage', key: 'pipeline_stage', width: 130,
			render: (v) => <Tag color={STAGE_COLORS[v] || 'default'}>{stageMap[v] || v}</Tag>,
		},
		{
			title: 'Khớp', dataIndex: 'score', key: 'score', width: 70, align: 'center',
			render: (v) => <Tag color={matchScoreColor(v || 0)}>{v || 0}</Tag>,
		},
		{title: 'Lý do', dataIndex: 'reasons', key: 'reasons', render: (v) => <Reasons items={v} />},
		{
			title: '', key: 'actions', width: 90, align: 'right',
			render: (_, r) => sendCell(r.already_sent, () => setSend({
				customerId: r.id, propertyId, demand_id: r.demand_id, subtitle: r.full_name,
			})),
		},
	];

	const tab0 = (
		<div className="app-card">
			<Table
				rowKey={(r) => `${r.customer_id}:${r.property_id}`}
				columns={overviewColumns}
				dataSource={overview}
				loading={loadingOverview}
				size="middle"
				locale={{emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE}
					description="Chưa có cặp khách ↔ BĐS phù hợp. Khi có BĐS/nhu cầu mới khớp, cơ hội sẽ hiện ở đây." />}}
				pagination={{pageSize: 15, hideOnSinglePage: true, showTotal: (t) => `${t} cơ hội khớp`}}
			/>
		</div>
	);

	const tab1 = (
		<div>
			<div className={`${style.picker} form`}>
				<label>Chọn khách hàng cần tìm sản phẩm</label>
				<DebounceSelect
					placeholder="Gõ tên hoặc số điện thoại khách..."
					fetchOptions={searchCustomers}
					value={customerId || undefined}
					onChange={(v) => setCustomerId(v || null)}
					allowClear
					style={{width: '100%'}}
				/>
			</div>
			<div className="app-card">
				<Table
					rowKey="id"
					columns={propColumns}
					dataSource={properties}
					loading={loadingProps}
					size="middle"
					locale={{emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE}
						description={customerId ? 'Không có BĐS phù hợp nhu cầu' : 'Chọn khách để xem gợi ý'} />}}
					pagination={{pageSize: 10, hideOnSinglePage: true, showTotal: (t) => `${t} sản phẩm gợi ý`}}
				/>
			</div>
		</div>
	);

	const tab2 = (
		<div>
			<div className={`${style.picker} form`}>
				<label>Chọn bất động sản cần tìm khách</label>
				<DebounceSelect
					placeholder="Gõ mã hoặc tiêu đề BĐS..."
					fetchOptions={searchProperties}
					value={propertyId || undefined}
					onChange={(v) => setPropertyId(v || null)}
					allowClear
					style={{width: '100%'}}
				/>
			</div>
			<div className="app-card">
				<Table
					rowKey="id"
					columns={custColumns}
					dataSource={customers}
					loading={loadingCusts}
					size="middle"
					locale={{emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE}
						description={propertyId ? 'Không có khách phù hợp BĐS này' : 'Chọn BĐS để xem gợi ý'} />}}
					pagination={{pageSize: 10, hideOnSinglePage: true, showTotal: (t) => `${t} khách gợi ý`}}
				/>
			</div>
		</div>
	);

	return (
		<div className="container">
			<PageHeader
				icon="fa-light fa-arrows-repeat"
				title="Khớp lệnh (Matching)"
				subtitle="Gợi ý BĐS phù hợp nhu cầu khách và tìm khách cho từng sản phẩm"
			/>

			<Tabs
				defaultActiveKey="overview"
				items={[
					{key: 'overview', label: 'Cơ hội của tôi', children: tab0},
					{key: 'props', label: 'Tìm BĐS cho khách', children: tab1},
					{key: 'custs', label: 'Tìm khách cho BĐS', children: tab2},
				]}
			/>

			<SendMatchModal
				open={!!send}
				subtitle={send?.subtitle}
				loading={sending}
				onCancel={() => setSend(null)}
				onSubmit={submitSend}
			/>
		</div>
	);
}

export default Matching;
