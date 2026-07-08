import {useContext, useMemo, useState} from "react";
import {App as AntdApp, Table, Tag} from "antd";
import dayjs from "dayjs";
import {AppContext} from "~/context/AppProvider";
import {Button, PageHeader, FontAwesomeIcon} from "~/components";
import {rtkErrorMessage} from "~/reduxs/api/apiSlice";
import {useGetCareTodayQuery, useCompleteCareMutation} from "~/reduxs/api/careApiSlice";
import CareCompleteModal from "../components/CareCompleteModal";

const toMap = (arr = []) => arr.reduce((m, o) => ({...m, [o.value]: o.label}), {});
const fmt = (v) => (v ? dayjs(v).format('DD/MM HH:mm') : '');

function CareToday() {

	const {notification} = AntdApp.useApp();
	const {appData} = useContext(AppContext);

	const careTypes = useMemo(() => appData?.care?.care_types || [], [appData]);
	const careTypeMap = useMemo(() => toMap(careTypes), [careTypes]);

	const {data: items = [], isFetching, refetch} = useGetCareTodayQuery();
	const [completeCare, {isLoading: completing}] = useCompleteCareMutation();

	const [openComplete, setOpenComplete] = useState(null);

	const onComplete = async (data) => {
		try {
			await completeCare({id: openComplete.id, ...data}).unwrap();
			setOpenComplete(null);
			notification.success({message: 'Thành công', description: 'Đã hoàn thành chăm sóc'});
		} catch (e) {
			notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Cập nhật thất bại')});
		}
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
		{title: 'Hình thức', dataIndex: 'type', key: 'type', width: 110, render: (v) => careTypeMap[v] || v},
		{title: 'Nội dung', dataIndex: 'content', key: 'content', render: (v) => v || '—'},
		{
			title: 'Hẹn lúc', key: 'scheduled_at', width: 130,
			render: (_, r) => (
				<span>
					{fmt(r.scheduled_at)}{' '}
					{r.overdue && <Tag color="error">Quá hạn</Tag>}
				</span>
			),
		},
		{
			title: '', key: 'actions', width: 130, align: 'right',
			render: (_, r) => (
				<Button small primary leftIcon={<FontAwesomeIcon icon="fa-light fa-check" />} onClick={() => setOpenComplete(r)}>
					Hoàn thành
				</Button>
			),
		},
	];

	return (
		<div className="container">
			<PageHeader
				icon="fa-light fa-calendar-heart"
				title="Cần chăm hôm nay"
				subtitle="Việc chăm sóc đến hạn và quá hạn của bạn"
				actions={
					<Button outline leftIcon={<FontAwesomeIcon icon="fa-light fa-rotate-right" />} onClick={() => refetch()}>
						Tải lại
					</Button>
				}
			/>

			<Table
				rowKey="id"
				columns={columns}
				dataSource={items}
				loading={isFetching}
				size="middle"
				pagination={false}
				locale={{emptyText: 'Tuyệt vời! Không có việc chăm sóc nào đến hạn.'}}
			/>

			<CareCompleteModal
				open={!!openComplete}
				care={openComplete}
				loading={completing}
				careTypes={careTypes}
				onCancel={() => setOpenComplete(null)}
				onSubmit={onComplete}
			/>
		</div>
	);
}

export default CareToday;
