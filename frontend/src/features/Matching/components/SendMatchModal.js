import {useEffect} from "react";
import {Controller, useForm} from "react-hook-form";
import {ModalForm} from "~/components";
import {TextAreaField} from "~/components/Forms";

/**
 * Modal xác nhận "gửi sản phẩm cho khách" + ghi chú (tuỳ chọn) — presentational (parent sở hữu
 * mutation/loading). Dùng chung ở trang /matching, drawer khách và panel BĐS.
 *
 * Props: open, title, subtitle, loading, onCancel, onSubmit(note).
 */
function SendMatchModal({open, title = 'Gửi sản phẩm cho khách', subtitle, loading, onCancel, onSubmit}) {

	const {control, handleSubmit, reset, formState: {errors}} = useForm({defaultValues: {note: ''}});

	useEffect(() => {
		if (open) reset({note: ''});
	}, [open, reset]);

	const submit = handleSubmit((data) => onSubmit((data.note || '').trim()));

	return (
		<ModalForm
			open={open}
			icon="fa-light fa-paper-plane"
			title={title}
			subtitle={subtitle}
			onCancel={onCancel}
			onOk={submit}
			okText="Gửi"
			loading={loading}
			width={480}
		>
			<Controller control={control} name="note" render={({field}) => (
				<TextAreaField label="Ghi chú (tuỳ chọn)" placeholder="Lời nhắn gửi kèm sản phẩm..."
					rows={3} errors={errors} {...field} />
			)} />
		</ModalForm>
	);
}

export default SendMatchModal;
