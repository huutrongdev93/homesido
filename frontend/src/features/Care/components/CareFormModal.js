import {useEffect} from "react";
import {Controller, useForm} from "react-hook-form";
import {yupResolver} from "@hookform/resolvers/yup";
import * as Yup from "yup";
import dayjs from "dayjs";
import {ModalForm} from "~/components";
import {SelectField, DateField, TextAreaField} from "~/components/Forms";

/**
 * Modal đặt lịch chăm sóc cho 1 khách. onSubmit(data) với scheduled_at đã format 'YYYY-MM-DD HH:mm:ss'.
 * Props: open, loading, careTypes, onCancel, onSubmit.
 */
function CareFormModal({open, loading, careTypes = [], onCancel, onSubmit}) {

	const {control, handleSubmit, reset, formState: {errors}} = useForm({
		defaultValues: {type: 'call', scheduled_at: null, content: ''},
		resolver: yupResolver(Yup.object().shape({
			scheduled_at: Yup.number().nullable().required('Vui lòng chọn thời điểm chăm'),
		})),
	});

	useEffect(() => {
		if (open) reset({type: 'call', scheduled_at: null, content: ''});
	}, [open, reset]);

	const submit = handleSubmit((data) => onSubmit({
		type: data.type,
		scheduled_at: dayjs(data.scheduled_at).format('YYYY-MM-DD HH:mm:ss'),
		content: data.content || '',
	}));

	return (
		<ModalForm
			open={open}
			icon="fa-light fa-calendar-plus"
			title="Đặt lịch chăm sóc"
			subtitle="Hẹn nhịp liên hệ tiếp theo với khách"
			onCancel={onCancel}
			onOk={submit}
			okText="Đặt lịch"
			loading={loading}
			width={480}
		>
			<div className="mform-grid-2">
				<Controller control={control} name="type" render={({field}) => (
					<SelectField label="Hình thức" options={careTypes} errors={errors} {...field} />
				)} />
				<Controller control={control} name="scheduled_at" render={({field}) => (
					<DateField label="Thời điểm" showTime errors={errors} {...field} />
				)} />
			</div>
			<Controller control={control} name="content" render={({field}) => (
				<TextAreaField label="Nội dung" rows={2} placeholder="VD: Gọi lại chốt lịch xem nhà" errors={errors} {...field} />
			)} />
		</ModalForm>
	);
}

export default CareFormModal;
