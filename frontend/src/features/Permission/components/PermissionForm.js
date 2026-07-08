import {useEffect, useMemo, useState} from "react";
import {Controller, useForm} from "react-hook-form";
import {App as AntdApp, Switch, Checkbox} from "antd";
import className from "classnames/bind";
import {roleApi} from "~/api";
import {apiError, handleRequest} from "~/utils";
import {Loading, FontAwesomeIcon, Button} from "~/components";
import style from "../style/PermissionForm.module.scss";

const cn = className.bind(style);

/**
 * Ma trận quyền của 1 chức vụ.
 * - Tải quyền hiện tại của chức vụ khi đổi `role`.
 * - Mỗi capability là 1 Switch (react-hook-form Controller).
 * - Có chọn tất cả theo nhóm + toàn bộ, và đếm số quyền đang bật.
 */
function PermissionForm({role, roleName, groups, onDelete}) {

	const {notification} = AntdApp.useApp();

	const [loading, setLoading] = useState(true);

	const {control, handleSubmit, reset, watch, setValue, formState: {isSubmitting}} = useForm({defaultValues: {}});

	const values = watch();

	const allCaps = useMemo(
		() => Object.values(groups).flatMap(group => Object.keys(group.permission)),
		[groups]
	);

	const grantedCount = allCaps.filter(cap => values[cap]).length;

	useEffect(() => {
		(async () => {
			setLoading(true);
			const [error, response] = await handleRequest(roleApi.permissionByRole(role));
			if (!apiError('Tải quyền của chức vụ thất bại', error, response)) {
				reset({role, ...(response.data?.capabilities || {})});
			}
			setLoading(false);
		})();
	}, [role, reset]);

	const setMany = (caps, value) => caps.forEach(cap => setValue(cap, value, {shouldDirty: true}));

	const onSubmit = async (data) => {
		const {role: _role, ...capabilities} = data;
		const [error, response] = await handleRequest(roleApi.permissionUpdate({role, capabilities}));
		if (!apiError('Cập nhật phân quyền thất bại', error, response)) {
			notification.success({title: 'Thành công', description: 'Cập nhật phân quyền thành công'});
		}
	};

	if (loading) return <Loading />;

	return (
		<form onSubmit={handleSubmit(onSubmit)} className={cn('form')}>
			{/* Thanh công cụ */}
			<header className={cn('toolbar')}>
				<div className={cn('toolbar-info')}>
					<h3 className={cn('role-title')}>{roleName || role}</h3>
					<span className={cn('role-meta')}>
						<FontAwesomeIcon icon="fa-light fa-key" /> {grantedCount}/{allCaps.length} quyền
					</span>
				</div>
				<div className={cn('toolbar-actions')}>
					{onDelete && (
						<Button
							red
							outline
							onClick={onDelete}
							leftIcon={<FontAwesomeIcon icon="fa-light fa-trash" />}
						>
							Xóa chức vụ
						</Button>
					)}
					<Button outline onClick={() => setMany(allCaps, true)}>Chọn tất cả</Button>
					<Button outline onClick={() => setMany(allCaps, false)}>Bỏ chọn</Button>
					<Button
						primary
						type="submit"
						loading={isSubmitting}
						leftIcon={<FontAwesomeIcon icon="fa-light fa-floppy-disk" />}
					>
						Lưu phân quyền
					</Button>
				</div>
			</header>

			{/* Lưới nhóm quyền */}
			<div className={cn('groups')}>
				{Object.entries(groups).map(([groupKey, group]) => {
					const caps  = Object.keys(group.permission);
					const on    = caps.filter(cap => values[cap]).length;
					const allOn = caps.length > 0 && on === caps.length;
					const some  = on > 0 && !allOn;

					return (
						<div className={cn('group')} key={groupKey}>
							<div className={cn('group-head')}>
								<div className={cn('group-titlewrap')}>
									<span className={cn('group-title')}>{group.label}</span>
									<span className={cn('group-count', {full: allOn})}>{on}/{caps.length}</span>
								</div>
								<Checkbox
									checked={allOn}
									indeterminate={some}
									onChange={(e) => setMany(caps, e.target.checked)}
								>
									Tất cả
								</Checkbox>
							</div>

							<div className={cn('caps')}>
								{Object.entries(group.permission).map(([capKey, capLabel]) => (
									<Controller
										key={capKey}
										control={control}
										name={capKey}
										render={({field}) => (
											<label className={cn('cap', {on: !!field.value})}>
												<span className={cn('cap-label')}>{capLabel}</span>
												<Switch
													size="small"
													checked={!!field.value}
													onChange={(checked) => field.onChange(checked)}
												/>
											</label>
										)}
									/>
								))}
							</div>
						</div>
					);
				})}
			</div>
		</form>
	);
}

export default PermissionForm;
