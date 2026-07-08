import {useEffect, useMemo} from "react";
import {Controller, useForm} from "react-hook-form";
import {yupResolver} from "@hookform/resolvers/yup";
import * as Yup from "yup";
import dayjs from "dayjs";
import {ModalForm} from "~/components";
import {SelectField, DateField, TextAreaField} from "~/components/Forms";

const CARE_TYPES_SET = ['call', 'sms', 'zalo', 'email', 'meeting'];

/** Thay biến {{ten_khach}} trong mẫu bằng tên khách. */
const applyTemplate = (content, customerName) => (content || '').replaceAll('{{ten_khach}}', customerName || 'quý khách');

/**
 * Modal đặt lịch chăm sóc cho 1 khách. onSubmit(data) với scheduled_at đã format 'YYYY-MM-DD HH:mm:ss'.
 * Props: open, loading, careTypes, careTemplates (raw rows), customerName, onCancel, onSubmit.
 * Chọn kịch bản → prefill nội dung (thay {{ten_khach}}) + set hình thức theo kênh của kịch bản.
 */
function CareFormModal({open, loading, careTypes = [], careTemplates = [], customerName = '', onCancel, onSubmit}) {

	const templateOptions = useMemo(() => careTemplates
		.filter((t) => t.is_active)
		.map((t) => ({value: t.id, label: t.name})), [careTemplates]);

	const {control, handleSubmit, reset, setValue, formState: {errors}} = useForm({
		defaultValues: {type: 'call', care_template_id: undefined, scheduled_at: null, content: ''},
		resolver: yupResolver(Yup.object().shape({
			scheduled_at: Yup.number().nullable().required('Vui lòng chọn thời điểm chăm'),
		})),
	});

	useEffect(() => {
		if (open) reset({type: 'call', care_template_id: undefined, scheduled_at: null, content: ''});
	}, [open, reset]);

	const onPickTemplate = (id, onChange) => {
		onChange(id);
		const tpl = careTemplates.find((t) => t.id === id);
		if (!tpl) return;
		setValue('content', applyTemplate(tpl.content, customerName));
		if (CARE_TYPES_SET.includes(tpl.channel)) setValue('type', tpl.channel);
	};

	const submit = handleSubmit((data) => onSubmit({
		type: data.type,
		care_template_id: data.care_template_id || 0,
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
			{templateOptions.length > 0 && (
				<Controller control={control} name="care_template_id" render={({field}) => (
					<SelectField label="Kịch bản (tuỳ chọn)" placeholder="Chọn để điền sẵn nội dung" allowClear
						options={templateOptions} errors={errors}
						{...field} onChange={(v) => onPickTemplate(v, field.onChange)} />
				)} />
			)}
			<div className="mform-grid-2">
				<Controller control={control} name="type" render={({field}) => (
					<SelectField label="Hình thức" options={careTypes} errors={errors} {...field} />
				)} />
				<Controller control={control} name="scheduled_at" render={({field}) => (
					<DateField label="Thời điểm" showTime errors={errors} {...field} />
				)} />
			</div>
			<Controller control={control} name="content" render={({field}) => (
				<TextAreaField label="Nội dung" rows={3} placeholder="VD: Gọi lại chốt lịch xem nhà" errors={errors} {...field} />
			)} />
		</ModalForm>
	);
}

export default CareFormModal;
