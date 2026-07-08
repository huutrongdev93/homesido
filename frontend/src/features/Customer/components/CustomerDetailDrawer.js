import {useContext, useMemo, useState} from "react";
import {App as AntdApp, Drawer, Tag, Empty} from "antd";
import dayjs from "dayjs";
import {AppContext} from "~/context/AppProvider";
import {useCan} from "~/hooks";
import {Button, FontAwesomeIcon} from "~/components";
import {rtkErrorMessage} from "~/reduxs/api/apiSlice";
import {
	useGetCustomerCaresQuery,
	useGetCustomerInteractionsQuery,
	useAddCareMutation,
	useCompleteCareMutation,
	useCancelCareMutation,
	useAddInteractionMutation,
} from "~/reduxs/api/careApiSlice";
import {
	useTransferCustomerMutation,
	useGetCustomerDemandsQuery,
	useAddDemandMutation,
	useUpdateDemandMutation,
	useDeleteDemandMutation,
} from "~/reduxs/api/customerApiSlice";
import {
	useGetSuggestedPropertiesQuery,
	useSendPropertyToCustomerMutation,
} from "~/reduxs/api/matchingApiSlice";
import {useGetCareTemplatesQuery} from "~/reduxs/api/catalogApiSlice";
import {useGetProvincesQuery} from "~/reduxs/api/locationApiSlice";
import CareFormModal from "~/features/Care/components/CareFormModal";
import CareCompleteModal from "~/features/Care/components/CareCompleteModal";
import SendMatchModal from "~/features/Matching/components/SendMatchModal";
import {fmtPrice, matchScoreColor} from "~/features/Matching/matchUtils";
import InteractionFormModal from "./InteractionFormModal";
import CustomerTransferModal from "./CustomerTransferModal";
import CustomerDemandModal from "./CustomerDemandModal";
import style from "../style/CustomerDetail.module.scss";

const CARE_STATUS = {
	pending: {color: 'processing', label: 'Chờ chăm'},
	done: {color: 'success', label: 'Đã xong'},
	missed: {color: 'error', label: 'Lỡ hẹn'},
	canceled: {color: 'default', label: 'Đã hủy'},
};

const toMap = (arr = []) => arr.reduce((m, o) => ({...m, [o.value]: o.label}), {});
const fmt = (v) => (v ? dayjs(v).format('DD/MM/YYYY HH:mm') : '');

// Màu theo điểm tiềm năng (0–100): càng cao càng "nóng".
const scoreColor = (s) => (s >= 70 ? 'green' : s >= 40 ? 'gold' : s > 0 ? 'blue' : 'default');

/** Số tiền VNĐ → chuỗi gọn (tỷ / triệu). Trả '' nếu 0/rỗng. */
const money = (vnd) => {
	const n = Number(vnd) || 0;
	if (n <= 0) return '';
	if (n >= 1e9) return `${(n / 1e9).toLocaleString('vi-VN', {maximumFractionDigits: 2})} tỷ`;
	return `${Math.round(n / 1e6).toLocaleString('vi-VN')} triệu`;
};

/** Khoảng [min, max] → "từ X đến Y" / "từ X" / "đến Y" / '' (fmt = hàm format 1 đầu). */
const range = (min, max, fmt1, unit = '') => {
	const a = fmt1 ? fmt1(min) : (Number(min) > 0 ? `${min}${unit}` : '');
	const b = fmt1 ? fmt1(max) : (Number(max) > 0 ? `${max}${unit}` : '');
	if (a && b) return `${a} – ${b}`;
	if (a) return `từ ${a}`;
	if (b) return `đến ${b}`;
	return '';
};

/**
 * Drawer chi tiết khách: thông tin + Chăm sóc (lịch) + Timeline tương tác.
 * Props: open, customer, stageMap, tempMap, onClose.
 */
function CustomerDetailDrawer({open, customer, stageMap = {}, tempMap = {}, onClose}) {

	const {notification, modal} = AntdApp.useApp();
	const {appData} = useContext(AppContext);

	const careTypes = useMemo(() => appData?.care?.care_types || [], [appData]);
	const interactionTypes = useMemo(() => appData?.care?.interaction_types || [], [appData]);
	const careTypeMap = useMemo(() => toMap(careTypes), [careTypes]);
	const intTypeMap = useMemo(() => toMap(interactionTypes), [interactionTypes]);

	const demandTypes = useMemo(() => appData?.customer?.demand_types || [], [appData]);
	const purposes = useMemo(() => appData?.customer?.purposes || [], [appData]);
	const propertyTypes = useMemo(() => appData?.property?.property_types || [], [appData]);
	const directions = useMemo(() => appData?.property?.directions || [], [appData]);
	const demandTypeMap = useMemo(() => toMap(demandTypes), [demandTypes]);
	const purposeMap = useMemo(() => toMap(purposes), [purposes]);
	const propTypeMap = useMemo(() => toMap(propertyTypes), [propertyTypes]);
	const directionMap = useMemo(() => toMap(directions), [directions]);

	const canEdit = useCan('customer_edit');
	const canTransfer = useCan('customer_transfer');
	const canMatchView = useCan('matching_view');
	const canMatchSend = useCan('matching_send');

	const id = customer?.id;
	const skip = !open || !id;

	const {data: cares = []} = useGetCustomerCaresQuery(id, {skip});
	const {data: interactions = []} = useGetCustomerInteractionsQuery(id, {skip});
	const {data: demands = []} = useGetCustomerDemandsQuery(id, {skip});
	const {data: matchProps = []} = useGetSuggestedPropertiesQuery(id, {skip: skip || !canMatchView});
	const {data: careTemplates = []} = useGetCareTemplatesQuery(undefined, {skip: !open});
	const {data: provinces = []} = useGetProvincesQuery();
	const provinceMap = useMemo(() => toMap(provinces), [provinces]);

	const [addCare, {isLoading: addingCare}] = useAddCareMutation();
	const [completeCare, {isLoading: completing}] = useCompleteCareMutation();
	const [cancelCare] = useCancelCareMutation();
	const [addInteraction, {isLoading: addingInt}] = useAddInteractionMutation();
	const [transferCustomer, {isLoading: transferring}] = useTransferCustomerMutation();
	const [addDemand, {isLoading: addingDemand}] = useAddDemandMutation();
	const [updateDemand, {isLoading: updatingDemand}] = useUpdateDemandMutation();
	const [deleteDemand] = useDeleteDemandMutation();
	const [sendProperty, {isLoading: sendingMatch}] = useSendPropertyToCustomerMutation();

	const [openCare, setOpenCare] = useState(false);
	const [openComplete, setOpenComplete] = useState(null);   // care đang hoàn thành
	const [openInt, setOpenInt] = useState(false);
	const [openTransfer, setOpenTransfer] = useState(false);
	const [openDemand, setOpenDemand] = useState(null);   // false=đóng | {}=thêm | record=sửa
	const [sendMatch, setSendMatch] = useState(null);   // {propertyId, demand_id, subtitle} | null

	const events = {
		saveCare: async (data) => {
			try {
				await addCare({customer_id: id, ...data}).unwrap();
				setOpenCare(false);
				notification.success({message: 'Thành công', description: 'Đã đặt lịch chăm sóc'});
			} catch (e) {
				notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Đặt lịch thất bại')});
			}
		},
		complete: async (data) => {
			try {
				await completeCare({id: openComplete.id, ...data}).unwrap();
				setOpenComplete(null);
				notification.success({message: 'Thành công', description: 'Đã hoàn thành chăm sóc'});
			} catch (e) {
				notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Cập nhật thất bại')});
			}
		},
		cancel: (care) => {
			modal.confirm({
				title: 'Hủy lịch chăm', content: 'Bạn có chắc muốn hủy lịch chăm này?',
				okText: 'Hủy lịch', okButtonProps: {danger: true}, cancelText: 'Đóng',
				onOk: async () => {
					try {
						await cancelCare(care.id).unwrap();
						notification.success({message: 'Thành công', description: 'Đã hủy lịch chăm'});
					} catch (e) {
						notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Hủy thất bại')});
						return Promise.reject();
					}
				},
			});
		},
		saveInt: async (data) => {
			try {
				await addInteraction({customerId: id, ...data}).unwrap();
				setOpenInt(false);
				notification.success({message: 'Thành công', description: 'Đã ghi tương tác'});
			} catch (e) {
				notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Ghi tương tác thất bại')});
			}
		},
		transfer: async (data) => {
			try {
				await transferCustomer({id, ...data}).unwrap();
				setOpenTransfer(false);
				notification.success({message: 'Thành công', description: 'Đã bàn giao khách hàng'});
				onClose();   // khách rời phạm vi của mình → đóng drawer.
			} catch (e) {
				notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Bàn giao thất bại')});
			}
		},
		saveDemand: async (data, item) => {
			try {
				if (item?.id) await updateDemand({customerId: id, id: item.id, ...data}).unwrap();
				else await addDemand({customerId: id, ...data}).unwrap();
				setOpenDemand(null);
				notification.success({message: 'Thành công', description: item?.id ? 'Đã cập nhật nhu cầu' : 'Đã thêm nhu cầu'});
			} catch (e) {
				notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Lưu nhu cầu thất bại')});
			}
		},
		removeDemand: (demand) => {
			modal.confirm({
				title: 'Xóa nhu cầu', content: 'Bạn có chắc muốn xóa nhu cầu này?',
				okText: 'Xóa', okButtonProps: {danger: true}, cancelText: 'Đóng',
				onOk: async () => {
					try {
						await deleteDemand({customerId: id, id: demand.id}).unwrap();
						notification.success({message: 'Thành công', description: 'Đã xóa nhu cầu'});
					} catch (e) {
						notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Xóa thất bại')});
						return Promise.reject();
					}
				},
			});
		},
		sendMatch: async (note) => {
			try {
				await sendProperty({customerId: id, propertyId: sendMatch.propertyId, demand_id: sendMatch.demand_id || 0, note}).unwrap();
				setSendMatch(null);
				notification.success({message: 'Thành công', description: 'Đã gửi sản phẩm cho khách'});
			} catch (e) {
				notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Gửi sản phẩm thất bại')});
			}
		},
	};

	return (
		<Drawer
			open={open}
			onClose={onClose}
			width={560}
			title={customer ? customer.full_name : ''}
			destroyOnClose
		>
			{customer && (
				<div className={style.wrap}>
					<div className={style.head}>
						<div className={style.phone}><FontAwesomeIcon icon="fa-light fa-phone" /> {customer.phone}</div>
						<div className={style.tags}>
							{customer.pipeline_stage && <Tag>{stageMap[customer.pipeline_stage] || customer.pipeline_stage}</Tag>}
							{customer.temperature && <Tag>{tempMap[customer.temperature] || customer.temperature}</Tag>}
							<Tag color={scoreColor(customer.lead_score || 0)}>Điểm {customer.lead_score || 0}</Tag>
						</div>
						{canTransfer && (
							<Button small outline leftIcon={<FontAwesomeIcon icon="fa-light fa-arrow-right-arrow-left" />} onClick={() => setOpenTransfer(true)}>
								Bàn giao
							</Button>
						)}
					</div>

					{/* NHU CẦU / TIÊU CHÍ */}
					<section className={style.section}>
						<div className={style.sectionHead}>
							<h4><FontAwesomeIcon icon="fa-light fa-magnifying-glass-location" /> Nhu cầu / tiêu chí</h4>
							{canEdit && (
								<Button small outline leftIcon={<FontAwesomeIcon icon="fa-light fa-plus" />} onClick={() => setOpenDemand({})}>
									Thêm nhu cầu
								</Button>
							)}
						</div>
						{demands.length === 0
							? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Chưa có nhu cầu" />
							: demands.map((d) => {
								const budget = range(d.budget_min, d.budget_max, money);
								const area = range(d.area_min, d.area_max, null, 'm²');
								const parts = [
									budget ? `💰 ${budget}` : '',
									area ? `📐 ${area}` : '',
									d.bedrooms_min > 0 ? `🛏 ≥ ${d.bedrooms_min} PN` : '',
									d.direction ? `🧭 ${directionMap[d.direction] || d.direction}` : '',
									d.province_code ? `📍 ${provinceMap[d.province_code] || ''}` : '',
									d.purpose ? (purposeMap[d.purpose] || d.purpose) : '',
								].filter(Boolean);
								return (
									<div key={d.id} className={style.demandItem}>
										<div className={style.demandMain}>
											<Tag color="blue">{demandTypeMap[d.demand_type] || d.demand_type}</Tag>
											{d.property_type && <span className={style.demandType}>{propTypeMap[d.property_type] || d.property_type}</span>}
											{!d.is_active && <Tag>Ngừng tìm</Tag>}
										</div>
										{parts.length > 0 && <p className={style.demandCriteria}>{parts.join('  ·  ')}</p>}
										{canEdit && (
											<div className={style.careActions}>
												<button type="button" onClick={() => setOpenDemand(d)}>Sửa</button>
												<button type="button" className={style.linkDanger} onClick={() => events.removeDemand(d)}>Xóa</button>
											</div>
										)}
									</div>
								);
							})}
					</section>

					{/* GỢI Ý BĐS (Matching) */}
					{canMatchView && (
						<section className={style.section}>
							<div className={style.sectionHead}>
								<h4><FontAwesomeIcon icon="fa-light fa-arrows-repeat" /> Gợi ý bất động sản</h4>
							</div>
							{matchProps.length === 0
								? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Chưa có BĐS phù hợp" />
								: matchProps.slice(0, 8).map((p) => (
									<div key={p.id} className={style.demandItem}>
										<div className={style.demandMain}>
											<Tag color={matchScoreColor(p.score || 0)}>{p.score || 0}</Tag>
											<span className={style.demandType}>{p.code} — {p.title}</span>
											{p.already_sent && <Tag color="success">Đã gửi</Tag>}
										</div>
										<p className={style.demandCriteria}>
											💰 {fmtPrice(p.price)}{p.reasons?.length ? '  ·  ' + p.reasons.join('  ·  ') : ''}
										</p>
										{canMatchSend && !p.already_sent && (
											<div className={style.careActions}>
												<button type="button" onClick={() => setSendMatch({propertyId: p.id, demand_id: p.demand_id, subtitle: `${p.code} — ${p.title}`})}>Gửi cho khách</button>
											</div>
										)}
									</div>
								))}
						</section>
					)}

					{/* CHĂM SÓC */}
					<section className={style.section}>
						<div className={style.sectionHead}>
							<h4><FontAwesomeIcon icon="fa-light fa-calendar-heart" /> Lịch chăm sóc</h4>
							{canEdit && (
								<Button small primary leftIcon={<FontAwesomeIcon icon="fa-light fa-plus" />} onClick={() => setOpenCare(true)}>
									Đặt lịch
								</Button>
							)}
						</div>
						{cares.length === 0
							? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Chưa có lịch chăm" />
							: cares.map((c) => (
								<div key={c.id} className={style.careItem}>
									<div className={style.careMain}>
										<Tag color={CARE_STATUS[c.status]?.color}>{CARE_STATUS[c.status]?.label || c.status}</Tag>
										<span className={style.careType}>{careTypeMap[c.type] || c.type}</span>
										<span className={style.careTime}>{fmt(c.scheduled_at)}</span>
									</div>
									{c.content && <p className={style.careNote}>{c.content}</p>}
									{c.result_note && <p className={style.careResult}>→ {c.result_note}</p>}
									{canEdit && c.status === 'pending' && (
										<div className={style.careActions}>
											<button type="button" onClick={() => setOpenComplete(c)}>Hoàn thành</button>
											<button type="button" className={style.linkDanger} onClick={() => events.cancel(c)}>Hủy</button>
										</div>
									)}
								</div>
							))}
					</section>

					{/* TIMELINE */}
					<section className={style.section}>
						<div className={style.sectionHead}>
							<h4><FontAwesomeIcon icon="fa-light fa-timeline" /> Timeline tương tác</h4>
							{canEdit && (
								<Button small outline leftIcon={<FontAwesomeIcon icon="fa-light fa-comment-dots" />} onClick={() => setOpenInt(true)}>
									Ghi tương tác
								</Button>
							)}
						</div>
						{interactions.length === 0
							? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Chưa có tương tác" />
							: <ul className={style.timeline}>
								{interactions.map((i) => (
									<li key={i.id} className={style.tlItem}>
										<span className={style.tlDot} />
										<div className={style.tlBody}>
											<div className={style.tlMeta}>
												<strong>{intTypeMap[i.type] || i.type}</strong>
												<span>{fmt(i.interacted_at)}</span>
											</div>
											<p>{i.content}</p>
										</div>
									</li>
								))}
							</ul>}
					</section>
				</div>
			)}

			<CareFormModal open={openCare} loading={addingCare} careTypes={careTypes}
				careTemplates={careTemplates} customerName={customer?.full_name}
				onCancel={() => setOpenCare(false)} onSubmit={events.saveCare} />
			<CareCompleteModal open={!!openComplete} care={openComplete} loading={completing} careTypes={careTypes}
				careTemplates={careTemplates} customerName={customer?.full_name}
				onCancel={() => setOpenComplete(null)} onSubmit={events.complete} />
			<InteractionFormModal open={openInt} loading={addingInt} interactionTypes={interactionTypes}
				onCancel={() => setOpenInt(false)} onSubmit={events.saveInt} />
			<CustomerTransferModal open={openTransfer} loading={transferring} customer={customer}
				onCancel={() => setOpenTransfer(false)} onSubmit={events.transfer} />
			<CustomerDemandModal open={!!openDemand} item={openDemand?.id ? openDemand : null}
				loading={addingDemand || updatingDemand}
				options={{demandTypes, propertyTypes, purposes, directions}}
				onCancel={() => setOpenDemand(null)} onSubmit={events.saveDemand} />
			<SendMatchModal open={!!sendMatch} subtitle={sendMatch?.subtitle} loading={sendingMatch}
				onCancel={() => setSendMatch(null)} onSubmit={events.sendMatch} />
		</Drawer>
	);
}

export default CustomerDetailDrawer;
