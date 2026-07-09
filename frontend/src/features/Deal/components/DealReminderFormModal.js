import {useEffect} from "react";
import {Controller, useForm} from "react-hook-form";
import {yupResolver} from "@hookform/resolvers/yup";
import * as Yup from "yup";
import dayjs from "dayjs";
import {ModalForm} from "~/components";
import {InputField, DateField, TextAreaField} from "~/components/Forms";

/**
 * Modal tạo lời nhắc gắn với giao dịch (gọi khách, chuẩn bị hồ sơ...). Đến giờ, tick
 * deal-reminder-tick sẽ bắn thông báo cho sale phụ trách. Props: open, loading, onCancel, onSubmit(data).
 */
function DealReminderFormModal({open, loading, onCancel, onSubmit}) {

	const {control, handleSubmit, reset, formState: {errors}} = useForm({
		defaultValues: {title: '', remind_at: null, note: ''},
		resolver: yupResolver(Yup.object().shape({
			title: Yup.string().trim().required('Nhập nội dung nhắc'),
			remind_at: Yup.mixed().required('Chọn thời điểm nhắc'),
		})),
	});

	useEffect(() => {
		if (open) reset({title: '', remind_at: null, note: ''});
	}, [open, reset]);

	const submit = handleSubmit((data) => onSubmit({
		title: data.title,
		remind_at: data.remind_at ? dayjs(data.remind_at).format('YYYY-MM-DD HH:mm:ss') : '',
		note: data.note || '',
	}));

	return (
		<ModalForm
			open={open}
			icon="fa-light fa-bell"
			title="Thêm nhắc hẹn"
			subtitle="Đặt lời nhắc cho giao dịch"
			onCancel={onCancel}
			onOk={submit}
			okText="Lưu"
			loading={loading}
			width={440}
		>
			<Controller control={control} name="title" render={({field}) => (
				<InputField label="Nội dung nhắc" placeholder="VD: Gọi khách chốt lịch ký HĐ" errors={errors} {...field} />
			)} />
			<Controller control={control} name="remind_at" render={({field}) => (
				<DateField label="Thời điểm nhắc" showTime errors={errors} {...field} />
			)} />
			<Controller control={control} name="note" render={({field}) => (
				<TextAreaField label="Ghi chú" rows={2} errors={errors} {...field} />
			)} />
		</ModalForm>
	);
}

export default DealReminderFormModal;
