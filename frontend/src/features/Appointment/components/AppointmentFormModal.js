import {useEffect, useMemo} from "react";
import {Controller, useForm} from "react-hook-form";
import {yupResolver} from "@hookform/resolvers/yup";
import * as Yup from "yup";
import dayjs from "dayjs";
import {ModalForm} from "~/components";
import {DebounceSelect, DateField, NumberField, InputField, TextAreaField} from "~/components/Forms";
import request from "~/utils/http";

// Tìm khách/BĐS cho DebounceSelect — dùng endpoint list sẵn có (không cần route riêng).
const searchCustomers = async (keyword) => {
	const body = await request({url: 'customer', method: 'get', params: {keyword, pageSize: 20}});
	return (body?.data?.items || []).map((c) => ({
		value: c.id,
		label: `${c.full_name}${c.phone ? ' — ' + c.phone : ''}`,
	}));
};
const searchProperties = async (keyword) => {
	const body = await request({url: 'property', method: 'get', params: {keyword, pageSize: 20}});
	return (body?.data?.items || []).map((p) => ({
		value: p.id,
		label: `${p.code} — ${p.title}`,
	}));
};

/**
 * Modal tạo / sửa buổi hẹn dẫn khách. onSubmit(data) với scheduled_at đã format 'YYYY-MM-DD HH:mm:ss'.
 * Props: open, appointment (row khi sửa; null khi tạo), loading, onCancel, onSubmit.
 * Sửa: nạp sẵn khách/BĐS đang chọn vào DebounceSelect qua optionsDefault (để hiện nhãn).
 */
function AppointmentFormModal({open, appointment = null, loading, onCancel, onSubmit}) {

	const isEdit = !!appointment;

	// Nhãn khách/BĐS đang gắn (để DebounceSelect hiện đúng khi mở form sửa).
	const customerDefault = useMemo(() => {
		if (!appointment?.customer_id) return [];
		const c = appointment.customer || {};
		return [{value: appointment.customer_id, label: `${c.full_name || 'Khách'}${c.phone ? ' — ' + c.phone : ''}`}];
	}, [appointment]);

	const propertyDefault = useMemo(() => {
		if (!appointment?.property_id) return [];
		const p = appointment.property || {};
		return [{value: appointment.property_id, label: `${p.code || ''} — ${p.title || ''}`}];
	}, [appointment]);

	const {control, handleSubmit, reset, formState: {errors}} = useForm({
		defaultValues: {customer_id: undefined, property_id: undefined, scheduled_at: null, duration_min: 30, location: '', note: ''},
		resolver: yupResolver(Yup.object().shape({
			customer_id: Yup.number().nullable().required('Vui lòng chọn khách hàng'),
			scheduled_at: Yup.number().nullable().required('Vui lòng chọn thời điểm hẹn'),
		})),
	});

	useEffect(() => {
		if (!open) return;
		reset({
			customer_id: appointment?.customer_id || undefined,
			property_id: appointment?.property_id || undefined,
			scheduled_at: appointment?.scheduled_at ? dayjs(appointment.scheduled_at).valueOf() : null,
			duration_min: appointment?.duration_min || 30,
			location: appointment?.location || '',
			note: appointment?.note || '',
		});
	}, [open, appointment, reset]);

	const submit = handleSubmit((data) => onSubmit({
		customer_id: data.customer_id,
		property_id: data.property_id || 0,
		scheduled_at: dayjs(data.scheduled_at).format('YYYY-MM-DD HH:mm:ss'),
		duration_min: data.duration_min || 0,
		location: data.location || '',
		note: data.note || '',
	}));

	return (
		<ModalForm
			open={open}
			icon="fa-light fa-calendar-plus"
			title={isEdit ? 'Sửa lịch hẹn' : 'Tạo lịch hẹn dẫn khách'}
			subtitle="Hẹn khách đi xem bất động sản"
			onCancel={onCancel}
			onOk={submit}
			okText={isEdit ? 'Lưu' : 'Tạo lịch'}
			loading={loading}
			width={520}
		>
			<Controller control={control} name="customer_id" render={({field}) => (
				<DebounceSelect
					label="Khách hàng" name="customer_id" placeholder="Gõ tên hoặc số điện thoại khách..."
					fetchOptions={searchCustomers} optionsDefault={customerDefault} errors={errors}
					allowClear style={{width: '100%'}}
					value={field.value || undefined} onChange={(v) => field.onChange(v || null)}
				/>
			)} />

			<Controller control={control} name="property_id" render={({field}) => (
				<DebounceSelect
					label="Bất động sản (tuỳ chọn)" name="property_id" placeholder="Gõ mã hoặc tiêu đề BĐS..."
					fetchOptions={searchProperties} optionsDefault={propertyDefault} errors={errors}
					allowClear style={{width: '100%'}}
					value={field.value || undefined} onChange={(v) => field.onChange(v || null)}
				/>
			)} />

			<div className="mform-grid-2">
				<Controller control={control} name="scheduled_at" render={({field}) => (
					<DateField label="Thời điểm hẹn" showTime errors={errors} {...field} />
				)} />
				<Controller control={control} name="duration_min" render={({field}) => (
					<NumberField label="Thời lượng (phút)" min={0} step={15} errors={errors} {...field} />
				)} />
			</div>

			<Controller control={control} name="location" render={({field}) => (
				<InputField label="Địa điểm gặp" placeholder="Bỏ trống sẽ lấy địa chỉ BĐS" errors={errors} {...field} />
			)} />

			<Controller control={control} name="note" render={({field}) => (
				<TextAreaField label="Ghi chú" rows={3} placeholder="VD: Dẫn xem căn góc tầng cao" errors={errors} {...field} />
			)} />
		</ModalForm>
	);
}

export default AppointmentFormModal;
