import {useEffect, useMemo, useState} from "react";
import {App as AntdApp, Table} from "antd";
import {Controller, useForm} from "react-hook-form";
import {yupResolver} from "@hookform/resolvers/yup";
import * as Yup from "yup";
import {Button, ModalForm, FontAwesomeIcon} from "~/components";
import {InputField, TextAreaField, SelectField, CheckBoxField} from "~/components/Forms";
import {rtkErrorMessage} from "~/reduxs/api/apiSlice";
import style from "../style/Catalog.module.scss";

/** Giá trị mặc định theo kiểu field (form thêm mới). */
const emptyFor = (fields) => fields.reduce((acc, f) => {
	acc[f.name] = f.type === 'switch' ? (f.default ?? true) : (f.type === 'select' ? undefined : '');
	return acc;
}, {});

/** Nạp giá trị 1 bản ghi vào form (sửa). */
const fillFrom = (fields, item) => fields.reduce((acc, f) => {
	acc[f.name] = f.type === 'switch' ? Boolean(item[f.name]) : (item[f.name] ?? '');
	return acc;
}, {});

/** yup schema từ field required (chuỗi bắt buộc). */
const schemaFor = (fields) => Yup.object().shape(fields.reduce((acc, f) => {
	if (f.required) acc[f.name] = Yup.string().required(`Vui lòng nhập ${f.label.toLowerCase()}`);
	return acc;
}, {}));

/**
 * Quản lý 1 danh mục phụ (bảng + modal thêm/sửa động theo `fields`) — dùng chung cho mọi tab
 * Cấu hình. `columns` là cột hiển thị (không gồm cột thao tác — tự thêm nếu canManage).
 * `hooks`: {useList, useAdd, useUpdate, useDelete} của danh mục tương ứng.
 */
function CatalogManager({title, icon, entityLabel, nameKey = 'name', columns, fields, canManage, hooks}) {

	const {notification, modal} = AntdApp.useApp();

	const {data: rows = [], isFetching} = hooks.useList();
	const [addItem, {isLoading: adding}] = hooks.useAdd();
	const [updateItem, {isLoading: updating}] = hooks.useUpdate();
	const [deleteItem] = hooks.useDelete();

	const [open, setOpen] = useState(false);
	const [itemEdit, setItemEdit] = useState(null);

	const empty = useMemo(() => emptyFor(fields), [fields]);
	const {control, handleSubmit, reset, formState: {errors}} = useForm({
		defaultValues: empty,
		resolver: yupResolver(schemaFor(fields)),
	});

	useEffect(() => {
		if (!open) return;
		reset(itemEdit ? fillFrom(fields, itemEdit) : empty);
	}, [open, itemEdit, fields, empty, reset]);

	const events = {
		openAdd: () => { setItemEdit(null); setOpen(true); },
		openEdit: (record) => { setItemEdit(record); setOpen(true); },
		submit: handleSubmit(async (data) => {
			try {
				if (itemEdit?.id) {
					await updateItem({id: itemEdit.id, ...data}).unwrap();
					notification.success({message: 'Thành công', description: `Đã cập nhật ${entityLabel}`});
				} else {
					await addItem(data).unwrap();
					notification.success({message: 'Thành công', description: `Đã thêm ${entityLabel}`});
				}
				setOpen(false);
			} catch (e) {
				notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Lưu thất bại')});
			}
		}),
		remove: (record) => {
			modal.confirm({
				title: `Xóa ${entityLabel}`,
				content: `Bạn có chắc muốn xóa "${record[nameKey]}"?`,
				okText: 'Xóa', okButtonProps: {danger: true}, cancelText: 'Hủy',
				onOk: async () => {
					try {
						await deleteItem(record.id).unwrap();
						notification.success({message: 'Thành công', description: `Đã xóa ${entityLabel}`});
					} catch (e) {
						notification.error({message: 'Lỗi', description: rtkErrorMessage(e, 'Xóa thất bại')});
						return Promise.reject();
					}
				},
			});
		},
	};

	const tableColumns = canManage ? [...columns, {
		title: '', key: 'actions', width: 90, align: 'right',
		render: (_, record) => (
			<div className={style.rowActions}>
				<button type="button" className={style.iconBtn} onClick={() => events.openEdit(record)} title="Sửa">
					<FontAwesomeIcon icon="fa-light fa-pen-to-square" />
				</button>
				<button type="button" className={`${style.iconBtn} ${style.danger}`} onClick={() => events.remove(record)} title="Xóa">
					<FontAwesomeIcon icon="fa-light fa-trash" />
				</button>
			</div>
		),
	}] : columns;

	return (
		<div className="app-card">
			<div className={style.tabBar}>
				<h3><FontAwesomeIcon icon={icon} /> {title}</h3>
				{canManage && (
					<Button small primary leftIcon={<FontAwesomeIcon icon="fa-light fa-plus" />} onClick={events.openAdd}>
						Thêm
					</Button>
				)}
			</div>

			<Table rowKey="id" columns={tableColumns} dataSource={rows} loading={isFetching} size="middle"
				pagination={{pageSize: 10, hideOnSinglePage: true}} />

			<ModalForm
				open={open}
				icon={icon}
				title={itemEdit ? `Sửa ${entityLabel}` : `Thêm ${entityLabel}`}
				onCancel={() => setOpen(false)}
				onOk={events.submit}
				okText={itemEdit ? 'Lưu' : 'Thêm'}
				loading={adding || updating}
				width={520}
			>
				{fields.map((f) => (
					<Controller key={f.name} control={control} name={f.name} render={({field}) => {
						if (f.type === 'textarea') return <TextAreaField label={f.label} rows={f.rows || 3} errors={errors} {...field} />;
						if (f.type === 'select') return <SelectField label={f.label} options={f.options || []} errors={errors} {...field} />;
						if (f.type === 'switch') return (
							<CheckBoxField label={f.label} errors={errors} name={f.name}
								value={field.value} onChange={(e) => field.onChange(e.target.checked)}>
								{f.checkboxLabel || 'Đang sử dụng'}
							</CheckBoxField>
						);
						return <InputField label={f.label} placeholder={f.placeholder} errors={errors} {...field} />;
					}} />
				))}
			</ModalForm>
		</div>
	);
}

export default CatalogManager;
