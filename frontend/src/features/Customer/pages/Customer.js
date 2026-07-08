import {useContext, useMemo, useState} from "react";
import {App as AntdApp, Table, Tag} from "antd";
import {AppContext} from "~/context/AppProvider";
import {useCan, useDebounce} from "~/hooks";
import {Button, PageHeader, FontAwesomeIcon} from "~/components";
import {SelectField} from "~/components/Forms";
import {rtkErrorMessage} from "~/reduxs/api/apiSlice";
import {
	useGetCustomersQuery,
	useAddCustomerMutation,
	useUpdateCustomerMutation,
	useDeleteCustomerMutation,
} from "~/reduxs/api/customerApiSlice";
import CustomerFormModal from "../components/CustomerFormModal";
import CustomerDetailDrawer from "../components/CustomerDetailDrawer";
import style from "../style/Customer.module.scss";

const STAGE_COLORS = {
	new: 'default', contacting: 'processing', potential: 'gold',
	negotiating: 'orange', won: 'success', lost: 'error',
};
const TEMP_COLORS = {hot: 'red', warm: 'gold', cold: 'blue'};

const toMap = (arr = []) => arr.reduce((m, o) => ({...m, [o.value]: o.label}), {});

function Customer() {

	const {notification, modal} = AntdApp.useApp();
	const {appData} = useContext(AppContext);

	const enums = useMemo(() => (appData && appData.customer) || {}, [appData]);
	const stages = useMemo(() => enums.pipeline_stages || [], [enums]);
	const temperatures = useMemo(() => enums.temperatures || [], [enums]);
	const genders = useMemo(() => enums.genders || [], [enums]);

	const stageMap = useMemo(() => toMap(stages), [stages]);
	const tempMap = useMemo(() => toMap(temperatures), [temperatures]);

	const can = {
		add: useCan('customer_add'),
		edit: useCan('customer_edit'),
		delete: useCan('customer_delete'),
	};

	// ── Filter + phân trang (state cục bộ; server-side qua RTK Query) ──
	const [keyword, setKeyword] = useState('');
	const [stage, setStage] = useState('');
	const [page, setPage] = useState(1);
	const [pageSize, setPageSize] = useState(20);
	const debouncedKeyword = useDebounce(keyword, 400);

	const params = useMemo(() => ({
		page, pageSize,
		...(debouncedKeyword ? {keyword: debouncedKeyword} : {}),
		...(stage ? {pipeline_stage: stage} : {}),
	}), [page, pageSize, debouncedKeyword, stage]);

	const {data = {items: [], total: 0}, isFetching, refetch} = useGetCustomersQuery(params);

	const [addCustomer, {isLoading: adding}] = useAddCustomerMutation();
	const [updateCustomer, {isLoading: updating}] = useUpdateCustomerMutation();
	const [deleteCustomer] = useDeleteCustomerMutation();

	// ── Modal ──
	const [openModal, setOpenModal] = useState({addEdit: false});
	const [itemEdit, setItemEdit] = useState(null);
	const [detail, setDetail] = useState(null);   // khách đang mở drawer chi tiết

	const events = {
		openAdd: () => {
			setItemEdit(null);
			setOpenModal({addEdit: true});
		},
		openEdit: (record) => {
			setItemEdit(record);
			setOpenModal({addEdit: true});
		},
		close: () => setOpenModal({addEdit: false}),
		save: async (formData, item) => {
			try {
				if (item?.id) {
					await updateCustomer({id: item.id, ...formData}).unwrap();
					notification.success({message: 'Thành công', description: 'Đã cập nhật khách hàng'});
				} else {
					await addCustomer(formData).unwrap();
					notification.success({message: 'Thành công', description: 'Đã thêm khách hàng'});
				}
				events.close();
			} catch (e) {
				notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Lưu khách hàng thất bại')});
			}
		},
		remove: (record) => {
			modal.confirm({
				title: 'Xóa khách hàng',
				content: `Bạn có chắc muốn xóa khách hàng "${record.full_name}"?`,
				okText: 'Xóa',
				okButtonProps: {danger: true},
				cancelText: 'Hủy',
				onOk: async () => {
					try {
						await deleteCustomer(record.id).unwrap();
						notification.success({message: 'Thành công', description: 'Đã xóa khách hàng'});
					} catch (e) {
						notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Xóa thất bại')});
						return Promise.reject();
					}
				},
			});
		},
	};

	const columns = [
		{
			title: 'Họ tên', dataIndex: 'full_name', key: 'full_name',
			render: (v, record) => (
				<button type="button" className={style.nameLink} onClick={() => setDetail(record)}>{v}</button>
			),
		},
		{title: 'Điện thoại', dataIndex: 'phone', key: 'phone', width: 130},
		{
			title: 'Giai đoạn', dataIndex: 'pipeline_stage', key: 'pipeline_stage', width: 130,
			render: (v) => <Tag color={STAGE_COLORS[v] || 'default'}>{stageMap[v] || v}</Tag>,
		},
		{
			title: 'Quan tâm', dataIndex: 'temperature', key: 'temperature', width: 100,
			render: (v) => <Tag color={TEMP_COLORS[v] || 'default'}>{tempMap[v] || v}</Tag>,
		},
		{
			title: '', key: 'actions', width: 90, align: 'right',
			render: (_, record) => (
				<div className={style.rowActions}>
					{can.edit && (
						<button type="button" className={style.iconBtn} onClick={() => events.openEdit(record)} title="Sửa">
							<FontAwesomeIcon icon="fa-light fa-pen-to-square" />
						</button>
					)}
					{can.delete && (
						<button type="button" className={`${style.iconBtn} ${style.danger}`} onClick={() => events.remove(record)} title="Xóa">
							<FontAwesomeIcon icon="fa-light fa-trash" />
						</button>
					)}
				</div>
			),
		},
	];

	return (
		<div className="container">
			<PageHeader
				icon="fa-light fa-users"
				title="Khách hàng"
				subtitle="Quản lý danh bạ và tiến trình chăm sóc khách hàng"
				actions={can.add && (
					<Button primary leftIcon={<FontAwesomeIcon icon="fa-light fa-plus" />} onClick={events.openAdd}>
						Thêm khách
					</Button>
				)}
			/>

			<div className={style.toolbar}>
				<div className={`${style.search} form`}>
					<FontAwesomeIcon icon="fa-light fa-magnifying-glass" className={style.searchIcon} />
					<input
						className="form-control"
						placeholder="Tìm theo tên hoặc số điện thoại..."
						value={keyword}
						onChange={(e) => {
							setPage(1);
							setKeyword(e.target.value);
						}}
					/>
				</div>
				<div className={`${style.filter} form`}>
					<SelectField
						placeholder="Tất cả giai đoạn"
						allowClear
						options={stages}
						value={stage || undefined}
						onChange={(v) => {
							setPage(1);
							setStage(v || '');
						}}
					/>
				</div>
				<button type="button" className={style.reload} onClick={() => refetch()} title="Tải lại">
					<FontAwesomeIcon icon="fa-light fa-rotate-right" />
				</button>
			</div>

			<Table
				rowKey="id"
				columns={columns}
				dataSource={data.items}
				loading={isFetching}
				size="middle"
				pagination={{
					current: page,
					pageSize,
					total: data.total,
					showSizeChanger: true,
					showTotal: (total) => `${total} khách hàng`,
					onChange: (p, ps) => {
						setPage(p);
						setPageSize(ps);
					},
				}}
			/>

			<CustomerFormModal
				open={openModal.addEdit}
				item={itemEdit}
				loading={adding || updating}
				options={{genders, stages, temperatures}}
				onCancel={events.close}
				onSubmit={events.save}
			/>

			<CustomerDetailDrawer
				open={!!detail}
				customer={detail}
				stageMap={stageMap}
				tempMap={tempMap}
				onClose={() => setDetail(null)}
			/>
		</div>
	);
}

export default Customer;
