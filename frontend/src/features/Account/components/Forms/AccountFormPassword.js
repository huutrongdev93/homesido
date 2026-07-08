import {Controller, useForm} from "react-hook-form";
import {yupResolver} from "@hookform/resolvers/yup";
import * as Yup from "yup";
import className from "classnames/bind";
import {App as AntdApp} from "antd";
import {
	Loading,
	Button
} from "~/components";
import {
	apiError,
	handleRequest
} from "~/utils";
import {InputField} from "~/components/Forms";
import {authApi} from "~/api";
import style from "../../style/Account.module.scss";

const cn = className.bind(style);

/** Form đổi mật khẩu — tab "Mật khẩu" của trang Hồ sơ cá nhân (/account). */
function AccountFormPassword() {

	const {notification} = AntdApp.useApp();

	const { control, handleSubmit, reset, formState: { isSubmitting, errors }} = useForm({
		defaultValues: {
			passCurrent: '',
			passNew: '',
			passNewConfirm: '',
		},
		resolver: yupResolver(Yup.object().shape({
			passCurrent: Yup.string().required('Bạn chưa điền mật khẩu hiện tại'),
			passNew: Yup.string().required('Bạn chưa điền mật khẩu mới'),
			passNewConfirm: Yup.string()
				.required('Bạn chưa nhập lại mật khẩu mới')
				.oneOf([Yup.ref('passNew')], 'Mật khẩu nhập lại không khớp'),
		}))
	});

	const handleChangePassword = async (data) => {
		let [error, response] = await handleRequest(authApi.password(data));
		let message = apiError(`Cập nhật mật khẩu thất bại`, error, response);
		if (!message) {
			notification.success({
				title: 'Thành công', description: `Đổi mật khẩu thành công`
			});
			reset();
		}
	}

	return (
		<section className={cn('card', 'card-form')}>
			<div className={cn('card-head')}>
				<span className={cn('card-head-icon')}>
					<i className="fa-light fa-lock" />
				</span>
				<h2 className={cn('card-title')}>Đổi mật khẩu</h2>
			</div>

			<form className="form" onSubmit={handleSubmit(handleChangePassword)}>
				{isSubmitting && <Loading/>}
				<Controller control={control} name="passCurrent" render={({ field }) => (
					<InputField label="Mật khẩu hiện tại" type={'password'} errors={errors} {...field}/>
				)}/>
				<Controller control={control} name="passNew" render={({ field }) => (
					<InputField label="Mật khẩu mới" type={'password'} errors={errors} {...field}/>
				)}/>
				<Controller control={control} name="passNewConfirm" render={({ field }) => (
					<InputField label="Nhập lại mật khẩu mới" type={'password'} errors={errors} {...field}/>
				)}/>
				<div className={cn('form-actions')}>
					<Button primary type="submit">Đổi mật khẩu</Button>
				</div>
			</form>
		</section>
	)
}

export default AccountFormPassword;
