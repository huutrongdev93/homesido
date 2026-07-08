import {Tabs, Tag} from "antd";
import {PageHeader} from "~/components";
import {useCan} from "~/hooks";
import {
	useGetLeadSourcesQuery, useAddLeadSourceMutation, useUpdateLeadSourceMutation, useDeleteLeadSourceMutation,
	useGetProjectsQuery, useAddProjectMutation, useUpdateProjectMutation, useDeleteProjectMutation,
	useGetPropertyOwnersQuery, useAddPropertyOwnerMutation, useUpdatePropertyOwnerMutation, useDeletePropertyOwnerMutation,
	useGetCareTemplatesQuery, useAddCareTemplateMutation, useUpdateCareTemplateMutation, useDeleteCareTemplateMutation,
} from "~/reduxs/api/catalogApiSlice";
import CatalogManager from "../components/CatalogManager";

const CHANNELS = [
	{value: 'call', label: 'Gọi điện'},
	{value: 'sms', label: 'SMS'},
	{value: 'zalo', label: 'Zalo'},
	{value: 'email', label: 'Email'},
];
const channelMap = CHANNELS.reduce((m, o) => ({...m, [o.value]: o.label}), {});

const activeTag = (v) => (v ? <Tag color="success">Đang dùng</Tag> : <Tag>Tắt</Tag>);

/**
 * Cấu hình danh mục phụ (/admin/catalog, cap `permission`): nguồn khách, dự án, chủ nhà,
 * kịch bản chăm sóc. Mỗi tab là 1 CatalogManager (bảng + modal động). Các danh mục này nạp
 * vào dropdown ở form Khách hàng / Bất động sản / Chăm sóc.
 */
function Catalog() {

	const canManage = useCan('permission');

	const tabs = [
		{
			key: 'lead-source',
			label: 'Nguồn khách',
			manager: {
				title: 'Nguồn khách', icon: 'fa-light fa-filter', entityLabel: 'nguồn khách', nameKey: 'name',
				columns: [
					{title: 'Tên nguồn', dataIndex: 'name', key: 'name'},
					{title: 'Trạng thái', dataIndex: 'is_active', key: 'is_active', width: 130, render: activeTag},
				],
				fields: [
					{name: 'name', label: 'Tên nguồn', type: 'text', required: true, placeholder: 'VD: Facebook, Giới thiệu'},
					{name: 'is_active', label: 'Trạng thái', type: 'switch', default: true},
				],
				hooks: {useList: useGetLeadSourcesQuery, useAdd: useAddLeadSourceMutation, useUpdate: useUpdateLeadSourceMutation, useDelete: useDeleteLeadSourceMutation},
			},
		},
		{
			key: 'project',
			label: 'Dự án',
			manager: {
				title: 'Dự án', icon: 'fa-light fa-city', entityLabel: 'dự án', nameKey: 'name',
				columns: [
					{title: 'Tên dự án', dataIndex: 'name', key: 'name'},
					{title: 'Chủ đầu tư', dataIndex: 'developer', key: 'developer'},
					{title: 'Địa chỉ', dataIndex: 'address', key: 'address'},
				],
				fields: [
					{name: 'name', label: 'Tên dự án', type: 'text', required: true},
					{name: 'developer', label: 'Chủ đầu tư', type: 'text'},
					{name: 'address', label: 'Địa chỉ', type: 'text'},
					{name: 'description', label: 'Mô tả', type: 'textarea'},
				],
				hooks: {useList: useGetProjectsQuery, useAdd: useAddProjectMutation, useUpdate: useUpdateProjectMutation, useDelete: useDeleteProjectMutation},
			},
		},
		{
			key: 'property-owner',
			label: 'Chủ nhà',
			manager: {
				title: 'Chủ nhà', icon: 'fa-light fa-user-tie', entityLabel: 'chủ nhà', nameKey: 'full_name',
				columns: [
					{title: 'Họ tên', dataIndex: 'full_name', key: 'full_name'},
					{title: 'Điện thoại', dataIndex: 'phone', key: 'phone', width: 140},
					{title: 'Email', dataIndex: 'email', key: 'email'},
				],
				fields: [
					{name: 'full_name', label: 'Họ tên', type: 'text', required: true},
					{name: 'phone', label: 'Điện thoại', type: 'text'},
					{name: 'email', label: 'Email', type: 'text'},
					{name: 'note', label: 'Ghi chú', type: 'textarea'},
				],
				hooks: {useList: useGetPropertyOwnersQuery, useAdd: useAddPropertyOwnerMutation, useUpdate: useUpdatePropertyOwnerMutation, useDelete: useDeletePropertyOwnerMutation},
			},
		},
		{
			key: 'care-template',
			label: 'Kịch bản chăm sóc',
			manager: {
				title: 'Kịch bản chăm sóc', icon: 'fa-light fa-comment-dots', entityLabel: 'kịch bản', nameKey: 'name',
				columns: [
					{title: 'Tên kịch bản', dataIndex: 'name', key: 'name'},
					{title: 'Kênh', dataIndex: 'channel', key: 'channel', width: 110, render: (v) => channelMap[v] || v},
					{title: 'Giai đoạn', dataIndex: 'stage', key: 'stage', width: 130},
					{title: 'Trạng thái', dataIndex: 'is_active', key: 'is_active', width: 120, render: activeTag},
				],
				fields: [
					{name: 'name', label: 'Tên kịch bản', type: 'text', required: true},
					{name: 'channel', label: 'Kênh', type: 'select', options: CHANNELS},
					{name: 'stage', label: 'Giai đoạn áp dụng', type: 'text', placeholder: 'VD: Lead mới, Chăm sau xem nhà'},
					{name: 'content', label: 'Nội dung (dùng {{ten_khach}})', type: 'textarea', rows: 4},
					{name: 'is_active', label: 'Trạng thái', type: 'switch', default: true},
				],
				hooks: {useList: useGetCareTemplatesQuery, useAdd: useAddCareTemplateMutation, useUpdate: useUpdateCareTemplateMutation, useDelete: useDeleteCareTemplateMutation},
			},
		},
	];

	return (
		<div className="container">
			<PageHeader
				icon="fa-light fa-sliders"
				title="Cấu hình danh mục"
				subtitle="Nguồn khách, dự án, chủ nhà, kịch bản chăm sóc — dùng cho các form nghiệp vụ"
			/>
			<Tabs items={tabs.map((t) => ({
				key: t.key,
				label: t.label,
				children: <CatalogManager {...t.manager} canManage={canManage} />,
			}))} />
		</div>
	);
}

export default Catalog;
