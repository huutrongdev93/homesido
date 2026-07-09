import {useEffect} from "react";
import {Controller, useForm} from "react-hook-form";
import {yupResolver} from "@hookform/resolvers/yup";
import * as Yup from "yup";
import dayjs from "dayjs";
import {ModalForm} from "~/components";
import {InputPriceField, SelectField, DateField, TextAreaField} from "~/components/Forms";

const PAYMENT_TYPES = [
	{value: 'paid', label: 'Đã thu'},
	{value: 'planned', label: 'Dự kiến (đặt lịch nhắc)'},
];

/**
 * Modal ghi 1 đợt thanh toán cho giao dịch. Số tiền nhập theo TRIỆU (×1e6, DB lưu VNĐ).
 * Loại "Đã thu" → có Ngày thu; "Dự kiến" → có Ngày đến hạn (tick sẽ nhắc khi tới hạn).
 * Props: open, loading, methods (enum payment_methods), onCancel, onSubmit(data).
 */
function DealPaymentFormModal({open, loading, methods = [], onCancel, onSubmit}) {

	const {control, handleSubmit, reset, watch, formState: {errors}} = useForm({
		defaultValues: {status: 'paid', amount: '', method: undefined, paid_at: null, due_date: null, note: ''},
		resolver: yupResolver(Yup.object().shape({
			amount: Yup.number().typeError('Nhập số tiền').positive('Số tiền phải lớn hơn 0').required('Nhập số tiền'),
			due_date: Yup.mixed().when('status', {
				is: 'planned',
				then: (s) => s.required('Chọn ngày đến hạn'),
				otherwise: (s) => s.nullable(),
			}),
		})),
	});

	useEffect(() => {
		if (open) reset({status: 'paid', amount: '', method: undefined, paid_at: null, due_date: null, note: ''});
	}, [open, reset]);

	const status = watch('status');
	const isPlanned = status === 'planned';

	const submit = handleSubmit((data) => onSubmit({
		amount: Number(data.amount) * 1e6,
		status: data.status || 'paid',
		method: data.method || '',
		paid_at: (!isPlanned && data.paid_at) ? dayjs(data.paid_at).format('YYYY-MM-DD HH:mm:ss') : '',
		due_date: (isPlanned && data.due_date) ? dayjs(data.due_date).format('YYYY-MM-DD HH:mm:ss') : '',
		note: data.note || '',
	}));

	return (
		<ModalForm
			open={open}
			icon="fa-light fa-money-bill-wave"
			title="Ghi đợt thanh toán"
			subtitle="Cọc / đợt thu tiền của giao dịch"
			onCancel={onCancel}
			onOk={submit}
			okText="Ghi nhận"
			loading={loading}
			width={440}
		>
			<Controller control={control} name="status" render={({field}) => (
				<SelectField label="Loại" options={PAYMENT_TYPES} errors={errors} {...field} />
			)} />
			<Controller control={control} name="amount" render={({field}) => (
				<InputPriceField label="Số tiền (triệu)" placeholder="VD: 500" min={0} errors={errors} {...field} />
			)} />
			<div className="mform-grid-2">
				<Controller control={control} name="method" render={({field}) => (
					<SelectField label="Hình thức" placeholder="Chọn" allowClear options={methods} errors={errors} {...field} />
				)} />
				{isPlanned ? (
					<Controller control={control} name="due_date" render={({field}) => (
						<DateField label="Ngày đến hạn" showTime errors={errors} {...field} />
					)} />
				) : (
					<Controller control={control} name="paid_at" render={({field}) => (
						<DateField label="Ngày thu" showTime errors={errors} {...field} />
					)} />
				)}
			</div>
			<Controller control={control} name="note" render={({field}) => (
				<TextAreaField label="Ghi chú" rows={2} errors={errors} {...field} />
			)} />
		</ModalForm>
	);
}

export default DealPaymentFormModal;
