import {useEffect, useRef, useState} from "react";
import {useNavigate} from "react-router-dom";
import {App as AntdApp, Badge, Popover, Empty, Switch, Tooltip} from "antd";
import dayjs from "dayjs";
import className from "classnames/bind";
import {FontAwesomeIcon} from "~/components";
import {
	useGetNotificationsQuery,
	useMarkNotificationsReadMutation,
	useGetPushConfigQuery,
	useSubscribePushDeviceMutation,
	useUnsubscribePushDeviceMutation,
} from "~/reduxs/api/notificationApiSlice";
import {
	pushSupported,
	pushPermission,
	getCurrentSubscription,
	subscribePush,
	unsubscribePush,
} from "~/utils/pushNotifications";
import style from "./NotificationBell.module.scss";

const cn = className.bind(style);

/** Icon + màu theo loại sự kiện (module mới thêm loại của mình = thêm 1 dòng). */
const TYPE_META = {
	info:    {icon: 'fa-light fa-circle-info', color: '#0ea5e9'},
	success: {icon: 'fa-light fa-circle-check', color: '#16a34a'},
	warning: {icon: 'fa-light fa-triangle-exclamation', color: '#d97706'},
	error:   {icon: 'fa-light fa-circle-xmark', color: '#dc2626'},
};

/**
 * Chuông thông báo in-app ở sidebar: badge số chưa đọc + dropdown danh sách.
 * Poll 60s (dừng khi tab không focus) — tiến trình nền tự "lên tiếng" (qua Notifier)
 * mà user không phải tự đi kiểm tra. Kèm switch bật/tắt Web Push cho từng thiết bị.
 */
function NotificationBell({onNavigate}) {

	const navigate = useNavigate();
	const {notification} = AntdApp.useApp();
	const [open, setOpen] = useState(false);

	const {data} = useGetNotificationsQuery(undefined, {
		pollingInterval: 60000,
		skipPollingIfUnfocused: true,
	});
	const [markRead] = useMarkNotificationsReadMutation();

	// --- Thông báo đẩy trên thiết bị (Web Push qua service worker) ---
	// Chỉ hỏi server khi user mở panel; trình duyệt không hỗ trợ thì khỏi hỏi.
	const {data: pushConfig} = useGetPushConfigQuery(undefined, {skip: !open || !pushSupported()});
	const [subscribeDevice] = useSubscribePushDeviceMutation();
	const [unsubscribeDevice] = useUnsubscribePushDeviceMutation();
	const [pushOn, setPushOn] = useState(false);
	const [pushBusy, setPushBusy] = useState(false);
	const pushSynced = useRef(false);

	useEffect(() => {
		if (!open || !pushSupported()) return;
		getCurrentSubscription().then((sub) => {
			setPushOn(!!sub);
			// Subscription của trình duyệt có thể đang gắn với TÀI KHOẢN KHÁC trên server
			// (cùng máy đăng nhập nhiều tài khoản — server upsert theo endpoint). Re-sync
			// để gán lại thiết bị cho user hiện tại, không thì switch hiện BẬT mà user
			// này không nhận được push nào. Fire-and-forget, 1 lần/phiên.
			if (sub && !pushSynced.current) {
				pushSynced.current = true;
				subscribeDevice(sub.toJSON()).catch(() => {});
			}
		});
	}, [open, subscribeDevice]);

	const togglePush = async (checked) => {
		setPushBusy(true);
		try {
			if (checked) {
				const subscription = await subscribePush(pushConfig?.publicKey);
				await subscribeDevice(subscription).unwrap();
				setPushOn(true);
				notification.success({
					title: 'Đã bật thông báo trên thiết bị này',
					description: 'Bạn sẽ nhận thông báo ngay cả khi không mở trang.',
				});
			} else {
				const subscription = await unsubscribePush();
				if (subscription) await unsubscribeDevice({endpoint: subscription.endpoint}).unwrap();
				setPushOn(false);
			}
		} catch (e) {
			notification.error({
				title: 'Không đổi được cài đặt thông báo',
				description: e?.message || e?.data?.message || 'Có lỗi xảy ra, thử lại sau.',
			});
		}
		setPushBusy(false);
	};

	const items = data?.items || [];
	const unread = data?.unread || 0;

	const openItem = (item) => {
		if (!item.is_read) markRead(item.id);
		setOpen(false);
		if (item.link) {
			navigate(item.link);
			onNavigate && onNavigate();
		}
	};

	const content = (
		<div className={cn('panel')}>
			<div className={cn('head')}>
				<span className={cn('headTitle')}>Thông báo</span>
				{unread > 0 && (
					<button type="button" className={cn('readAll')} onClick={() => markRead()}>
						<FontAwesomeIcon icon="fa-light fa-check-double" /> Đọc tất cả
					</button>
				)}
			</div>

			{pushSupported() && pushConfig?.enabled && (
				<div className={cn('pushRow')}>
					<span className={cn('pushLabel')}>
						<FontAwesomeIcon icon="fa-light fa-laptop-mobile" />
						Thông báo trên thiết bị này
						<Tooltip title="Hiện thông báo của hệ điều hành (máy tính/điện thoại) kể cả khi không mở trang. Bật riêng cho từng thiết bị.">
							<span className={cn('pushHint')}>
								<FontAwesomeIcon icon="fa-light fa-circle-info" />
							</span>
						</Tooltip>
					</span>
					<Tooltip title={pushPermission() === 'denied' && !pushOn
						? 'Trình duyệt đang chặn thông báo — mở cài đặt trang (ổ khoá cạnh thanh địa chỉ) và cho phép Thông báo.'
						: ''}>
						<Switch
							size="small"
							checked={pushOn}
							loading={pushBusy}
							disabled={pushPermission() === 'denied' && !pushOn}
							onChange={togglePush}
						/>
					</Tooltip>
				</div>
			)}

			<div className={cn('list')}>
				{items.length === 0 ? (
					<Empty image={Empty.PRESENTED_IMAGE_SIMPLE}
						description="Chưa có thông báo nào" style={{padding: '18px 0'}} />
				) : (
					items.map((item) => {
						const meta = TYPE_META[item.type] || {icon: 'fa-light fa-bell', color: '#6b7280'};
						return (
							<button key={item.id} type="button"
								className={cn('item', {unread: !item.is_read, clickable: !!item.link})}
								onClick={() => openItem(item)}>
								<span className={cn('itemIcon')} style={{color: meta.color}}>
									<FontAwesomeIcon icon={meta.icon} />
								</span>
								<span className={cn('itemBody')}>
									<span className={cn('itemTitle')}>{item.title}</span>
									{item.message && <span className={cn('itemMsg')}>{item.message}</span>}
									<span className={cn('itemTime')}>
										{item.created ? dayjs(item.created).format('HH:mm DD/MM/YYYY') : ''}
									</span>
								</span>
								{!item.is_read && <span className={cn('dot')} />}
							</button>
						);
					})
				)}
			</div>
		</div>
	);

	return (
		<Popover
			content={content}
			trigger="click"
			placement="bottomRight"
			open={open}
			onOpenChange={setOpen}
			overlayInnerStyle={{padding: 0}}
		>
			<button type="button" className={cn('bell')} aria-label="Thông báo">
				<Badge count={unread} size="small" overflowCount={99}>
					<FontAwesomeIcon icon="fa-light fa-bell" />
				</Badge>
			</button>
		</Popover>
	);
}

export default NotificationBell;
