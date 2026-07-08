import {useEffect} from "react";
import {Controller, useForm} from "react-hook-form";
import {yupResolver} from "@hookform/resolvers/yup";
import * as Yup from "yup";
import {ModalForm} from "~/components";
import {InputField, SelectField, TextAreaField} from "~/components/Forms";

/**
 * Modal thêm/sửa khách hàng — presentational: page sở hữu mutation/loading.
 *
 * Props: open, item (record đang sửa | null = thêm), loading, options {genders, stages, temperatures},
 *        onCancel, onSubmit(data, item).
 */
const EMPTY = {
	full_name: '', phone: '', phone_alt: '', email: '', gender: '',
	birth_year: '', occupation: '', address: '',
	pipeline_stage: 'new', temperature: 'warm', note: '',
};

function CustomerFormModal({open, item, loading, options = {}, onCancel, onSubmit}) {

	const {genders = [], stages = [], temperatures = []} = options;

	const {control, handleSubmit, reset, formState: {errors}} = useForm({
		defaultValues: EMPTY,
		resolver: yupResolver(Yup.object().shape({
			full_name: Yup.string().required('Vui lòng nhập họ tên'),
			phone: Yup.string().required('Vui lòng nhập số điện thoại'),
		})),
	});

	// Nạp dữ liệu khi mở (sửa) hoặc reset rỗng (thêm).
	useEffect(() => {
		if (!open) return;
		reset(item ? {
			full_name: item.full_name || '',
			phone: item.phone || '',
			phone_alt: item.phone_alt || '',
			email: item.email || '',
			gender: item.gender || '',
			birth_year: item.birth_year || '',
			occupation: item.occupation || '',
			address: item.address || '',
			pipeline_stage: item.pipeline_stage || 'new',
			temperature: item.temperature || 'warm',
			note: item.note || '',
		} : EMPTY);
	}, [open, item, reset]);

	const submit = handleSubmit((data) => onSubmit(data, item));

	return (
		<ModalForm
			open={open}
			icon="fa-light fa-user-plus"
			title={item ? 'Sửa khách hàng' : 'Thêm khách hàng'}
			subtitle={item ? 'Cập nhật thông tin khách hàng' : 'Tạo hồ sơ khách hàng mới'}
			onCancel={onCancel}
			onOk={submit}
			okText={item ? 'Lưu' : 'Thêm'}
			loading={loading}
			width={640}
		>
			<div className="mform-grid-2">
				<Controller control={control} name="full_name" render={({field}) => (
					<InputField label="Họ tên" placeholder="VD: Nguyễn Văn A" errors={errors} {...field} />
				)} />
				<Controller control={control} name="phone" render={({field}) => (
					<InputField label="Số điện thoại" placeholder="VD: 09xxxxxxxx" errors={errors} {...field} />
				)} />
				<Controller control={control} name="phone_alt" render={({field}) => (
					<InputField label="SĐT phụ" errors={errors} {...field} />
				)} />
				<Controller control={control} name="email" render={({field}) => (
					<InputField label="Email" errors={errors} {...field} />
				)} />
				<Controller control={control} name="gender" render={({field}) => (
					<SelectField label="Giới tính" allowClear options={genders} errors={errors} {...field} />
				)} />
				<Controller control={control} name="birth_year" render={({field}) => (
					<InputField label="Năm sinh" placeholder="VD: 1990" errors={errors} {...field} />
				)} />
				<Controller control={control} name="occupation" render={({field}) => (
					<InputField label="Nghề nghiệp" errors={errors} {...field} />
				)} />
				<Controller control={control} name="pipeline_stage" render={({field}) => (
					<SelectField label="Giai đoạn" options={stages} errors={errors} {...field} />
				)} />
				<Controller control={control} name="temperature" render={({field}) => (
					<SelectField label="Mức quan tâm" options={temperatures} errors={errors} {...field} />
				)} />
			</div>

			<Controller control={control} name="address" render={({field}) => (
				<InputField label="Địa chỉ" errors={errors} {...field} />
			)} />
			<Controller control={control} name="note" render={({field}) => (
				<TextAreaField label="Ghi chú" rows={3} errors={errors} {...field} />
			)} />
		</ModalForm>
	);
}

export default CustomerFormModal;
