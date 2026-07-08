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
import CareFormModal from "~/features/Care/components/CareFormModal";
import CareCompleteModal from "~/features/Care/components/CareCompleteModal";
import InteractionFormModal from "./InteractionFormModal";
import style from "../style/CustomerDetail.module.scss";

const CARE_STATUS = {
	pending: {color: 'processing', label: 'Chờ chăm'},
	done: {color: 'success', label: 'Đã xong'},
	missed: {color: 'error', label: 'Lỡ hẹn'},
	canceled: {color: 'default', label: 'Đã hủy'},
};

const toMap = (arr = []) => arr.reduce((m, o) => ({...m, [o.value]: o.label}), {});
const fmt = (v) => (v ? dayjs(v).format('DD/MM/YYYY HH:mm') : '');

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

	const canEdit = useCan('customer_edit');

	const id = customer?.id;
	const skip = !open || !id;

	const {data: cares = []} = useGetCustomerCaresQuery(id, {skip});
	const {data: interactions = []} = useGetCustomerInteractionsQuery(id, {skip});

	const [addCare, {isLoading: addingCare}] = useAddCareMutation();
	const [completeCare, {isLoading: completing}] = useCompleteCareMutation();
	const [cancelCare] = useCancelCareMutation();
	const [addInteraction, {isLoading: addingInt}] = useAddInteractionMutation();

	const [openCare, setOpenCare] = useState(false);
	const [openComplete, setOpenComplete] = useState(null);   // care đang hoàn thành
	const [openInt, setOpenInt] = useState(false);

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
						</div>
					</div>

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
				onCancel={() => setOpenCare(false)} onSubmit={events.saveCare} />
			<CareCompleteModal open={!!openComplete} care={openComplete} loading={completing} careTypes={careTypes}
				onCancel={() => setOpenComplete(null)} onSubmit={events.complete} />
			<InteractionFormModal open={openInt} loading={addingInt} interactionTypes={interactionTypes}
				onCancel={() => setOpenInt(false)} onSubmit={events.saveInt} />
		</Drawer>
	);
}

export default CustomerDetailDrawer;
