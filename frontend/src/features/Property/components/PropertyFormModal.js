import {useEffect} from "react";
import {Controller, useForm} from "react-hook-form";
import {yupResolver} from "@hookform/resolvers/yup";
import * as Yup from "yup";
import {ModalForm} from "~/components";
import {InputField, InputPriceField, SelectField, TextAreaField} from "~/components/Forms";
import {useGetProvincesQuery, useGetWardsQuery} from "~/reduxs/api/locationApiSlice";

/**
 * Modal thêm/sửa BĐS — presentational (page sở hữu mutation/loading).
 *
 * Props: open, item (record sửa | null = thêm), loading, options {types, transactions, statuses,
 *        visibilities, legals, furnitures, directions, roadTypes}, onCancel, onSubmit(data, item).
 * Địa chỉ: cascade tỉnh → phường qua locationApiSlice (phường nạp theo province_code đang chọn).
 * Giá: nhập theo đơn vị TRIỆU trên form; quy đổi ×/÷ 1.000.000 sang VNĐ khi lưu/nạp (DB lưu VNĐ).
 */
const EMPTY = {
	title: '', code: '', property_type: '', transaction_type: 'sale', status: 'available',
	visibility: 'shared', price: '', area_land: '', area_usable: '',
	bedrooms: '', bathrooms: '', floors: '', direction: '', road_type: '',
	legal_status: '', furniture: '', project_id: undefined, owner_id: undefined,
	province_code: undefined, ward_code: undefined,
	address: '', description: '',
};

function PropertyFormModal({open, item, loading, options = {}, onCancel, onSubmit}) {

	const {types = [], transactions = [], statuses = [], visibilities = [], legals = [], furnitures = [], directions = [], roadTypes = [], projects = [], owners = []} = options;

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
			price: item.price ? item.price / 1e6 : '', area_land: item.area_land || '', area_usable: item.area_usable || '',
			bedrooms: item.bedrooms || '', bathrooms: item.bathrooms || '', floors: item.floors || '',
			direction: item.direction || '', road_type: item.road_type || '',
			legal_status: item.legal_status || '', furniture: item.furniture || '',
			project_id: item.project_id || undefined, owner_id: item.owner_id || undefined,
			province_code: item.province_code || undefined, ward_code: item.ward_code || undefined,
			address: item.address || '', description: item.description || '',
		} : EMPTY);
	}, [open, item, reset]);

	const submit = handleSubmit((data) => onSubmit({
		...data,
		price: data.price ? Number(data.price) * 1e6 : '',   // triệu → VNĐ (DB lưu VNĐ)
	}, item));

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
				<div className="mform-row-3">
					<Controller control={control} name="transaction_type" render={({field}) => (
						<SelectField label="Hình thức" options={transactions} errors={errors} {...field} />
					)} />
					<Controller control={control} name="status" render={({field}) => (
						<SelectField label="Trạng thái" options={statuses} errors={errors} {...field} />
					)} />
					<Controller control={control} name="visibility" render={({field}) => (
						<SelectField label="Phạm vi" options={visibilities} errors={errors} {...field} />
					)} />
				</div>
				<div className="mform-row-3">
					<Controller control={control} name="price" render={({field}) => (
						<InputPriceField label="Giá (triệu)" placeholder="VD: 3500" min={0} errors={errors} {...field} />
					)} />
					<Controller control={control} name="area_land" render={({field}) => (
						<InputField label="Diện tích đất (m²)" errors={errors} {...field} />
					)} />
					<Controller control={control} name="area_usable" render={({field}) => (
						<InputField label="Diện tích sử dụng (m²)" errors={errors} {...field} />
					)} />
				</div>
				<div className="mform-row-3">
					<Controller control={control} name="bedrooms" render={({field}) => (
						<InputField label="Phòng ngủ" errors={errors} {...field} />
					)} />
					<Controller control={control} name="bathrooms" render={({field}) => (
						<InputField label="Phòng tắm" errors={errors} {...field} />
					)} />
					<Controller control={control} name="floors" render={({field}) => (
						<InputField label="Số tầng" errors={errors} {...field} />
					)} />
				</div>
				<Controller control={control} name="direction" render={({field}) => (
					<SelectField label="Hướng" allowClear options={directions} errors={errors} {...field} />
				)} />
				<Controller control={control} name="road_type" render={({field}) => (
					<SelectField label="Đường vào" allowClear options={roadTypes} errors={errors} {...field} />
				)} />
				<Controller control={control} name="legal_status" render={({field}) => (
					<SelectField label="Pháp lý" allowClear options={legals} errors={errors} {...field} />
				)} />
				<Controller control={control} name="furniture" render={({field}) => (
					<SelectField label="Nội thất" allowClear options={furnitures} errors={errors} {...field} />
				)} />
				<Controller control={control} name="project_id" render={({field}) => (
					<SelectField label="Dự án" allowClear options={projects} errors={errors} {...field} />
				)} />
				<Controller control={control} name="owner_id" render={({field}) => (
					<SelectField label="Chủ nhà" allowClear options={owners} errors={errors} {...field} />
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
