import {Controller, useForm} from "react-hook-form";
import {yupResolver} from "@hookform/resolvers/yup";
import * as Yup from "yup";
import {ModalForm} from "~/components";
import {InputField} from "~/components/Forms";
import {roleApi} from "~/api";
import {apiError, handleRequest} from "~/utils";

/**
 * Modal thêm chức vụ mới. Gọi onAdded({key, name}) khi thành công.
 *
 * Dùng <ModalForm> (không phải antd Modal trần) để input nằm trong scope `.form`
 * → InputField áp đúng style chuẩn của dự án, đồng nhất với các form khác.
 */
function PermissionFormAdd({open, onCancel, onAdded}) {

	const {control, handleSubmit, reset, formState: {isSubmitting, errors}} = useForm({
		defaultValues: {name: ''},
		resolver: yupResolver(Yup.object().shape({
			name: Yup.string().required('Bạn chưa nhập tên chức vụ'),
		})),
	});

	const onSubmit = async (data) => {
		const [error, response] = await handleRequest(roleApi.add(data));
		if (!apiError('Thêm chức vụ thất bại', error, response)) {
			reset({name: ''});
			onAdded(response.data);
		}
	};

	const handleCancel = () => {
		reset({name: ''});
		onCancel();
	};

	return (
		<ModalForm
			open={open}
			icon="fa-light fa-user-lock"
			title="Thêm chức vụ"
			subtitle="Tạo chức vụ mới để gán quyền ở màn Phân quyền"
			onCancel={handleCancel}
			onOk={handleSubmit(onSubmit)}
			okText="Thêm"
			loading={isSubmitting}
			width={460}
		>
			<Controller
				control={control}
				name="name"
				render={({field}) => (
					<InputField label="Tên chức vụ" placeholder="VD: Nhân viên kinh doanh" errors={errors} {...field} />
				)}
			/>
		</ModalForm>
	);
}

export default PermissionFormAdd;
