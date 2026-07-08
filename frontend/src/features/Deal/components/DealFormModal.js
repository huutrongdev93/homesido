import {useEffect, useMemo} from "react";
import {Controller, useForm} from "react-hook-form";
import {yupResolver} from "@hookform/resolvers/yup";
import * as Yup from "yup";
import {ModalForm} from "~/components";
import {DebounceSelect, InputPriceField, NumberField, TextAreaField} from "~/components/Forms";
import request from "~/utils/http";

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
 * Modal tạo / sửa giao dịch. Giá trị + hoa hồng nhập theo TRIỆU (÷/×1e6, DB lưu VNĐ).
 * Bỏ trống "Hoa hồng" → BE tự tính theo % trên giá trị. Props: open, deal, loading, onCancel, onSubmit.
 */
function DealFormModal({open, deal = null, loading, onCancel, onSubmit}) {

	const isEdit = !!deal;

	const customerDefault = useMemo(() => {
		if (!deal?.customer_id) return [];
		const c = deal.customer || {};
		return [{value: deal.customer_id, label: `${c.full_name || 'Khách'}${c.phone ? ' — ' + c.phone : ''}`}];
	}, [deal]);

	const propertyDefault = useMemo(() => {
		if (!deal?.property_id) return [];
		const p = deal.property || {};
		return [{value: deal.property_id, label: `${p.code || ''} — ${p.title || ''}`}];
	}, [deal]);

	const {control, handleSubmit, reset, formState: {errors}} = useForm({
		defaultValues: {customer_id: undefined, property_id: undefined, value: '', commission_rate: '', commission_amount: '', note: ''},
		resolver: yupResolver(Yup.object().shape({
			customer_id: Yup.number().nullable().required('Vui lòng chọn khách hàng'),
			property_id: Yup.number().nullable().required('Vui lòng chọn bất động sản'),
		})),
	});

	useEffect(() => {
		if (!open) return;
		reset({
			customer_id: deal?.customer_id || undefined,
			property_id: deal?.property_id || undefined,
			value: deal?.value ? deal.value / 1e6 : '',
			commission_rate: deal?.commission_rate || '',
			commission_amount: deal?.commission_amount ? deal.commission_amount / 1e6 : '',
			note: deal?.note || '',
		});
	}, [open, deal, reset]);

	const submit = handleSubmit((data) => onSubmit({
		customer_id: data.customer_id,
		property_id: data.property_id,
		value: data.value ? Number(data.value) * 1e6 : 0,
		commission_rate: data.commission_rate ? Number(data.commission_rate) : 0,
		commission_amount: data.commission_amount ? Number(data.commission_amount) * 1e6 : 0,
		note: data.note || '',
	}, deal));

	return (
		<ModalForm
			open={open}
			icon="fa-light fa-file-signature"
			title={isEdit ? 'Sửa giao dịch' : 'Tạo giao dịch'}
			subtitle="Khách + bất động sản + giá trị giao dịch"
			onCancel={onCancel}
			onOk={submit}
			okText={isEdit ? 'Lưu' : 'Tạo'}
			loading={loading}
			width={560}
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
					label="Bất động sản" name="property_id" placeholder="Gõ mã hoặc tiêu đề BĐS..."
					fetchOptions={searchProperties} optionsDefault={propertyDefault} errors={errors}
					allowClear style={{width: '100%'}}
					value={field.value || undefined} onChange={(v) => field.onChange(v || null)}
				/>
			)} />

			<Controller control={control} name="value" render={({field}) => (
				<InputPriceField label="Giá trị giao dịch (triệu)" placeholder="VD: 3500" min={0} errors={errors} {...field} />
			)} />

			<div className="mform-grid-2">
				<Controller control={control} name="commission_rate" render={({field}) => (
					<NumberField label="% hoa hồng" min={0} max={100} step={0.5} errors={errors} {...field} />
				)} />
				<Controller control={control} name="commission_amount" render={({field}) => (
					<InputPriceField label="Hoa hồng (triệu)" placeholder="Bỏ trống = tự tính theo %" min={0} errors={errors} {...field} />
				)} />
			</div>

			<Controller control={control} name="note" render={({field}) => (
				<TextAreaField label="Ghi chú" rows={3} errors={errors} {...field} />
			)} />
		</ModalForm>
	);
}

export default DealFormModal;
