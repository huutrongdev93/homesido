import {apiSlice} from './apiSlice';

/**
 * Thông báo in-app — chuông ở sidebar.
 *
 * - getNotifications: danh sách mới nhất + số chưa đọc. Component chuông poll 60s
 *   (skipPollingIfUnfocused) để badge tự cập nhật khi tiến trình nền hoàn tất.
 * - markNotificationsRead: {id} = đọc 1 cái, không id = đọc tất cả → invalidate để badge cập nhật.
 *
 * Web Push (thông báo đẩy trên thiết bị qua service worker):
 * - getPushConfig: server đã cấu hình VAPID chưa + khoá public cho PushManager.subscribe.
 * - subscribePushDevice / unsubscribePushDevice: đồng bộ subscription của thiết bị với BE.
 */
export const notificationApiSlice = apiSlice.injectEndpoints({
	endpoints: (builder) => ({
		getNotifications: builder.query({
			query: () => ({url: 'notifications', method: 'get'}),
			transformResponse: (body) => body?.data || {items: [], unread: 0},
			providesTags: ['Notification'],
		}),
		markNotificationsRead: builder.mutation({
			query: (id) => ({url: 'notifications/read', method: 'post', data: id ? {id} : {}}),
			invalidatesTags: ['Notification'],
		}),
		getPushConfig: builder.query({
			query: () => ({url: 'notifications/push/config', method: 'get'}),
			transformResponse: (body) => body?.data || {enabled: false, publicKey: ''},
		}),
		subscribePushDevice: builder.mutation({
			query: (subscription) => ({url: 'notifications/push/subscribe', method: 'post', data: subscription}),
		}),
		unsubscribePushDevice: builder.mutation({
			query: (subscription) => ({url: 'notifications/push/unsubscribe', method: 'post', data: subscription}),
		}),
	}),
});

export const {
	useGetNotificationsQuery,
	useMarkNotificationsReadMutation,
	useGetPushConfigQuery,
	useSubscribePushDeviceMutation,
	useUnsubscribePushDeviceMutation,
} = notificationApiSlice;
