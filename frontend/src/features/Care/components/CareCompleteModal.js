import {useEffect, useMemo} from "react";
import {Controller, useForm} from "react-hook-form";
import dayjs from "dayjs";
import {ModalForm} from "~/components";
import {SelectField, DateField, TextAreaField, CheckBoxField} from "~/components/Forms";

const CARE_TYPES_SET = ['call', 'sms', 'zalo', 'email', 'meeting'];
const applyTemplate = (content, customerName) => (content || '').replaceAll('{{ten_khach}}', customerName || 'quý khách');

/**
 * Modal hoàn thành 1 lịch chăm: ghi kết quả (→ tạo tương tác timeline) + tuỳ chọn đặt lịch tiếp.
 * Props: open, care, loading, careTypes, careTemplates (raw), customerName, onCancel, onSubmit(data).
 */
function CareCompleteModal({open, care, loading, careTypes = [], careTemplates = [], customerName = '', onCancel, onSubmit}) {

	const templateOptions = useMemo(() => careTemplates
		.filter((t) => t.is_active)
		.map((t) => ({value: t.id, label: t.name})), [careTemplates]);

	const {control, handleSubmit, reset, watch, setValue, formState: {errors}} = useForm({
		defaultValues: {result_note: '', schedule_next: false, next_template_id: undefined, next_type: 'call', next_scheduled_at: null, next_content: ''},
	});

	const scheduleNext = watch('schedule_next');

	const onPickTemplate = (id, onChange) => {
		onChange(id);
		const tpl = careTemplates.find((t) => t.id === id);
		if (!tpl) return;
		setValue('next_content', applyTemplate(tpl.content, customerName));
		if (CARE_TYPES_SET.includes(tpl.channel)) setValue('next_type', tpl.channel);
	};

	useEffect(() => {
		if (open) reset({result_note: '', schedule_next: false, next_template_id: undefined, next_type: care?.type || 'call', next_scheduled_at: null, next_content: ''});
	}, [open, care, reset]);

	const submit = handleSubmit((data) => {
		const payload = {result_note: data.result_note || ''};
		if (data.schedule_next && data.next_scheduled_at) {
			payload.next_type = data.next_type;
			payload.next_scheduled_at = dayjs(data.next_scheduled_at).format('YYYY-MM-DD HH:mm:ss');
			payload.next_content = data.next_content || '';
		}
		onSubmit(payload);
	});

	return (
		<ModalForm
			open={open}
			icon="fa-light fa-circle-check"
			title="Hoàn thành chăm sóc"
			subtitle="Ghi kết quả liên hệ với khách"
			onCancel={onCancel}
			onOk={submit}
			okText="Hoàn thành"
			loading={loading}
			width={480}
		>
			<Controller control={control} name="result_note" render={({field}) => (
				<TextAreaField label="Kết quả" rows={3} placeholder="VD: Khách đồng ý xem nhà thứ 7" errors={errors} {...field} />
			)} />

			<Controller control={control} name="schedule_next" render={({field}) => (
				<CheckBoxField name={field.name} value={field.value} onChange={(e) => field.onChange(e.target.checked)}>
					Đặt lịch chăm tiếp theo
				</CheckBoxField>
			)} />

			{scheduleNext && (
				<>
					{templateOptions.length > 0 && (
						<Controller control={control} name="next_template_id" render={({field}) => (
							<SelectField label="Kịch bản (tuỳ chọn)" placeholder="Chọn để điền sẵn nội dung" allowClear
								options={templateOptions} errors={errors}
								{...field} onChange={(v) => onPickTemplate(v, field.onChange)} />
						)} />
					)}
					<div className="mform-grid-2">
						<Controller control={control} name="next_type" render={({field}) => (
							<SelectField label="Hình thức" options={careTypes} errors={errors} {...field} />
						)} />
						<Controller control={control} name="next_scheduled_at" render={({field}) => (
							<DateField label="Thời điểm" showTime errors={errors} {...field} />
						)} />
					</div>
					<Controller control={control} name="next_content" render={({field}) => (
						<TextAreaField label="Nội dung lịch tiếp" rows={2} errors={errors} {...field} />
					)} />
				</>
			)}
		</ModalForm>
	);
}

export default CareCompleteModal;
