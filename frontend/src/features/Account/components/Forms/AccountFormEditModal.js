import {useEffect} from "react";
import {useDispatch} from "react-redux";
import {useForm, Controller} from "react-hook-form";
import {yupResolver} from "@hookform/resolvers/yup";
import * as Yup from "yup";
import {App as AntdApp} from "antd";
import {ModalForm} from "~/components";
import {InputField, TextAreaField} from "~/components/Forms";
import {apiError, handleRequest} from "~/utils";
import {authApi} from "~/api";
import {authActions} from "~/reduxs/Auth/authSlice";

const schema = Yup.object().shape({
	firstname: Yup.string().required('Vui lòng nhập tên'),
	phone: Yup.string().matches(/^$|^[0-9+][0-9 .()-]{7,14}$/, 'Số điện thoại không hợp lệ'),
});

/**
 * Modal user TỰ sửa hồ sơ — chỉ field vô hại (họ tên / điện thoại / địa chỉ), khớp
 * POST api/auth/update. Email/username/chức vụ vẫn phải qua quản trị viên. Lưu xong
 * dispatch authActions.update với user payload mới → mọi nơi đọc useCurrentUser tự cập nhật.
 */
function AccountFormEditModal({open, currentUser, onCancel}) {

	const dispatch = useDispatch();
	const {notification} = AntdApp.useApp();

	const {control, handleSubmit, reset, formState: {isSubmitting, errors}} = useForm({
		defaultValues: {firstname: '', lastname: '', phone: '', address: ''},
		resolver: yupResolver(schema),
	});

	// Nạp giá trị hiện tại mỗi khi mở.
	useEffect(() => {
		if (open) reset({
			firstname: currentUser?.firstname || '',
			lastname: currentUser?.lastname || '',
			phone: currentUser?.phone || '',
			address: currentUser?.address || '',
		});
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [open]);

	const onSubmit = async (values) => {
		const [error, response] = await handleRequest(authApi.update(values));
		const message = apiError('Cập nhật hồ sơ thất bại', error, response);

		if (!message) {
			if (response?.data?.user) dispatch(authActions.update(response.data.user));
			notification.success({title: 'Thành công', description: 'Đã cập nhật hồ sơ cá nhân'});
			onCancel();
		}
	};

	return (
		<ModalForm
			open={open}
			icon="fa-light fa-user-pen"
			title="Chỉnh sửa hồ sơ"
			subtitle="Email, tên đăng nhập và chức vụ chỉ quản trị viên đổi được"
			onCancel={onCancel}
			onOk={handleSubmit(onSubmit)}
			loading={isSubmitting}
		>
			<Controller control={control} name="lastname" render={({field}) => (
				<InputField label="Họ" placeholder="VD: Nguyễn Văn" errors={errors} {...field} />
			)} />
			<Controller control={control} name="firstname" render={({field}) => (
				<InputField label="Tên" placeholder="VD: An" errors={errors} {...field} />
			)} />
			<Controller control={control} name="phone" render={({field}) => (
				<InputField label="Số điện thoại" placeholder="VD: 0901234567" errors={errors} {...field} />
			)} />
			<Controller control={control} name="address" render={({field}) => (
				<TextAreaField label="Địa chỉ" rows={2} errors={errors} {...field} />
			)} />
		</ModalForm>
	);
}

export default AccountFormEditModal;
