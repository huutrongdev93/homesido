import {useEffect} from "react";
import {Controller, useForm} from "react-hook-form";
import {yupResolver} from "@hookform/resolvers/yup";
import * as Yup from "yup";
import {ModalForm} from "~/components";
import {SelectField, TextAreaField} from "~/components/Forms";

const DIRECTIONS = [
	{value: 'out', label: 'Chủ động (gọi đi / gửi đi)'},
	{value: 'in', label: 'Khách liên hệ (gọi đến)'},
];

/**
 * Modal ghi 1 tương tác vào timeline khách. Props: open, loading, interactionTypes, onCancel, onSubmit.
 */
function InteractionFormModal({open, loading, interactionTypes = [], onCancel, onSubmit}) {

	const {control, handleSubmit, reset, formState: {errors}} = useForm({
		defaultValues: {type: 'call', direction: 'out', content: ''},
		resolver: yupResolver(Yup.object().shape({
			content: Yup.string().required('Vui lòng nhập nội dung'),
		})),
	});

	useEffect(() => {
		if (open) reset({type: 'call', direction: 'out', content: ''});
	}, [open, reset]);

	const submit = handleSubmit((data) => onSubmit(data));

	return (
		<ModalForm
			open={open}
			icon="fa-light fa-comment-dots"
			title="Ghi tương tác"
			subtitle="Lưu lại nội dung liên hệ với khách"
			onCancel={onCancel}
			onOk={submit}
			okText="Lưu"
			loading={loading}
			width={480}
		>
			<div className="mform-grid-2">
				<Controller control={control} name="type" render={({field}) => (
					<SelectField label="Loại" options={interactionTypes} errors={errors} {...field} />
				)} />
				<Controller control={control} name="direction" render={({field}) => (
					<SelectField label="Chiều" allowClear options={DIRECTIONS} errors={errors} {...field} />
				)} />
			</div>
			<Controller control={control} name="content" render={({field}) => (
				<TextAreaField label="Nội dung" rows={3} placeholder="VD: Đã gọi tư vấn, khách quan tâm căn 2PN" errors={errors} {...field} />
			)} />
		</ModalForm>
	);
}

export default InteractionFormModal;
