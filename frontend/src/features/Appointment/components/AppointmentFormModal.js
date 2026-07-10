import {useContext, useEffect, useMemo, useState} from "react";
import {Controller, useForm, useWatch} from "react-hook-form";
import {yupResolver} from "@hookform/resolvers/yup";
import * as Yup from "yup";
import dayjs from "dayjs";
import {Tag, Empty} from "antd";
import {AppContext} from "~/context/AppProvider";
import {useCan} from "~/hooks";
import {ModalForm, FontAwesomeIcon} from "~/components";
import {DebounceSelect, DateField, NumberField, InputField, TextAreaField} from "~/components/Forms";
import {useGetCustomerQuery} from "~/reduxs/api/customerApiSlice";
import {useGetSuggestedPropertiesQuery} from "~/reduxs/api/matchingApiSlice";
import {useGetAppointmentsQuery} from "~/reduxs/api/appointmentApiSlice";
import {fmtPrice} from "~/features/Matching/matchUtils";
import request from "~/utils/http";
import style from "../style/Appointment.module.scss";

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

const STAGE_COLORS = {new: 'default', contacting: 'processing', potential: 'gold', negotiating: 'orange', won: 'success', lost: 'error'};
const TEMP_COLORS = {hot: 'red', warm: 'gold', cold: 'blue'};
const RESULT_COLORS = {interested: 'blue', considering: 'gold', rejected: 'default', deposited: 'green'};

const toMap = (arr = []) => arr.reduce((m, o) => ({...m, [o.value]: o.label}), {});
const fmtDate = (v) => (v ? dayjs(v).format('DD/MM/YYYY') : '');

/**
 * Modal tạo / sửa buổi hẹn dẫn khách. onSubmit(data) với scheduled_at đã format 'YYYY-MM-DD HH:mm:ss'.
 * Props: open, appointment (row khi sửa; null khi tạo), loading, onCancel, onSubmit.
 * Sửa: nạp sẵn khách/BĐS đang chọn vào DebounceSelect qua optionsDefault (để hiện nhãn).
 *
 * Panel bên phải (khi đã chọn khách): thông tin khách + "BĐS đã xem" (buổi hẹn `done` có gắn BĐS)
 * — bấm nút chọn lại để đặt lịch xem lại 1 căn cũ. Trường BĐS ưu tiên hiện quick-pick tối đa 5 gợi ý
 * (tái dùng Matching — `getSuggestedProperties`) thay vì chỉ có ô select trơn.
 */
function AppointmentFormModal({open, appointment = null, loading, onCancel, onSubmit}) {

	const isEdit = !!appointment;

	const {appData} = useContext(AppContext);
	const stageMap = useMemo(() => toMap(appData?.customer?.pipeline_stages), [appData]);
	const tempMap = useMemo(() => toMap(appData?.customer?.temperatures), [appData]);
	const resultMap = useMemo(() => toMap(appData?.appointment?.results), [appData]);

	const canCustomerView = useCan('customer_view');
	const canMatchView = useCan('matching_view');
	const canAppointmentView = useCan('appointment_view');

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

	// Nhãn BĐS đang chọn qua quick-pick (ghi đè optionsDefault để Select hiện đúng nhãn ngay).
	const [propertyPicked, setPropertyPicked] = useState(null);

	const {control, handleSubmit, reset, setValue, formState: {errors}} = useForm({
		defaultValues: {customer_id: undefined, property_id: undefined, scheduled_at: null, duration_min: 30, location: '', note: ''},
		resolver: yupResolver(Yup.object().shape({
			customer_id: Yup.number().nullable().required('Vui lòng chọn khách hàng'),
			scheduled_at: Yup.number().nullable().required('Vui lòng chọn thời điểm hẹn'),
		})),
	});

	const customerId = useWatch({control, name: 'customer_id'});
	const propertyId = useWatch({control, name: 'property_id'});

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
		setPropertyPicked(null);
	}, [open, appointment, reset]);

	const skipPanel = !open || !customerId;

	const {data: customerInfo} = useGetCustomerQuery(customerId, {skip: skipPanel || !canCustomerView});
	const {data: suggestedProperties = []} = useGetSuggestedPropertiesQuery(customerId, {skip: skipPanel || !canMatchView});
	const {data: viewedData} = useGetAppointmentsQuery({customer_id: customerId, status: 'done', pageSize: 50}, {skip: skipPanel || !canAppointmentView});

	const viewedProperties = useMemo(
		() => (viewedData?.items || []).filter((it) => it.property && it.property.code),
		[viewedData]
	);
	const propertyOptionsDefault = propertyPicked ? [propertyPicked] : propertyDefault;

	const pickProperty = (p) => {
		setValue('property_id', p.id, {shouldValidate: true});
		setPropertyPicked({value: p.id, label: `${p.code} — ${p.title}`});
	};

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
			width={900}
		>
			<div className={style.formLayout}>
				<div className={style.formMain}>
					<Controller control={control} name="customer_id" render={({field}) => (
						<DebounceSelect
							label="Khách hàng" name="customer_id" placeholder="Gõ tên hoặc số điện thoại khách..."
							fetchOptions={searchCustomers} optionsDefault={customerDefault} errors={errors}
							allowClear style={{width: '100%'}}
							value={field.value || undefined} onChange={(v) => field.onChange(v || null)}
						/>
					)} />

					{customerId && canMatchView && suggestedProperties.length > 0 && (
						<div className="form-group">
							<label>Gợi ý cho khách này (chọn nhanh)</label>
							<div className={style.suggestList}>
								{suggestedProperties.slice(0, 5).map((p) => (
									<div
										key={p.id}
										className={`${style.suggestItem} ${propertyId === p.id ? style.suggestItemActive : ''}`}
										onClick={() => pickProperty(p)}
									>
										<span className={style.suggestThumb}>
											{p.thumbnail ? <img src={p.thumbnail} alt="" /> : <FontAwesomeIcon icon="fa-light fa-image" />}
										</span>
										<div className={style.suggestInfo}>
											<div className={style.suggestTitle}>
												<span className={style.suggestCode}>{p.code}</span>
												<span className={style.suggestName}>{p.title}</span>
											</div>
											<div className={style.suggestPrice}>{fmtPrice(p.price)}</div>
										</div>
										{propertyId === p.id && <FontAwesomeIcon icon="fa-light fa-check" className={style.suggestCheck} />}
									</div>
								))}
							</div>
						</div>
					)}

					<Controller control={control} name="property_id" render={({field}) => (
						<DebounceSelect
							label="Bất động sản (tuỳ chọn)" name="property_id" placeholder="Không thấy? Gõ mã hoặc tiêu đề để tìm..."
							fetchOptions={searchProperties} optionsDefault={propertyOptionsDefault} errors={errors}
							allowClear style={{width: '100%'}}
							value={field.value || undefined}
							onChange={(v) => {field.onChange(v || null); if (!v) setPropertyPicked(null);}}
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
				</div>

				<div className={style.formSide}>
					{!customerId
						? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Chọn khách hàng để xem thông tin" />
						: <>
							{canCustomerView && customerInfo && (
								<div className={style.custCard}>
									<div className={style.custName}>{customerInfo.full_name}</div>
									<div className={style.custPhone}><FontAwesomeIcon icon="fa-light fa-phone" /> {customerInfo.phone}</div>
									<div className={style.custTags}>
										{customerInfo.pipeline_stage && <Tag color={STAGE_COLORS[customerInfo.pipeline_stage] || 'default'}>{stageMap[customerInfo.pipeline_stage] || customerInfo.pipeline_stage}</Tag>}
										{customerInfo.temperature && <Tag color={TEMP_COLORS[customerInfo.temperature] || 'default'}>{tempMap[customerInfo.temperature] || customerInfo.temperature}</Tag>}
									</div>
								</div>
							)}

							{canAppointmentView && (
								<div>
									<p className={style.sideTitle}><FontAwesomeIcon icon="fa-light fa-eye" /> BĐS đã xem</p>
									{viewedProperties.length === 0
										? <p className={style.sideEmpty}>Khách chưa dẫn xem BĐS nào</p>
										: <div className={style.viewedList}>
											{viewedProperties.map((it) => (
												<div key={it.id} className={style.viewedItem}>
													<div className={style.viewedInfo}>
														<div className={style.viewedTitle}>{it.property.code} — {it.property.title}</div>
														<div className={style.viewedMeta}>
															<span>{fmtDate(it.completed_at || it.scheduled_at)}</span>
															{it.result && <Tag color={RESULT_COLORS[it.result] || 'default'} bordered={false}>{resultMap[it.result] || it.result}</Tag>}
														</div>
													</div>
													<button
														type="button" title="Đặt lịch xem lại căn này" className={style.viewedPick}
														onClick={() => pickProperty({id: it.property_id, code: it.property.code, title: it.property.title})}
													>
														<FontAwesomeIcon icon="fa-light fa-rotate" />
													</button>
												</div>
											))}
										</div>}
								</div>
							)}
						</>}
				</div>
			</div>
		</ModalForm>
	);
}

export default AppointmentFormModal;
