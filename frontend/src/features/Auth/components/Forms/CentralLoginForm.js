import {useState} from "react";
import {useForm, Controller} from "react-hook-form";
import axios from "axios";
import {yupResolver} from "@hookform/resolvers/yup";
import * as Yup from "yup";
import {
	Button,
	Loading,
	FontAwesomeIcon
} from "~/components";
import {InputField} from "~/components/Forms";
import {
	apiBaseForKey,
	homePrefixForKey,
	saveTenantSession,
	rememberTenant,
	getRecentTenants,
} from "~/utils";

/**
 * Form ĐĂNG NHẬP TRUNG TÂM (multi-tenant — hướng A) tại `domain.com/login` (không kèm `/{key}`).
 *
 * Vì username KHÔNG duy nhất toàn hệ thống (mỗi sàn 1 bảng users), phải nhập thêm **Mã sàn** để
 * biết đăng nhập vào sàn nào. Luồng: gọi thẳng `/{key}/api/auth/login` → lưu token vào namespace
 * `{key}:` của sàn đích → chuyển hướng full-URL vào `/{key}/` (app tự nạp phiên từ URL).
 *
 * Nhớ mã sàn: mỗi lần login thành công lưu key vào danh sách "sàn gần đây" (localStorage dùng
 * chung) để lần sau prefill + hiện chip 1-chạm. Xem docs/features/multi-tenant.md §"Đăng nhập trung tâm".
 */
function CentralLoginForm() {

	const recent = getRecentTenants();

	const [serverError, setServerError] = useState('');

	const initialValues = {tenant: recent[0] || '', username: '', password: ''};

	const validationSchema = Yup.object().shape({
		tenant: Yup.string()
			.required('Mã sàn không được để trống')
			.matches(/^[a-z0-9-]{3,30}$/, 'Mã sàn chỉ gồm chữ thường/số/gạch ngang, 3–30 ký tự'),
		username: Yup.string().required('Tên đăng nhập không được để trống').min(5, 'Tên đăng nhập quá ngắn'),
		password: Yup.string().required('Mật khẩu không được để trống'),
	});

	const {control, handleSubmit, setValue, formState: {isSubmitting, errors}} = useForm({
		defaultValues: initialValues,
		resolver: yupResolver(validationSchema),
	});

	const onSubmit = async (data) => {
		setServerError('');

		const key = String(data.tenant || '').trim().toLowerCase();

		try {
			// Instance riêng trỏ base của SÀN ĐÍCH (không dùng http.js — baseURL của nó là root).
			const api = axios.create({
				baseURL: apiBaseForKey(key),
				headers: {'Content-Type': 'application/json'},
			});

			const res = await api.post('/auth/login', {username: data.username, password: data.password});
			const body = res.data;

			if (!body?.accessToken) {
				setServerError('Đăng nhập thất bại, vui lòng thử lại.');
				return;
			}

			// Lưu token vào namespace của sàn đích + nhớ mã sàn, rồi reload vào sàn.
			saveTenantSession(key, body);
			rememberTenant(key);
			window.location.assign(homePrefixForKey(key) + '/');
		}
		catch (err) {
			const status = err?.response?.status;

			if (status === 404) {
				setServerError(`Mã sàn "${key}" không tồn tại.`);
			}
			else if (status === 401 || status === 422) {
				setServerError(err?.response?.data?.message || 'Sai tài khoản hoặc mật khẩu.');
			}
			else {
				setServerError('Không kết nối được máy chủ. Vui lòng thử lại sau.');
			}
		}
	};

	return (
		<form className="form" onSubmit={handleSubmit(onSubmit)}>
			{isSubmitting && <Loading/>}

			<Controller control={control} name="tenant"
			            render={({field}) => (
				            <InputField label="Mã sàn" placeholder="vd: sana"
				                        autoComplete="organization"
				                        prefix={<FontAwesomeIcon icon="fa-light fa-building" className="color-grey"/>}
				                        errors={errors} {...field}/>
			            )}
			/>

			{recent.length > 0 && (
				<div className="text-sm color-grey" style={{display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center', marginBottom: 12}}>
					<span>Gần đây:</span>
					{recent.map((k) => (
						<button type="button" key={k}
						        onClick={() => setValue('tenant', k, {shouldValidate: true})}
						        style={{border: '1px solid #d9d9d9', borderRadius: 12, padding: '2px 10px', cursor: 'pointer', background: 'transparent'}}>
							{k}
						</button>
					))}
				</div>
			)}

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

			{serverError && (
				<div className="color-red text-sm" style={{marginBottom: 12}}>
					<FontAwesomeIcon icon="fa-light fa-circle-exclamation"/> {serverError}
				</div>
			)}

			<div className="form-group mt-3 d-flex justify-content-center">
				<Button background blue type="submit" className={'w-full pdt-2 pdb-2'}>
					<FontAwesomeIcon icon="fa-light fa-right-to-bracket"/> Đăng nhập
				</Button>
			</div>
		</form>
	);
}

export default CentralLoginForm;
