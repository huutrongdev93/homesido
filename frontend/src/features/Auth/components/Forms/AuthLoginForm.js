import {useContext} from "react";
import {useForm, Controller} from "react-hook-form";
import {useDispatch} from "react-redux";
import {Tooltip} from "antd";
import {yupResolver} from "@hookform/resolvers/yup";
import * as Yup from "yup";
import {
	Button,
	Loading,
	FontAwesomeIcon
} from "~/components";
import {InputField} from "~/components/Forms";
import {authActions} from "~/reduxs/Auth/authSlice";
import {AppContext} from "~/context/AppProvider";
import {apiError, handleRequest} from "~/utils";
import {authApi} from "~/api";
import {globalNavigate} from "~/routes/GlobalHistory";

function AuthLoginForm() {

	const dispatch = useDispatch();

	const {setUserLogin} = useContext(AppContext);

	const initialValues = { username: '', password: '' }

	const validationSchema = Yup.object().shape({
		username: Yup.string().required('Tên đăng nhập không được để trống').min(5, 'Tên đăng nhập quá ngắn'),
		password: Yup.string().required('Mật khẩu không được để trống'),
	})

	const { control, handleSubmit, formState: { isSubmitting, errors } } = useForm({
		defaultValues: initialValues,
		resolver: yupResolver(validationSchema)
	});

	const onSubmit = async (data) => {

		let [error, response] = await handleRequest(authApi.token(data.username, data.password));

		let message = apiError(`Đăng nhập thất bại`, error, response);

		if(!message) {
			const accessToken = {
				accessToken   : response.accessToken,
				expires       : response.expires,
			}
			localStorage.setItem('access_token', JSON.stringify(accessToken));
			localStorage.setItem('reload_token', response.refreshToken);
			dispatch(authActions.loginSuccess(response.data.user))
			setUserLogin(true);
			globalNavigate("/");
		}
	}

	return (
        <form className="form" onSubmit={handleSubmit(onSubmit)}>
            {isSubmitting && <Loading/>}

            <Controller control={control} name="username"
                        render={({field}) => (
                            <InputField label="Email hoặc tên đăng nhập" placeholder="Tên đăng nhập"
                                        autoComplete="username"
                                        prefix={<FontAwesomeIcon icon="fa-light fa-user" className="color-grey"/>}
                                        errors={errors} {...field}/>
                        )}
            />
            <Controller control={control} name="password"
                        render={({field}) => (
                            <InputField label="Mật khẩu" type="password" placeholder="Mật khẩu đăng nhập"
                                        autoComplete="current-password"
                                        prefix={<FontAwesomeIcon icon="fa-light fa-lock" className="color-grey"/>}
                                        errors={errors} {...field}/>
                        )}
            />
            <div className="text-right">
                <Tooltip title="Vui lòng liên hệ quản trị viên để được cấp lại mật khẩu.">
                    <span className="text-sm color-grey" style={{cursor: 'help'}}>
                        Quên mật khẩu? <FontAwesomeIcon icon="fa-light fa-circle-question"/>
                    </span>
                </Tooltip>
            </div>
            <div className="form-group mt-3 d-flex justify-content-center">
                <Button background blue type="submit" className={'w-full pdt-2 pdb-2'}>
                    <FontAwesomeIcon icon="fa-light fa-right-to-bracket"/> Đăng nhập
                </Button>
            </div>
        </form>
    )
}

export default AuthLoginForm;