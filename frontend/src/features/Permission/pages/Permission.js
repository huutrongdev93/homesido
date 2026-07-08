import {useEffect, useMemo, useState} from "react";
import {App as AntdApp} from "antd";
import className from "classnames/bind";
import {roleApi} from "~/api";
import {apiError, handleRequest} from "~/utils";
import {Loading, FontAwesomeIcon, PageHeader} from "~/components";
import PermissionForm from "../components/PermissionForm";
import PermissionFormAdd from "../components/PermissionFormAdd";
import style from "../style/Permission.module.scss";

const cn = className.bind(style);

function Permission() {

	const {notification, modal} = AntdApp.useApp();

	const [loading, setLoading]         = useState(true);
	const [roles, setRoles]             = useState({});   // {key: name}
	const [groups, setGroups]           = useState({});   // {groupKey: {label, permission: {capKey: label}}}
	const [currentRole, setCurrentRole] = useState(null);
	const [openAdd, setOpenAdd]         = useState(false);

	useEffect(() => {
		(async () => {
			const [errRoles, resRoles] = await handleRequest(roleApi.gets());
			const [errPerm, resPerm]   = await handleRequest(roleApi.permissionList());

			const messageRoles = apiError('Tải danh sách chức vụ thất bại', errRoles, resRoles);
			const messagePerm  = apiError('Tải danh sách quyền thất bại', errPerm, resPerm);

			if (!messageRoles && !messagePerm) {
				const rolesData = resRoles.data || {};
				setRoles(rolesData);
				setGroups(resPerm.data || {});
				setCurrentRole(Object.keys(rolesData)[0] || null);
			}

			setLoading(false);
		})();
	}, []);

	const handleAdded = (role) => {
		setRoles(prev => ({...prev, [role.key]: role.name}));
		setCurrentRole(role.key);
		setOpenAdd(false);
		notification.success({title: 'Thành công', description: 'Đã thêm chức vụ mới'});
	};

	const handleDeleteRole = () => {
		if (!currentRole) return;

		modal.confirm({
			title: 'Xóa chức vụ',
			content: `Bạn có chắc muốn xóa chức vụ "${roles[currentRole] || currentRole}"? Hành động này không thể hoàn tác.`,
			okText: 'Xóa',
			okButtonProps: {danger: true},
			cancelText: 'Hủy',
			onOk: async () => {
				const [error, response] = await handleRequest(roleApi.delete(currentRole));

				if (apiError('Xóa chức vụ thất bại', error, response)) {
					return Promise.reject();
				}

				const deleted = currentRole;
				setRoles(prev => {
					const next = {...prev};
					delete next[deleted];
					setCurrentRole(Object.keys(next)[0] || null);
					return next;
				});
				notification.success({title: 'Thành công', description: 'Đã xóa chức vụ'});
			},
		});
	};

	const roleEntries = useMemo(() => Object.entries(roles), [roles]);

	if (loading) return <Loading />;

	return (
		<div className="container">
			<PageHeader
				icon="fa-light fa-user-lock"
				title="Phân quyền"
				subtitle="Quản lý quyền truy cập cho từng chức vụ trong hệ thống"
			/>

			<div className={cn('layout')}>
				{/* Danh sách chức vụ */}
				<aside className={cn('roles')}>
					<div className={cn('roles-head')}>
						<FontAwesomeIcon icon="fa-light fa-user-lock" />
						<span>Chức vụ</span>
						<span className={cn('roles-count')}>{roleEntries.length}</span>
					</div>

					<div className={cn('roles-list')}>
						{roleEntries.length === 0 && <p className={cn('empty')}>Chưa có chức vụ nào</p>}
						{roleEntries.map(([key, name]) => (
							<button
								type="button"
								key={key}
								className={cn('role-item', {active: key === currentRole})}
								onClick={() => setCurrentRole(key)}
							>
								<span className={cn('role-avatar')}>{(name || key).charAt(0).toUpperCase()}</span>
								<span className={cn('role-name')}>{name}</span>
								<FontAwesomeIcon icon="fa-light fa-chevron-right" className={cn('role-caret')} />
							</button>
						))}
					</div>

					<button type="button" className={cn('role-add')} onClick={() => setOpenAdd(true)}>
						<FontAwesomeIcon icon="fa-light fa-plus" />
						<span>Thêm chức vụ</span>
					</button>
				</aside>

				{/* Ma trận quyền */}
				<section className={cn('panel')}>
					{currentRole
						? <PermissionForm
							key={currentRole}
							role={currentRole}
							roleName={roles[currentRole]}
							groups={groups}
							onDelete={handleDeleteRole}
						/>
						: <div className={cn('panel-empty')}>
							<FontAwesomeIcon icon="fa-light fa-user-lock" />
							<p>Chọn một chức vụ để phân quyền</p>
						</div>}
				</section>
			</div>

			<PermissionFormAdd open={openAdd} onCancel={() => setOpenAdd(false)} onAdded={handleAdded} />
		</div>
	);
}

export default Permission;
