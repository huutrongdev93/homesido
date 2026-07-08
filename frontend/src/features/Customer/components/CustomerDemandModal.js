import {useEffect} from "react";
import {Controller, useForm} from "react-hook-form";
import {ModalForm} from "~/components";
import {InputPriceField, NumberField, SelectField, CheckBoxField} from "~/components/Forms";
import {useGetProvincesQuery, useGetWardsQuery} from "~/reduxs/api/locationApiSlice";

/**
 * Modal thêm/sửa 1 nhu cầu / tiêu chí của khách — presentational (drawer sở hữu mutation/loading).
 *
 * Props: open, item (record sửa | null = thêm), loading, options {demandTypes, propertyTypes,
 *        purposes, directions}, onCancel, onSubmit(data, item).
 * Ngân sách: nhập theo đơn vị TRIỆU trên form; quy đổi ×/÷ 1.000.000 sang VNĐ khi lưu/nạp
 * (DB lưu VNĐ — đồng nhất với giá BĐS để Matching GĐ2 so trực tiếp). Địa chỉ: cascade tỉnh → phường.
 */
const EMPTY = {
	demand_type: 'buy', property_type: '', purpose: '',
	province_code: undefined, ward_code: undefined,
	budget_min: '', budget_max: '', area_min: '', area_max: '',
	bedrooms_min: '', direction: '', is_active: true,
};

function CustomerDemandModal({open, item, loading, options = {}, onCancel, onSubmit}) {

	const {demandTypes = [], propertyTypes = [], purposes = [], directions = []} = options;

	const {control, handleSubmit, reset, watch, setValue, formState: {errors}} = useForm({
		defaultValues: EMPTY,
	});

	const provinceCode = watch('province_code');

	const {data: provinces = []} = useGetProvincesQuery();
	const {data: wards = []} = useGetWardsQuery(provinceCode, {skip: !provinceCode});

	useEffect(() => {
		if (!open) return;
		reset(item ? {
			demand_type: item.demand_type || 'buy',
			property_type: item.property_type || '',
			purpose: item.purpose || '',
			province_code: item.province_code || undefined,
			ward_code: item.ward_code || undefined,
			budget_min: item.budget_min ? item.budget_min / 1e6 : '',
			budget_max: item.budget_max ? item.budget_max / 1e6 : '',
			area_min: item.area_min || '',
			area_max: item.area_max || '',
			bedrooms_min: item.bedrooms_min || '',
			direction: item.direction || '',
			is_active: item.is_active !== false,
		} : EMPTY);
	}, [open, item, reset]);

	const submit = handleSubmit((data) => onSubmit({
		...data,
		budget_min: data.budget_min ? Number(data.budget_min) * 1e6 : 0,   // triệu → VNĐ
		budget_max: data.budget_max ? Number(data.budget_max) * 1e6 : 0,
		is_active: data.is_active ? 1 : 0,
	}, item));

	return (
		<ModalForm
			open={open}
			icon="fa-light fa-magnifying-glass-location"
			title={item ? 'Sửa nhu cầu' : 'Thêm nhu cầu'}
			subtitle="Tiêu chí tìm kiếm BĐS của khách (dùng để gợi ý sản phẩm phù hợp)"
			onCancel={onCancel}
			onOk={submit}
			okText={item ? 'Lưu' : 'Thêm'}
			loading={loading}
			width={640}
		>
			<div className="mform-grid-2">
				<Controller control={control} name="demand_type" render={({field}) => (
					<SelectField label="Nhu cầu" options={demandTypes} errors={errors} {...field} />
				)} />
				<Controller control={control} name="property_type" render={({field}) => (
					<SelectField label="Loại hình" allowClear options={propertyTypes} errors={errors} {...field} />
				)} />
				<Controller control={control} name="purpose" render={({field}) => (
					<SelectField label="Mục đích" allowClear options={purposes} errors={errors} {...field} />
				)} />
				<Controller control={control} name="direction" render={({field}) => (
					<SelectField label="Hướng" allowClear options={directions} errors={errors} {...field} />
				)} />
				<Controller control={control} name="budget_min" render={({field}) => (
					<InputPriceField label="Ngân sách từ (triệu)" min={0} errors={errors} {...field} />
				)} />
				<Controller control={control} name="budget_max" render={({field}) => (
					<InputPriceField label="Ngân sách đến (triệu)" min={0} errors={errors} {...field} />
				)} />
				<Controller control={control} name="area_min" render={({field}) => (
					<NumberField label="Diện tích từ (m²)" min={0} errors={errors} {...field} />
				)} />
				<Controller control={control} name="area_max" render={({field}) => (
					<NumberField label="Diện tích đến (m²)" min={0} errors={errors} {...field} />
				)} />
				<Controller control={control} name="bedrooms_min" render={({field}) => (
					<NumberField label="Số phòng ngủ tối thiểu" min={0} errors={errors} {...field} />
				)} />
				<Controller control={control} name="province_code" render={({field}) => (
					<SelectField label="Tỉnh / Thành" allowClear options={provinces} errors={errors}
						{...field}
						onChange={(v) => {
							field.onChange(v);
							setValue('ward_code', undefined);   // đổi tỉnh → reset phường
						}}
					/>
				)} />
				<Controller control={control} name="ward_code" render={({field}) => (
					<SelectField label="Phường / Xã" allowClear options={wards}
						disabled={!provinceCode} errors={errors} {...field} />
				)} />
			</div>

			<Controller control={control} name="is_active" render={({field}) => (
				<CheckBoxField errors={errors} {...field} onChange={(e) => field.onChange(e.target.checked)}>
					Đang tìm (bỏ chọn nếu khách đã ngừng tìm theo tiêu chí này)
				</CheckBoxField>
			)} />
		</ModalForm>
	);
}

export default CustomerDemandModal;
