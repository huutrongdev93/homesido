import {useEffect, useMemo} from "react";
import {Controller, useForm} from "react-hook-form";
import {yupResolver} from "@hookform/resolvers/yup";
import * as Yup from "yup";
import {ModalForm} from "~/components";
import {SelectField, TextAreaField} from "~/components/Forms";
import {useGetAssignableUsersQuery} from "~/reduxs/api/customerApiSlice";

/**
 * Modal bàn giao khách cho nhân viên khác. Tải danh sách nhân viên khi mở; loại nhân viên đang
 * phụ trách khỏi lựa chọn. Props: open, loading, customer, onCancel, onSubmit({to_user_id, reason}).
 */
function CustomerTransferModal({open, loading, customer, onCancel, onSubmit}) {

	const {data: users = [], isFetching} = useGetAssignableUsersQuery(undefined, {skip: !open});

	const options = useMemo(() => users
		.filter((u) => u.id !== customer?.assigned_user_id)
		.map((u) => ({value: u.id, label: u.role ? `${u.fullname} (${u.role})` : u.fullname})),
	[users, customer]);

	const {control, handleSubmit, reset, formState: {errors}} = useForm({
		defaultValues: {to_user_id: undefined, reason: ''},
		resolver: yupResolver(Yup.object().shape({
			to_user_id: Yup.number().typeError('Vui lòng chọn nhân viên nhận').required('Vui lòng chọn nhân viên nhận'),
		})),
	});

	useEffect(() => {
		if (open) reset({to_user_id: undefined, reason: ''});
	}, [open, reset]);

	const submit = handleSubmit((data) => onSubmit(data));

	return (
		<ModalForm
			open={open}
			icon="fa-light fa-arrow-right-arrow-left"
			title="Bàn giao khách"
			subtitle={customer ? `Chuyển khách "${customer.full_name}" cho nhân viên khác` : ''}
			onCancel={onCancel}
			onOk={submit}
			okText="Bàn giao"
			loading={loading}
			width={480}
		>
			<Controller control={control} name="to_user_id" render={({field}) => (
				<SelectField
					label="Nhân viên nhận"
					placeholder="Chọn nhân viên"
					loading={isFetching}
					options={options}
					errors={errors}
					{...field}
				/>
			)} />
			<Controller control={control} name="reason" render={({field}) => (
				<TextAreaField label="Lý do (tuỳ chọn)" rows={2} placeholder="VD: Điều chuyển khu vực phụ trách" errors={errors} {...field} />
			)} />
		</ModalForm>
	);
}

export default CustomerTransferModal;
