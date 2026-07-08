import {useContext, useMemo, useState} from "react";
import {App as AntdApp, Table, Tag} from "antd";
import dayjs from "dayjs";
import {AppContext} from "~/context/AppProvider";
import {useCan} from "~/hooks";
import {Button, PageHeader, FontAwesomeIcon} from "~/components";
import {SelectField} from "~/components/Forms";
import {rtkErrorMessage} from "~/reduxs/api/apiSlice";
import {
	useGetAppointmentsQuery,
	useAddAppointmentMutation,
	useUpdateAppointmentMutation,
	useCompleteAppointmentMutation,
	useCancelAppointmentMutation,
} from "~/reduxs/api/appointmentApiSlice";
import AppointmentFormModal from "../components/AppointmentFormModal";
import AppointmentCompleteModal from "../components/AppointmentCompleteModal";
import style from "../style/Appointment.module.scss";

const PAGE_SIZE = 20;

const STATUS_TAG = {
	pending: 'processing', done: 'success', canceled: 'default', no_show: 'error',
};

const toMap = (arr = []) => arr.reduce((m, o) => ({...m, [o.value]: o.label}), {});
const fmt = (v) => (v ? dayjs(v).format('DD/MM/YYYY HH:mm') : '—');

function Appointment() {

	const {notification, modal} = AntdApp.useApp();
	const {appData} = useContext(AppContext);

	const statuses = useMemo(() => appData?.appointment?.statuses || [], [appData]);
	const results  = useMemo(() => appData?.appointment?.results || [], [appData]);
	const statusMap = useMemo(() => toMap(statuses), [statuses]);
	const resultMap = useMemo(() => toMap(results), [results]);

	const canAdd    = useCan('appointment_add');
	const canEdit   = useCan('appointment_edit');
	const canDelete = useCan('appointment_delete');

	const [page, setPage] = useState(1);
	const [statusFilter, setStatusFilter] = useState('');

	const queryArgs = useMemo(() => ({
		page, pageSize: PAGE_SIZE, ...(statusFilter ? {status: statusFilter} : {}),
	}), [page, statusFilter]);

	const {data = {items: [], total: 0}, isFetching} = useGetAppointmentsQuery(queryArgs);

	const [addAppointment, {isLoading: adding}] = useAddAppointmentMutation();
	const [updateAppointment, {isLoading: updating}] = useUpdateAppointmentMutation();
	const [completeAppointment, {isLoading: completing}] = useCompleteAppointmentMutation();
	const [cancelAppointment] = useCancelAppointmentMutation();

	const [openForm, setOpenForm] = useState(null);        // {appointment} — null = đóng
	const [openComplete, setOpenComplete] = useState(null); // row đang chốt

	const onSubmitForm = async (payload) => {
		try {
			if (openForm?.appointment) {
				await updateAppointment({id: openForm.appointment.id, ...payload}).unwrap();
				notification.success({message: 'Thành công', description: 'Đã cập nhật lịch hẹn'});
			} else {
				await addAppointment(payload).unwrap();
				notification.success({message: 'Thành công', description: 'Đã tạo lịch hẹn'});
			}
			setOpenForm(null);
		} catch (e) {
			notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Lưu lịch hẹn thất bại')});
		}
	};

	const onComplete = async (payload) => {
		try {
			await completeAppointment({id: openComplete.id, ...payload}).unwrap();
			setOpenComplete(null);
			notification.success({message: 'Thành công', description: 'Đã lưu kết quả buổi hẹn'});
		} catch (e) {
			notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Cập nhật thất bại')});
		}
	};

	const onCancel = (row) => {
		modal.confirm({
			title: 'Hủy lịch hẹn',
			content: `Hủy buổi hẹn với ${row.customer?.full_name || 'khách'}?`,
			okText: 'Hủy lịch', okType: 'danger', cancelText: 'Đóng',
			onOk: async () => {
				try {
					await cancelAppointment(row.id).unwrap();
					notification.success({message: 'Thành công', description: 'Đã hủy lịch hẹn'});
				} catch (e) {
					notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Hủy thất bại')});
				}
			},
		});
	};

	const columns = [
		{
			title: 'Khách hàng', key: 'customer',
			render: (_, r) => (
				<div>
					<div style={{fontWeight: 600}}>{r.customer?.full_name}</div>
					<div style={{color: 'var(--text-muted)', fontSize: 13}}>{r.customer?.phone}</div>
				</div>
			),
		},
		{
			title: 'Bất động sản', key: 'property',
			render: (_, r) => (r.property
				? <span>{r.property.code ? <b>{r.property.code}</b> : null} {r.property.title}</span>
				: <span style={{color: 'var(--text-muted)'}}>—</span>),
		},
		{
			title: 'Hẹn lúc', key: 'scheduled_at', width: 170,
			render: (_, r) => (
				<span>
					{fmt(r.scheduled_at)}{' '}
					{r.overdue && <Tag color="error">Quá hạn</Tag>}
				</span>
			),
		},
		{
			title: 'Trạng thái', key: 'status', width: 150,
			render: (_, r) => (
				<div>
					<Tag color={STATUS_TAG[r.status] || 'default'}>{statusMap[r.status] || r.status}</Tag>
					{r.status === 'done' && r.result && (
						<div style={{marginTop: 4}}><Tag color="blue">{resultMap[r.result] || r.result}</Tag></div>
					)}
				</div>
			),
		},
		{
			title: '', key: 'actions', width: 210, align: 'right',
			render: (_, r) => (
				<div className={style.rowActions}>
					{r.status === 'pending' && canEdit && (
						<Button small primary leftIcon={<FontAwesomeIcon icon="fa-light fa-check" />} onClick={() => setOpenComplete(r)}>
							Chốt
						</Button>
					)}
					{r.status === 'pending' && canEdit && (
						<Button small outline leftIcon={<FontAwesomeIcon icon="fa-light fa-pen" />} onClick={() => setOpenForm({appointment: r})}>
							Sửa
						</Button>
					)}
					{r.status === 'pending' && canDelete && (
						<Button small outline leftIcon={<FontAwesomeIcon icon="fa-light fa-xmark" />} onClick={() => onCancel(r)}>
							Hủy
						</Button>
					)}
				</div>
			),
		},
	];

	return (
		<div className="container">
			<PageHeader
				icon="fa-light fa-calendar-clock"
				title="Lịch hẹn dẫn khách"
				subtitle="Đặt lịch dẫn khách xem bất động sản và ghi kết quả"
				actions={canAdd && (
					<Button primary leftIcon={<FontAwesomeIcon icon="fa-light fa-plus" />} onClick={() => setOpenForm({appointment: null})}>
						Tạo lịch hẹn
					</Button>
				)}
			/>

			<div className={`${style.filterBar} form`}>
				<SelectField
					value={statusFilter || undefined}
					onChange={(v) => {setStatusFilter(v || ''); setPage(1);}}
					placeholder="Tất cả trạng thái"
					allowClear
					style={{width: 200}}
					options={statuses}
				/>
			</div>

			<div className="app-card">
				<Table
					rowKey="id"
					columns={columns}
					dataSource={data.items}
					loading={isFetching}
					size="middle"
					locale={{emptyText: 'Chưa có lịch hẹn nào.'}}
					pagination={{
						current: page,
						pageSize: PAGE_SIZE,
						total: data.total,
						onChange: setPage,
						hideOnSinglePage: true,
						showTotal: (t) => `${t} lịch hẹn`,
					}}
				/>
			</div>

			<AppointmentFormModal
				open={!!openForm}
				appointment={openForm?.appointment || null}
				loading={adding || updating}
				onCancel={() => setOpenForm(null)}
				onSubmit={onSubmitForm}
			/>

			<AppointmentCompleteModal
				open={!!openComplete}
				appointment={openComplete}
				loading={completing}
				results={results}
				onCancel={() => setOpenComplete(null)}
				onSubmit={onComplete}
			/>
		</div>
	);
}

export default Appointment;
