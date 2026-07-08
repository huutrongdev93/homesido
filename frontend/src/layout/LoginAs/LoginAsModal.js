import {useCallback, useEffect, useState} from "react";
import {App as AntdApp, Modal, Input, Spin, Empty, Tag} from "antd";
import className from "classnames/bind";
import {Image} from "~/components";
import {authApi} from "~/api";
import {handleRequest, resolveUserAvatar, enterLoginAs, getLoginAsUser} from "~/utils";
import style from "./LoginAs.module.scss";

const cn = className.bind(style);

/**
 * Modal chọn tài khoản để "đăng nhập vào" (impersonation). Dùng cho cả lần đầu lẫn
 * chuyển đổi khi đang mạo danh — luôn lấy danh sách từ quyền của TÀI KHOẢN GỐC.
 */
function LoginAsModal({open, onClose}) {

	const {notification} = AntdApp.useApp();

	const [keyword, setKeyword]       = useState('');
	const [search, setSearch]         = useState('');
	const [items, setItems]           = useState([]);
	const [loading, setLoading]       = useState(false);
	const [submittingId, setSubmittingId] = useState(null);

	const current = getLoginAsUser();

	// Debounce ô tìm kiếm
	useEffect(() => {
		const timer = setTimeout(() => setSearch(keyword.trim()), 400);
		return () => clearTimeout(timer);
	}, [keyword]);

	const fetchCandidates = useCallback(async (kw) => {
		setLoading(true);
		const [error, res] = await handleRequest(authApi.loginAsCandidates(kw));
		setLoading(false);

		if (error) {
			setItems([]);
			notification.error({
				title: 'Lỗi',
				description: error?.response?.data?.message || 'Không tải được danh sách tài khoản',
			});
			return;
		}

		setItems(res?.data || []);
	}, [notification]);

	useEffect(() => {
		if (open) fetchCandidates(search);
	}, [open, search, fetchCandidates]);

	// Reset khi đóng
	useEffect(() => {
		if (!open) { setKeyword(''); setSearch(''); }
	}, [open]);

	const handleSelect = async (user) => {
		if (submittingId) return;

		setSubmittingId(user.id);
		const [error, res] = await handleRequest(authApi.loginAs(user.id));
		setSubmittingId(null);

		if (error) {
			notification.error({
				title: 'Đăng nhập tài khoản thất bại',
				description: error?.response?.data?.message || 'Vui lòng thử lại',
			});
			return;
		}

		// Body từ response()->success(...): accessToken nằm ở TOP-LEVEL (helper spread $data
		// khi có key 'data'); user/permissions nằm trong res.data.
		const token      = res?.accessToken;
		const targetUser = res?.data?.user;

		if (!token || !targetUser) {
			notification.error({title: 'Lỗi', description: 'Phản hồi không hợp lệ từ máy chủ'});
			return;
		}

		// Giữ lại tên chức vụ từ danh sách ứng viên (payload user của BE chỉ có role slug)
		// để banner hiển thị đúng nhãn.
		if (user.role_name && !targetUser.role_name) {
			targetUser.role_name = user.role_name;
		}

		// enterLoginAs sẽ reload trang → AppProvider nạp lại danh tính mới.
		enterLoginAs(token, targetUser);
	};

	return (
		<Modal
			title="Đăng nhập vào tài khoản khác"
			open={open}
			onCancel={onClose}
			footer={null}
			width={520}
			destroyOnHidden
		>
			<Input.Search
				placeholder="Tìm theo tên, tài khoản, email, số điện thoại…"
				allowClear
				value={keyword}
				onChange={(e) => setKeyword(e.target.value)}
				className={cn('search')}
			/>

			<div className={cn('list')}>
				{loading ? (
					<div className={cn('center')}><Spin /></div>
				) : items.length === 0 ? (
					<Empty description="Không có tài khoản phù hợp" />
				) : (
					items.map((user) => {
						const roleName = user.role_name || user.role;
						const isCurrent = current && current.id === user.id;

						return (
							<button
								key={user.id}
								type="button"
								className={cn('item', {active: isCurrent})}
								disabled={Boolean(submittingId)}
								onClick={() => handleSelect(user)}
							>
								<span className={cn('avatar')}>
									<Image src={resolveUserAvatar(user)} alt={user.fullname} />
								</span>
								<span className={cn('info')}>
									<span className={cn('name')}>{user.fullname || user.username}</span>
									<span className={cn('sub')}>@{user.username}{user.email ? ` · ${user.email}` : ''}</span>
								</span>
								<span className={cn('meta')}>
									{roleName && <Tag color="geekblue">{roleName}</Tag>}
									{user.status === 'block' && <Tag color="red">Đã khóa</Tag>}
									{submittingId === user.id && <Spin size="small" />}
								</span>
							</button>
						);
					})
				)}
			</div>
		</Modal>
	);
}

export default LoginAsModal;
