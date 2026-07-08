import {apiSlice} from './apiSlice';

/**
 * Chăm sóc chủ động (care_schedules) + timeline tương tác của khách.
 *
 * - getCareToday: việc cần chăm đến hạn hôm nay (gồm quá hạn) — trang "Cần chăm hôm nay".
 * - getCustomerCares: lịch chăm của 1 khách (drawer chi tiết).
 * - getCustomerInteractions: timeline tương tác của 1 khách.
 * - completeCare/addInteraction đụng tới last_interaction_at + tạo tương tác → invalidate cả
 *   'Care', 'Interaction', 'Customer' để mọi nơi refetch.
 */
export const careApiSlice = apiSlice.injectEndpoints({
	endpoints: (builder) => ({
		getCareToday: builder.query({
			query: () => ({url: 'care/today', method: 'get'}),
			transformResponse: (body) => body?.data || [],
			providesTags: ['Care'],
		}),
		getCustomerCares: builder.query({
			query: (customerId) => ({url: 'care', method: 'get', params: {customer_id: customerId}}),
			transformResponse: (body) => body?.data || [],
			providesTags: ['Care'],
		}),
		addCare: builder.mutation({
			query: (data) => ({url: 'care', method: 'post', data}),
			invalidatesTags: ['Care'],
		}),
		completeCare: builder.mutation({
			query: ({id, ...data}) => ({url: `care/${id}/complete`, method: 'put', data}),
			invalidatesTags: ['Care', 'Interaction', 'Customer'],
		}),
		cancelCare: builder.mutation({
			query: (id) => ({url: `care/${id}`, method: 'delete'}),
			invalidatesTags: ['Care'],
		}),
		getCustomerInteractions: builder.query({
			query: (customerId) => ({url: `customer/${customerId}/interactions`, method: 'get'}),
			transformResponse: (body) => body?.data || [],
			providesTags: ['Interaction'],
		}),
		addInteraction: builder.mutation({
			query: ({customerId, ...data}) => ({url: `customer/${customerId}/interactions`, method: 'post', data}),
			invalidatesTags: ['Interaction', 'Customer'],
		}),
	}),
});

export const {
	useGetCareTodayQuery,
	useGetCustomerCaresQuery,
	useAddCareMutation,
	useCompleteCareMutation,
	useCancelCareMutation,
	useGetCustomerInteractionsQuery,
	useAddInteractionMutation,
} = careApiSlice;
