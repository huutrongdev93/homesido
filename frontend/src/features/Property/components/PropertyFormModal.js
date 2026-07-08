import {useEffect} from "react";
import {Controller, useForm} from "react-hook-form";
import {yupResolver} from "@hookform/resolvers/yup";
import * as Yup from "yup";
import {ModalForm} from "~/components";
import {InputField, SelectField, TextAreaField} from "~/components/Forms";
import {useGetProvincesQuery, useGetWardsQuery} from "~/reduxs/api/locationApiSlice";

/**
 * Modal thêm/sửa BĐS — presentational (page sở hữu mutation/loading).
 *
 * Props: open, item (record sửa | null = thêm), loading, options {types, transactions, statuses,
 *        visibilities, legals, furnitures}, onCancel, onSubmit(data, item).
 * Địa chỉ: cascade tỉnh → phường qua locationApiSlice (phường nạp theo province_code đang chọn).
 */
const EMPTY = {
	title: '', code: '', property_type: '', transaction_type: 'sale', status: 'available',
	visibility: 'shared', price: '', area_land: '', area_usable: '',
	bedrooms: '', bathrooms: '', floors: '', direction: '',
	legal_status: '', furniture: '', province_code: undefined, ward_code: undefined,
	address: '', description: '',
};

function PropertyFormModal({open, item, loading, options = {}, onCancel, onSubmit}) {

	const {types = [], transactions = [], statuses = [], visibilities = [], legals = [], furnitures = []} = options;

	const {control, handleSubmit, reset, watch, setValue, formState: {errors}} = useForm({
		defaultValues: EMPTY,
		resolver: yupResolver(Yup.object().shape({
			title: Yup.string().required('Vui lòng nhập tiêu đề'),
		})),
	});

	const provinceCode = watch('province_code');

	const {data: provinces = []} = useGetProvincesQuery();
	const {data: wards = []} = useGetWardsQuery(provinceCode, {skip: !provinceCode});

	useEffect(() => {
		if (!open) return;
		reset(item ? {
			title: item.title || '', code: item.code || '',
			property_type: item.property_type || '', transaction_type: item.transaction_type || 'sale',
			status: item.status || 'available', visibility: item.visibility || 'shared',
			price: item.price || '', area_land: item.area_land || '', area_usable: item.area_usable || '',
			bedrooms: item.bedrooms || '', bathrooms: item.bathrooms || '', floors: item.floors || '',
			direction: item.direction || '', legal_status: item.legal_status || '', furniture: item.furniture || '',
			province_code: item.province_code || undefined, ward_code: item.ward_code || undefined,
			address: item.address || '', description: item.description || '',
		} : EMPTY);
	}, [open, item, reset]);

	const submit = handleSubmit((data) => onSubmit(data, item));

	return (
		<ModalForm
			open={open}
			icon="fa-light fa-building"
			title={item ? 'Sửa bất động sản' : 'Thêm bất động sản'}
			subtitle={item ? 'Cập nhật thông tin sản phẩm' : 'Đăng sản phẩm mới vào kho'}
			onCancel={onCancel}
			onOk={submit}
			okText={item ? 'Lưu' : 'Thêm'}
			loading={loading}
			width={760}
		>
			<Controller control={control} name="title" render={({field}) => (
				<InputField label="Tiêu đề" placeholder="VD: Căn hộ 2PN Quận 1" errors={errors} {...field} />
			)} />

			<div className="mform-grid-2">
				<Controller control={control} name="code" render={({field}) => (
					<InputField label="Mã sản phẩm" placeholder="Bỏ trống để tự sinh" errors={errors} {...field} />
				)} />
				<Controller control={control} name="property_type" render={({field}) => (
					<SelectField label="Loại hình" allowClear options={types} errors={errors} {...field} />
				)} />
				<Controller control={control} name="transaction_type" render={({field}) => (
					<SelectField label="Hình thức" options={transactions} errors={errors} {...field} />
				)} />
				<Controller control={control} name="status" render={({field}) => (
					<SelectField label="Trạng thái" options={statuses} errors={errors} {...field} />
				)} />
				<Controller control={control} name="price" render={({field}) => (
					<InputField label="Giá (VNĐ)" placeholder="VD: 3500000000" errors={errors} {...field} />
				)} />
				<Controller control={control} name="visibility" render={({field}) => (
					<SelectField label="Phạm vi" options={visibilities} errors={errors} {...field} />
				)} />
				<Controller control={control} name="area_land" render={({field}) => (
					<InputField label="Diện tích đất (m²)" errors={errors} {...field} />
				)} />
				<Controller control={control} name="area_usable" render={({field}) => (
					<InputField label="Diện tích sử dụng (m²)" errors={errors} {...field} />
				)} />
				<Controller control={control} name="bedrooms" render={({field}) => (
					<InputField label="Phòng ngủ" errors={errors} {...field} />
				)} />
				<Controller control={control} name="bathrooms" render={({field}) => (
					<InputField label="Phòng tắm" errors={errors} {...field} />
				)} />
				<Controller control={control} name="floors" render={({field}) => (
					<InputField label="Số tầng" errors={errors} {...field} />
				)} />
				<Controller control={control} name="direction" render={({field}) => (
					<InputField label="Hướng" placeholder="VD: Đông Nam" errors={errors} {...field} />
				)} />
				<Controller control={control} name="legal_status" render={({field}) => (
					<SelectField label="Pháp lý" allowClear options={legals} errors={errors} {...field} />
				)} />
				<Controller control={control} name="furniture" render={({field}) => (
					<SelectField label="Nội thất" allowClear options={furnitures} errors={errors} {...field} />
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

			<Controller control={control} name="address" render={({field}) => (
				<InputField label="Địa chỉ" errors={errors} {...field} />
			)} />
			<Controller control={control} name="description" render={({field}) => (
				<TextAreaField label="Mô tả" rows={3} errors={errors} {...field} />
			)} />
		</ModalForm>
	);
}

export default PropertyFormModal;
