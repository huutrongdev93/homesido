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
	useDeleteDealPaymentMutation,
	useUpdateDealCommissionMutation,
} from "~/reduxs/api/dealApiSlice";
import DealPaymentFormModal from "./DealPaymentFormModal";
import {fmtMoney, DEAL_STATUS_TAG} from "../dealUtils";
import style from "../style/Deal.module.scss";

const toMap = (arr = []) => arr.reduce((m, o) => ({...m, [o.value]: o.label}), {});
const fmt = (v) => (v ? dayjs(v).format('DD/MM/YYYY HH:mm') : '—');

/**
 * Drawer chi tiết giao dịch: header (mã/giai đoạn/giá trị/hoa hồng) + chuyển giai đoạn +
 * các đợt thanh toán (thêm/xóa) + hoa hồng (đánh dấu chi). Props: open, dealId, onClose.
 */
function DealDetailDrawer({open, dealId, onClose}) {

	const {notification, modal} = AntdApp.useApp();
	const {appData} = useContext(AppContext);

	const statuses = useMemo(() => appData?.deal?.statuses || [], [appData]);
	const methods  = useMemo(() => appData?.deal?.payment_methods || [], [appData]);
	const statusMap = useMemo(() => toMap(statuses), [statuses]);
	const methodMap = useMemo(() => toMap(methods), [methods]);

	const canEdit = useCan('deal_edit');
	const canCommission = useCan('commission_manage');

	const skip = !open || !dealId;
	const {data: deal, isFetching} = useGetDealQuery(dealId, {skip});

	const [changeStatus] = useChangeDealStatusMutation();
	const [addPayment, {isLoading: addingPayment}] = useAddDealPaymentMutation();
	const [deletePayment] = useDeleteDealPaymentMutation();
	const [updateCommission] = useUpdateDealCommissionMutation();

	const [openPayment, setOpenPayment] = useState(false);

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
						</div>
						{(!deal.payments || deal.payments.length === 0) ? (
							<Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Chưa có đợt thu nào" />
						) : (
							<ul className={style.list}>
								{deal.payments.map((p) => (
									<li key={p.id}>
										<div>
											<b>{fmtMoney(p.amount)}</b>
											{p.method && <Tag style={{marginLeft: 8}}>{methodMap[p.method] || p.method}</Tag>}
											<div className={style.muted}>{fmt(p.paid_at)}{p.note ? ` · ${p.note}` : ''}</div>
										</div>
										{canEdit && (
											<button type="button" className={style.linkDanger} onClick={() => onDeletePayment(p)}>Xóa</button>
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
				</div>
			)}

			<DealPaymentFormModal
				open={openPayment}
				loading={addingPayment}
				methods={methods}
				onCancel={() => setOpenPayment(false)}
				onSubmit={onAddPayment}
			/>
		</Drawer>
	);
}

export default DealDetailDrawer;
