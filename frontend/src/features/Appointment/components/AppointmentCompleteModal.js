import {useEffect} from "react";
import {Controller, useForm} from "react-hook-form";
import {ModalForm} from "~/components";
import {SelectField, TextAreaField} from "~/components/Forms";

const STATUS_OPTIONS = [
	{value: 'done', label: 'Đã dẫn xem'},
	{value: 'no_show', label: 'Khách không đến'},
];

/**
 * Modal chốt buổi hẹn: "Đã dẫn xem" (ghi kết quả → tạo tương tác timeline) hoặc "Khách không đến".
 * Props: open, appointment, loading, results (enum kết quả từ api/utils), onCancel, onSubmit(data).
 */
function AppointmentCompleteModal({open, appointment, loading, results = [], onCancel, onSubmit}) {

	const {control, handleSubmit, reset, watch, formState: {errors}} = useForm({
		defaultValues: {status: 'done', result: undefined, result_note: ''},
	});

	const status = watch('status');

	useEffect(() => {
		if (open) reset({status: 'done', result: undefined, result_note: ''});
	}, [open, reset]);

	const submit = handleSubmit((data) => {
		const payload = {status: data.status, result_note: data.result_note || ''};
		if (data.status === 'done') payload.result = data.result || '';
		onSubmit(payload);
	});

	return (
		<ModalForm
			open={open}
			icon="fa-light fa-circle-check"
			title="Chốt buổi hẹn"
			subtitle={appointment?.customer?.full_name ? `Khách: ${appointment.customer.full_name}` : 'Ghi kết quả dẫn xem'}
			onCancel={onCancel}
			onOk={submit}
			okText="Lưu kết quả"
			loading={loading}
			width={480}
		>
			<Controller control={control} name="status" render={({field}) => (
				<SelectField label="Kết quả buổi hẹn" options={STATUS_OPTIONS} errors={errors} {...field} />
			)} />

			{status === 'done' && (
				<Controller control={control} name="result" render={({field}) => (
					<SelectField label="Phản hồi của khách" placeholder="Chọn mức độ quan tâm" allowClear
						options={results} errors={errors} {...field} />
				)} />
			)}

			<Controller control={control} name="result_note" render={({field}) => (
				<TextAreaField label="Ghi chú" rows={3}
					placeholder={status === 'done' ? 'VD: Khách thích view, cân nhắc căn tầng cao' : 'VD: Gọi không nghe máy'}
					errors={errors} {...field} />
			)} />
		</ModalForm>
	);
}

export default AppointmentCompleteModal;
