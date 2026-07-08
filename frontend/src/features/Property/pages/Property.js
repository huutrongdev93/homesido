import {useContext, useMemo, useState} from "react";
import {App as AntdApp, Table, Tag} from "antd";
import {AppContext} from "~/context/AppProvider";
import {useCan, useDebounce} from "~/hooks";
import {Button, PageHeader, FontAwesomeIcon} from "~/components";
import {SelectField} from "~/components/Forms";
import {rtkErrorMessage} from "~/reduxs/api/apiSlice";
import {
	useGetPropertiesQuery,
	useAddPropertyMutation,
	useUpdatePropertyMutation,
	useDeletePropertyMutation,
} from "~/reduxs/api/propertyApiSlice";
import {useGetProjectsQuery, useGetPropertyOwnersQuery} from "~/reduxs/api/catalogApiSlice";
import PropertyFormModal from "../components/PropertyFormModal";
import PropertyMediaModal from "../components/PropertyMediaModal";
import PropertyDetailPanel from "../components/PropertyDetailPanel";
import style from "../style/Property.module.scss";

const STATUS_COLORS = {
	available: 'success', deposited: 'gold', sold: 'error', rented: 'blue', inactive: 'default',
};

const toMap = (arr = []) => arr.reduce((m, o) => ({...m, [o.value]: o.label}), {});

const fmtPrice = (v) => {
	const n = Number(v);
	if (!n) return '—';
	if (n >= 1e9) return `${(n / 1e9).toLocaleString('vi-VN', {maximumFractionDigits: 2})} tỷ`;
	if (n >= 1e6) return `${(n / 1e6).toLocaleString('vi-VN', {maximumFractionDigits: 0})} tr`;
	return n.toLocaleString('vi-VN');
};

function Property() {

	const {notification, modal} = AntdApp.useApp();
	const {appData} = useContext(AppContext);

	const enums = useMemo(() => (appData && appData.property) || {}, [appData]);
	const types = useMemo(() => enums.property_types || [], [enums]);
	const transactions = useMemo(() => enums.transaction_types || [], [enums]);
	const statuses = useMemo(() => enums.statuses || [], [enums]);
	const visibilities = useMemo(() => enums.visibilities || [], [enums]);
	const legals = useMemo(() => enums.legal_statuses || [], [enums]);
	const furnitures = useMemo(() => enums.furnitures || [], [enums]);
	const directions = useMemo(() => enums.directions || [], [enums]);
	const roadTypes = useMemo(() => enums.road_types || [], [enums]);

	const typeMap = useMemo(() => toMap(types), [types]);
	const statusMap = useMemo(() => toMap(statuses), [statuses]);
	const transMap = useMemo(() => toMap(transactions), [transactions]);

	const can = {
		add: useCan('property_add'),
		edit: useCan('property_edit'),
		delete: useCan('property_delete'),
	};

	const [keyword, setKeyword] = useState('');
	const [type, setType] = useState('');
	const [status, setStatus] = useState('');
	const [page, setPage] = useState(1);
	const [pageSize, setPageSize] = useState(20);
	const debouncedKeyword = useDebounce(keyword, 400);

	const params = useMemo(() => ({
		page, pageSize,
		...(debouncedKeyword ? {keyword: debouncedKeyword} : {}),
		...(type ? {property_type: type} : {}),
		...(status ? {status} : {}),
	}), [page, pageSize, debouncedKeyword, type, status]);

	const {data = {items: [], total: 0}, isFetching, refetch} = useGetPropertiesQuery(params);

	// Danh mục cho select trong form.
	const {data: projectRows = []} = useGetProjectsQuery();
	const {data: ownerRows = []} = useGetPropertyOwnersQuery();
	const projects = useMemo(() => projectRows.map((p) => ({value: p.id, label: p.name})), [projectRows]);
	const owners = useMemo(() => ownerRows.map((o) => ({value: o.id, label: o.phone ? `${o.full_name} — ${o.phone}` : o.full_name})), [ownerRows]);

	const [addProperty, {isLoading: adding}] = useAddPropertyMutation();
	const [updateProperty, {isLoading: updating}] = useUpdatePropertyMutation();
	const [deleteProperty] = useDeletePropertyMutation();

	const [openModal, setOpenModal] = useState({addEdit: false});
	const [itemEdit, setItemEdit] = useState(null);
	const [mediaItem, setMediaItem] = useState(null);   // BĐS đang mở modal media
	const [expandedKey, setExpandedKey] = useState(null);   // id hàng đang mở chi tiết (1 hàng)

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
					await updateProperty({id: item.id, ...formData}).unwrap();
					notification.success({message: 'Thành công', description: 'Đã cập nhật bất động sản'});
				} else {
					await addProperty(formData).unwrap();
					notification.success({message: 'Thành công', description: 'Đã thêm bất động sản'});
				}
				events.close();
			} catch (e) {
				notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Lưu bất động sản thất bại')});
			}
		},
		remove: (record) => {
			modal.confirm({
				title: 'Xóa bất động sản',
				content: `Bạn có chắc muốn xóa "${record.title}"?`,
				okText: 'Xóa',
				okButtonProps: {danger: true},
				cancelText: 'Hủy',
				onOk: async () => {
					try {
						await deleteProperty(record.id).unwrap();
						notification.success({message: 'Thành công', description: 'Đã xóa bất động sản'});
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
			title: '', key: 'thumbnail', width: 64,
			render: (_, record) => (
				<div className={style.thumbCell}>
					{record.thumbnail
						? <img src={record.thumbnail} alt="" />
						: <FontAwesomeIcon icon="fa-light fa-image" />}
				</div>
			),
		},
		{title: 'Mã', dataIndex: 'code', key: 'code', width: 120},
		{title: 'Tiêu đề', dataIndex: 'title', key: 'title'},
		{
			title: 'Loại', dataIndex: 'property_type', key: 'property_type', width: 110,
			render: (v) => typeMap[v] || v || '—',
		},
		{
			title: 'Hình thức', dataIndex: 'transaction_type', key: 'transaction_type', width: 100,
			render: (v) => transMap[v] || v,
		},
		{
			title: 'Giá', dataIndex: 'price', key: 'price', width: 110, align: 'right',
			render: (v) => fmtPrice(v),
		},
		{
			title: 'Trạng thái', dataIndex: 'status', key: 'status', width: 120,
			render: (v) => <Tag color={STATUS_COLORS[v] || 'default'}>{statusMap[v] || v}</Tag>,
		},
		{
			title: '', key: 'actions', width: 90, align: 'right',
			render: (_, record) => (
				<div className={style.rowActions} onClick={(e) => e.stopPropagation()}>
					<button type="button" className={style.iconBtn} onClick={() => setMediaItem(record)} title="Ảnh / video">
						<FontAwesomeIcon icon="fa-light fa-images" />
					</button>
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
				icon="fa-light fa-building"
				title="Bất động sản"
				subtitle="Kho sản phẩm để môi giới và matching"
				actions={can.add && (
					<Button primary leftIcon={<FontAwesomeIcon icon="fa-light fa-plus" />} onClick={events.openAdd}>
						Thêm BĐS
					</Button>
				)}
			/>

			<div className={style.toolbar}>
				<div className={`${style.search} form`}>
					<FontAwesomeIcon icon="fa-light fa-magnifying-glass" className={style.searchIcon} />
					<input
						className="form-control"
						placeholder="Tìm theo tiêu đề hoặc mã..."
						value={keyword}
						onChange={(e) => {
							setPage(1);
							setKeyword(e.target.value);
						}}
					/>
				</div>
				<div className={`${style.filter} form`}>
					<SelectField placeholder="Tất cả loại hình" allowClear options={types}
						value={type || undefined}
						onChange={(v) => {
							setPage(1);
							setType(v || '');
						}}
					/>
				</div>
				<div className={`${style.filter} form`}>
					<SelectField placeholder="Tất cả trạng thái" allowClear options={statuses}
						value={status || undefined}
						onChange={(v) => {
							setPage(1);
							setStatus(v || '');
						}}
					/>
				</div>
				<button type="button" className={style.reload} onClick={() => refetch()} title="Tải lại">
					<FontAwesomeIcon icon="fa-light fa-rotate-right" />
				</button>
			</div>

			<div className="app-card">
			<Table
				rowKey="id"
				columns={columns}
				dataSource={data.items}
				loading={isFetching}
				size="middle"
				scroll={{x: 800}}
				rowClassName={(record) => (record.id === expandedKey ? style.rowExpanded : '')}
				onRow={() => ({style: {cursor: 'pointer'}})}
				expandable={{
					expandedRowKeys: expandedKey ? [expandedKey] : [],
					expandRowByClick: true,
					showExpandColumn: false,
					onExpandedRowsChange: (keys) => {
						// Chỉ mở 1 hàng: lấy key khác key hiện tại (hàng vừa click), không có → đóng.
						const next = (keys || []).filter((k) => k !== expandedKey);
						setExpandedKey(next.length ? next[next.length - 1] : null);
					},
					expandedRowRender: (record) => (
						<PropertyDetailPanel
							property={record}
							canEdit={can.edit}
							onEdit={events.openEdit}
							onMedia={(r) => setMediaItem(r)}
						/>
					),
				}}
				pagination={{
					current: page,
					pageSize,
					total: data.total,
					showSizeChanger: true,
					showTotal: (total) => `${total} sản phẩm`,
					onChange: (p, ps) => {
						setPage(p);
						setPageSize(ps);
					},
				}}
			/>
			</div>

			<PropertyFormModal
				open={openModal.addEdit}
				item={itemEdit}
				loading={adding || updating}
				options={{types, transactions, statuses, visibilities, legals, furnitures, directions, roadTypes, projects, owners}}
				onCancel={events.close}
				onSubmit={events.save}
			/>

			<PropertyMediaModal
				open={!!mediaItem}
				property={mediaItem}
				canEdit={can.edit}
				onClose={() => setMediaItem(null)}
			/>
		</div>
	);
}

export default Property;
