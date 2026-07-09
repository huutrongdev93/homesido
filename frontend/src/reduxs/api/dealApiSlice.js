import {apiSlice} from './apiSlice';

/**
 * Giao dịch (deals) + đợt thanh toán + hoa hồng — GĐ2.
 *
 * detail trả kèm `payments[]` + `commission` + `paid_total`/`remaining`, nên mọi mutation con
 * (payment/commission/status) chỉ cần invalidate 'Deal' để detail + list refetch. changeStatus/destroy
 * đụng tới `properties.status` → invalidate thêm 'Property'.
 */
export const dealApiSlice = apiSlice.injectEndpoints({
	endpoints: (builder) => ({
		getDeals: builder.query({
			query: (params) => ({url: 'deal', method: 'get', params}),
			transformResponse: (body) => body?.data || {items: [], total: 0},
			providesTags: ['Deal'],
		}),
		getDeal: builder.query({
			query: (id) => ({url: `deal/${id}`, method: 'get'}),
			transformResponse: (body) => body?.data || null,
			providesTags: ['Deal'],
		}),
		addDeal: builder.mutation({
			query: (data) => ({url: 'deal', method: 'post', data}),
			invalidatesTags: ['Deal', 'Property'],
		}),
		updateDeal: builder.mutation({
			query: ({id, ...data}) => ({url: `deal/${id}`, method: 'put', data}),
			invalidatesTags: ['Deal'],
		}),
		changeDealStatus: builder.mutation({
			query: ({id, status}) => ({url: `deal/${id}/status`, method: 'put', data: {status}}),
			invalidatesTags: ['Deal', 'Property'],
		}),
		deleteDeal: builder.mutation({
			query: (id) => ({url: `deal/${id}`, method: 'delete'}),
			invalidatesTags: ['Deal', 'Property'],
		}),
		// Đợt thanh toán (đã thu / dự kiến — payload kèm status, due_date khi dự kiến)
		addDealPayment: builder.mutation({
			query: ({dealId, ...data}) => ({url: `deal/${dealId}/payments`, method: 'post', data}),
			invalidatesTags: ['Deal'],
		}),
		markDealPaymentPaid: builder.mutation({
			query: ({dealId, id}) => ({url: `deal/${dealId}/payments/${id}/paid`, method: 'put'}),
			invalidatesTags: ['Deal'],
		}),
		deleteDealPayment: builder.mutation({
			query: ({dealId, id}) => ({url: `deal/${dealId}/payments/${id}`, method: 'delete'}),
			invalidatesTags: ['Deal'],
		}),
		// Nhắc hẹn
		addDealReminder: builder.mutation({
			query: ({dealId, ...data}) => ({url: `deal/${dealId}/reminders`, method: 'post', data}),
			invalidatesTags: ['Deal'],
		}),
		updateDealReminder: builder.mutation({
			query: ({dealId, id, ...data}) => ({url: `deal/${dealId}/reminders/${id}`, method: 'put', data}),
			invalidatesTags: ['Deal'],
		}),
		deleteDealReminder: builder.mutation({
			query: ({dealId, id}) => ({url: `deal/${dealId}/reminders/${id}`, method: 'delete'}),
			invalidatesTags: ['Deal'],
		}),
		// Hoa hồng (mark chi/chưa chi)
		updateDealCommission: builder.mutation({
			query: ({id, ...data}) => ({url: `deal/${id}/commission`, method: 'put', data}),
			invalidatesTags: ['Deal'],
		}),
	}),
});

export const {
	useGetDealsQuery,
	useGetDealQuery,
	useAddDealMutation,
	useUpdateDealMutation,
	useChangeDealStatusMutation,
	useDeleteDealMutation,
	useAddDealPaymentMutation,
	useMarkDealPaymentPaidMutation,
	useDeleteDealPaymentMutation,
	useAddDealReminderMutation,
	useUpdateDealReminderMutation,
	useDeleteDealReminderMutation,
	useUpdateDealCommissionMutation,
} = dealApiSlice;
