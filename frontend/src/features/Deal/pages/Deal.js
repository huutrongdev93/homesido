import {useContext, useMemo, useState} from "react";
import {App as AntdApp, Table, Tag} from "antd";
import {AppContext} from "~/context/AppProvider";
import {useCan, useDebounce} from "~/hooks";
import {Button, PageHeader, FontAwesomeIcon} from "~/components";
import {SelectField} from "~/components/Forms";
import {rtkErrorMessage} from "~/reduxs/api/apiSlice";
import {
	useGetDealsQuery,
	useAddDealMutation,
	useUpdateDealMutation,
	useDeleteDealMutation,
} from "~/reduxs/api/dealApiSlice";
import DealFormModal from "../components/DealFormModal";
import DealDetailDrawer from "../components/DealDetailDrawer";
import {fmtMoney, DEAL_STATUS_TAG} from "../dealUtils";
import style from "../style/Deal.module.scss";

const PAGE_SIZE = 20;
const toMap = (arr = []) => arr.reduce((m, o) => ({...m, [o.value]: o.label}), {});

function Deal() {

	const {notification, modal} = AntdApp.useApp();
	const {appData} = useContext(AppContext);

	const statuses = useMemo(() => appData?.deal?.statuses || [], [appData]);
	const statusMap = useMemo(() => toMap(statuses), [statuses]);

	const canAdd    = useCan('deal_add');
	const canEdit   = useCan('deal_edit');
	const canDelete = useCan('deal_delete');

	const [keyword, setKeyword] = useState('');
	const [statusFilter, setStatusFilter] = useState('');
	const [page, setPage] = useState(1);
	const debouncedKeyword = useDebounce(keyword, 400);

	const params = useMemo(() => ({
		page, pageSize: PAGE_SIZE,
		...(debouncedKeyword ? {keyword: debouncedKeyword} : {}),
		...(statusFilter ? {status: statusFilter} : {}),
	}), [page, debouncedKeyword, statusFilter]);

	const {data = {items: [], total: 0}, isFetching} = useGetDealsQuery(params);

	const [addDeal, {isLoading: adding}] = useAddDealMutation();
	const [updateDeal, {isLoading: updating}] = useUpdateDealMutation();
	const [deleteDeal] = useDeleteDealMutation();

	const [openForm, setOpenForm] = useState(null); // {deal} — null = đóng
	const [detailId, setDetailId] = useState(null);

	const onSubmitForm = async (payload, deal) => {
		try {
			if (deal?.id) {
				await updateDeal({id: deal.id, ...payload}).unwrap();
				notification.success({message: 'Thành công', description: 'Đã cập nhật giao dịch'});
			} else {
				await addDeal(payload).unwrap();
				notification.success({message: 'Thành công', description: 'Đã tạo giao dịch'});
			}
			setOpenForm(null);
		} catch (e) {
			notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Lưu giao dịch thất bại')});
		}
	};

	const onDelete = (record) => {
		modal.confirm({
			title: 'Xóa giao dịch',
			content: `Xóa giao dịch ${record.code}?`,
			okText: 'Xóa', okButtonProps: {danger: true}, cancelText: 'Đóng',
			onOk: async () => {
				try {
					await deleteDeal(record.id).unwrap();
					notification.success({message: 'Thành công', description: 'Đã xóa giao dịch'});
				} catch (e) {
					notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Xóa thất bại')});
				}
			},
		});
	};

	const columns = [
		{
			title: 'Mã', dataIndex: 'code', key: 'code', width: 130,
			render: (v, r) => <button type="button" className={style.codeLink} onClick={() => setDetailId(r.id)}>{v}</button>,
		},
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
			render: (_, r) => <span>{r.property?.code} — {r.property?.title}</span>,
		},
		{
			title: 'Giá trị', dataIndex: 'value', key: 'value', width: 120, align: 'right',
			render: (v) => fmtMoney(v),
		},
		{
			title: 'Giai đoạn', dataIndex: 'status', key: 'status', width: 130,
			render: (v) => <Tag color={DEAL_STATUS_TAG[v] || 'default'}>{statusMap[v] || v}</Tag>,
		},
		{
			title: '', key: 'actions', width: 120, align: 'right',
			render: (_, r) => (
				<div className={style.rowActions}>
					{canEdit && (
						<Button small outline leftIcon={<FontAwesomeIcon icon="fa-light fa-pen" />} onClick={() => setOpenForm({deal: r})} />
					)}
					{canDelete && (
						<Button small outline leftIcon={<FontAwesomeIcon icon="fa-light fa-trash" />} onClick={() => onDelete(r)} />
					)}
				</div>
			),
		},
	];

	return (
		<div className="container">
			<PageHeader
				icon="fa-light fa-file-signature"
				title="Giao dịch"
				subtitle="Quản lý giao dịch cọc → hợp đồng → hoàn tất và hoa hồng"
				actions={canAdd && (
					<Button primary leftIcon={<FontAwesomeIcon icon="fa-light fa-plus" />} onClick={() => setOpenForm({deal: null})}>
						Tạo giao dịch
					</Button>
				)}
			/>

			<div className={`${style.toolbar} form`}>
				<input
					className="form-control"
					placeholder="Tìm theo mã giao dịch..."
					value={keyword}
					onChange={(e) => {setKeyword(e.target.value); setPage(1);}}
				/>
				<SelectField
					value={statusFilter || undefined}
					onChange={(v) => {setStatusFilter(v || ''); setPage(1);}}
					placeholder="Tất cả giai đoạn"
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
					locale={{emptyText: 'Chưa có giao dịch nào.'}}
					pagination={{
						current: page,
						pageSize: PAGE_SIZE,
						total: data.total,
						onChange: setPage,
						hideOnSinglePage: true,
						showTotal: (t) => `${t} giao dịch`,
					}}
				/>
			</div>

			<DealFormModal
				open={!!openForm}
				deal={openForm?.deal || null}
				loading={adding || updating}
				onCancel={() => setOpenForm(null)}
				onSubmit={onSubmitForm}
			/>

			<DealDetailDrawer
				open={!!detailId}
				dealId={detailId}
				onClose={() => setDetailId(null)}
			/>
		</div>
	);
}

export default Deal;
