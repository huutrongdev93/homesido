import {useContext, useMemo, useState} from "react";
import {App as AntdApp, Drawer, Tag, Empty} from "antd";
import dayjs from "dayjs";
import {AppContext} from "~/context/AppProvider";
import {useCan} from "~/hooks";
import {Button, FontAwesomeIcon} from "~/components";
import {rtkErrorMessage} from "~/reduxs/api/apiSlice";
import {
	useGetDealQuery,
	useChangeDealStatusMutation,
	useAddDealPaymentMutation,
	useMarkDealPaymentPaidMutation,
	useDeleteDealPaymentMutation,
	useAddDealReminderMutation,
	useUpdateDealReminderMutation,
	useDeleteDealReminderMutation,
	useUpdateDealCommissionMutation,
} from "~/reduxs/api/dealApiSlice";
import DealPaymentFormModal from "./DealPaymentFormModal";
import DealReminderFormModal from "./DealReminderFormModal";
import {fmtMoney, DEAL_STATUS_TAG, PAYMENT_STATUS_TAG, REMINDER_STATUS_TAG, ACTIVITY_DOT} from "../dealUtils";
import style from "../style/Deal.module.scss";

const toMap = (arr = []) => arr.reduce((m, o) => ({...m, [o.value]: o.label}), {});
const fmt = (v) => (v ? dayjs(v).format('DD/MM/YYYY HH:mm') : '—');

/**
 * Drawer chi tiết giao dịch: header (mã/giai đoạn/giá trị/hoa hồng) + chuyển giai đoạn +
 * các đợt thanh toán (đã thu / dự kiến) + nhắc hẹn + hoa hồng + lịch sử hoạt động.
 * Props: open, dealId, onClose.
 */
function DealDetailDrawer({open, dealId, onClose}) {

	const {notification, modal} = AntdApp.useApp();
	const {appData} = useContext(AppContext);

	const statuses = useMemo(() => appData?.deal?.statuses || [], [appData]);
	const methods  = useMemo(() => appData?.deal?.payment_methods || [], [appData]);
	const statusMap = useMemo(() => toMap(statuses), [statuses]);
	const methodMap = useMemo(() => toMap(methods), [methods]);
	const paymentStatusMap = useMemo(() => toMap(appData?.deal?.payment_statuses || []), [appData]);
	const reminderStatusMap = useMemo(() => toMap(appData?.deal?.reminder_statuses || []), [appData]);

	const canEdit = useCan('deal_edit');
	const canCommission = useCan('commission_manage');

	const skip = !open || !dealId;
	const {data: deal, isFetching} = useGetDealQuery(dealId, {skip});

	const [changeStatus] = useChangeDealStatusMutation();
	const [addPayment, {isLoading: addingPayment}] = useAddDealPaymentMutation();
	const [markPaid] = useMarkDealPaymentPaidMutation();
	const [deletePayment] = useDeleteDealPaymentMutation();
	const [addReminder, {isLoading: addingReminder}] = useAddDealReminderMutation();
	const [updateReminder] = useUpdateDealReminderMutation();
	const [deleteReminder] = useDeleteDealReminderMutation();
	const [updateCommission] = useUpdateDealCommissionMutation();

	const [openPayment, setOpenPayment] = useState(false);
	const [openReminder, setOpenReminder] = useState(false);

	const onChangeStatus = (status, label) => {
		const run = async () => {
			try {
				await changeStatus({id: dealId, status}).unwrap();
				notification.success({message: 'Thành công', description: `Đã chuyển: ${label}`});
			} catch (e) {
				notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Chuyển giai đoạn thất bại')});
			}
		};
		if (status === 'completed' || status === 'canceled') {
			modal.confirm({
				title: `Chuyển sang "${label}"?`,
				content: status === 'completed'
					? 'BĐS sẽ được đánh dấu đã bán/cho thuê.'
					: 'BĐS sẽ được trả về kho (đang bán).',
				okText: 'Xác nhận', cancelText: 'Đóng', onOk: run,
			});
		} else {
			run();
		}
	};

	const onAddPayment = async (payload) => {
		try {
			await addPayment({dealId, ...payload}).unwrap();
			setOpenPayment(false);
			notification.success({message: 'Thành công', description: 'Đã ghi đợt thanh toán'});
		} catch (e) {
			notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Ghi thanh toán thất bại')});
		}
	};

	const onMarkPaid = async (p) => {
		try {
			await markPaid({dealId, id: p.id}).unwrap();
			notification.success({message: 'Thành công', description: 'Đã đánh dấu đã thu'});
		} catch (e) {
			notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Cập nhật thất bại')});
		}
	};

	const onDeletePayment = (p) => {
		modal.confirm({
			title: 'Xóa đợt thanh toán',
			content: `Xóa đợt ${fmtMoney(p.amount)}?`,
			okText: 'Xóa', okButtonProps: {danger: true}, cancelText: 'Đóng',
			onOk: async () => {
				try {
					await deletePayment({dealId, id: p.id}).unwrap();
					notification.success({message: 'Thành công', description: 'Đã xóa đợt thanh toán'});
				} catch (e) {
					notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Xóa thất bại')});
				}
			},
		});
	};

	const onAddReminder = async (payload) => {
		try {
			await addReminder({dealId, ...payload}).unwrap();
			setOpenReminder(false);
			notification.success({message: 'Thành công', description: 'Đã tạo nhắc hẹn'});
		} catch (e) {
			notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Tạo nhắc hẹn thất bại')});
		}
	};

	const onDoneReminder = async (r) => {
		try {
			await updateReminder({dealId, id: r.id, status: 'done'}).unwrap();
			notification.success({message: 'Thành công', description: 'Đã đánh dấu xong'});
		} catch (e) {
			notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Cập nhật thất bại')});
		}
	};

	const onDeleteReminder = (r) => {
		modal.confirm({
			title: 'Xóa nhắc hẹn',
			content: `Xóa nhắc "${r.title}"?`,
			okText: 'Xóa', okButtonProps: {danger: true}, cancelText: 'Đóng',
			onOk: async () => {
				try {
					await deleteReminder({dealId, id: r.id}).unwrap();
					notification.success({message: 'Thành công', description: 'Đã xóa nhắc hẹn'});
				} catch (e) {
					notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Xóa thất bại')});
				}
			},
		});
	};

	const onToggleCommission = async () => {
		const next = deal?.commission?.status === 'paid' ? 'pending' : 'paid';
		try {
			await updateCommission({id: dealId, status: next}).unwrap();
			notification.success({message: 'Thành công', description: next === 'paid' ? 'Đã đánh dấu chi hoa hồng' : 'Đã chuyển về chờ chi'});
		} catch (e) {
			notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Cập nhật hoa hồng thất bại')});
		}
	};

	const commission = deal?.commission;

	return (
		<Drawer
			open={open}
			onClose={onClose}
			width={560}
			destroyOnClose
			title={deal ? `Giao dịch ${deal.code}` : 'Giao dịch'}
			loading={isFetching}
		>
			{deal && (
				<div className={style.drawer}>
					{/* Header tóm tắt */}
					<div className={style.head}>
						<Tag color={DEAL_STATUS_TAG[deal.status] || 'default'}>{statusMap[deal.status] || deal.status}</Tag>
						<div className={style.value}>{fmtMoney(deal.value)}</div>
						<div className={style.sub}>
							<div><b>Khách:</b> {deal.customer?.full_name} {deal.customer?.phone ? `· ${deal.customer.phone}` : ''}</div>
							<div><b>BĐS:</b> {deal.property?.code} — {deal.property?.title}</div>
						</div>
					</div>

					{/* Chuyển giai đoạn */}
					{canEdit && (
						<section className={style.section}>
							<h4>Chuyển giai đoạn</h4>
							<div className={style.statusBtns}>
								{statuses.filter((s) => s.value !== deal.status).map((s) => (
									<Button key={s.value} small outline onClick={() => onChangeStatus(s.value, s.label)}>
										{s.label}
									</Button>
								))}
							</div>
						</section>
					)}

					{/* Đợt thanh toán */}
					<section className={style.section}>
						<div className={style.sectionHead}>
							<h4>Thanh toán</h4>
							{canEdit && (
								<Button small outline leftIcon={<FontAwesomeIcon icon="fa-light fa-plus" />} onClick={() => setOpenPayment(true)}>
									Thêm đợt
								</Button>
							)}
						</div>
						<div className={style.money}>
							<span>Đã thu <b>{fmtMoney(deal.paid_total)}</b></span>
							<span>Còn lại <b>{fmtMoney(deal.remaining)}</b></span>
							{deal.planned_total > 0 && <span>Dự kiến <b>{fmtMoney(deal.planned_total)}</b></span>}
						</div>
						{(!deal.payments || deal.payments.length === 0) ? (
							<Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Chưa có đợt thu nào" />
						) : (
							<ul className={style.list}>
								{deal.payments.map((p) => (
									<li key={p.id}>
										<div>
											<b>{fmtMoney(p.amount)}</b>
											<Tag color={PAYMENT_STATUS_TAG[p.status] || 'default'} style={{marginLeft: 8}}>
												{paymentStatusMap[p.status] || p.status}
											</Tag>
											{p.method && <Tag style={{marginLeft: 4}}>{methodMap[p.method] || p.method}</Tag>}
											<div className={style.muted}>
												{p.status === 'planned' ? `Đến hạn: ${fmt(p.due_date)}` : fmt(p.paid_at)}
												{p.note ? ` · ${p.note}` : ''}
											</div>
										</div>
										{canEdit && (
											<div className={style.rowActions}>
												{p.status === 'planned' && (
													<button type="button" className={style.link} onClick={() => onMarkPaid(p)}>Đã thu</button>
												)}
												<button type="button" className={style.linkDanger} onClick={() => onDeletePayment(p)}>Xóa</button>
											</div>
										)}
									</li>
								))}
							</ul>
						)}
					</section>

					{/* Nhắc hẹn */}
					<section className={style.section}>
						<div className={style.sectionHead}>
							<h4>Nhắc hẹn</h4>
							{canEdit && (
								<Button small outline leftIcon={<FontAwesomeIcon icon="fa-light fa-plus" />} onClick={() => setOpenReminder(true)}>
									Thêm nhắc hẹn
								</Button>
							)}
						</div>
						{(!deal.reminders || deal.reminders.length === 0) ? (
							<Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Chưa có nhắc hẹn" />
						) : (
							<ul className={style.list}>
								{deal.reminders.map((r) => (
									<li key={r.id}>
										<div>
											<b className={r.status === 'done' ? style.doneText : undefined}>{r.title}</b>
											<Tag color={REMINDER_STATUS_TAG[r.status] || 'default'} style={{marginLeft: 8}}>
												{reminderStatusMap[r.status] || r.status}
											</Tag>
											<div className={style.muted}>{fmt(r.remind_at)}{r.note ? ` · ${r.note}` : ''}</div>
										</div>
										{canEdit && (
											<div className={style.rowActions}>
												{r.status !== 'done' && (
													<button type="button" className={style.link} onClick={() => onDoneReminder(r)}>Xong</button>
												)}
												<button type="button" className={style.linkDanger} onClick={() => onDeleteReminder(r)}>Xóa</button>
											</div>
										)}
									</li>
								))}
							</ul>
						)}
					</section>

					{/* Hoa hồng */}
					<section className={style.section}>
						<h4>Hoa hồng</h4>
						{commission ? (
							<div className={style.commission}>
								<div>
									<b>{fmtMoney(commission.amount)}</b>
									{commission.rate > 0 && <span className={style.muted}> ({commission.rate}%)</span>}{' '}
									<Tag color={commission.status === 'paid' ? 'success' : 'gold'}>
										{commission.status === 'paid' ? 'Đã chi' : 'Chờ chi'}
									</Tag>
								</div>
								{canCommission && (
									<Button small outline onClick={onToggleCommission}>
										{commission.status === 'paid' ? 'Chuyển về chờ chi' : 'Đánh dấu đã chi'}
									</Button>
								)}
							</div>
						) : <span className={style.muted}>Chưa có hoa hồng</span>}
					</section>

					{/* Lịch sử hoạt động */}
					<section className={style.section}>
						<h4>Lịch sử</h4>
						{(!deal.activities || deal.activities.length === 0) ? (
							<span className={style.muted}>Chưa có hoạt động</span>
						) : (
							<ul className={style.timeline}>
								{deal.activities.map((a) => (
									<li key={a.id}>
										<span className={style.dot} style={{background: ACTIVITY_DOT[a.type] || '#94a3b8'}} />
										<div>
											<div className={style.tlTitle}>
												{a.title}{a.amount > 0 ? ` — ${fmtMoney(a.amount)}` : ''}
											</div>
											<div className={style.muted}>{fmt(a.created)}{a.note ? ` · ${a.note}` : ''}</div>
										</div>
									</li>
								))}
							</ul>
						)}
					</section>
				</div>
			)}

			<DealPaymentFormModal
				open={openPayment}
				loading={addingPayment}
				methods={methods}
				onCancel={() => setOpenPayment(false)}
				onSubmit={onAddPayment}
			/>

			<DealReminderFormModal
				open={openReminder}
				loading={addingReminder}
				onCancel={() => setOpenReminder(false)}
				onSubmit={onAddReminder}
			/>
		</Drawer>
	);
}

export default DealDetailDrawer;
