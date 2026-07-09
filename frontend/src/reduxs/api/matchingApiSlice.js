import {apiSlice} from './apiSlice';

/**
 * Matching Khách ↔ BĐS (GĐ2) — RTK Query.
 *
 * Tính khớp on-the-fly ở BE (App\Services\Matching\MatchEngine); FE chỉ đọc gợi ý + gửi SP.
 * - getMatchOverview(): "Cơ hội của tôi" — mọi cặp khách-của-tôi ↔ BĐS khớp trong kho (màn hình đích
 *   của thông báo đẩy auto-matching); không cần chọn tay từng khách.
 * - getSuggestedProperties(customerId): BĐS khớp nhu cầu 1 khách (kèm score + reasons + already_sent).
 * - getMatchingCustomers(propertyId): khách khớp 1 BĐS.
 * - getCustomerMatches(customerId): lịch sử SP đã gửi cho khách.
 * - sendPropertyToCustomer: "gửi SP cho khách" → ghi lịch sử + 1 tương tác vào timeline.
 *   invalidate ['Match','Interaction','Customer'] để drawer/timeline/list tự refetch.
 */
export const matchingApiSlice = apiSlice.injectEndpoints({
	endpoints: (builder) => ({
		getMatchOverview: builder.query({
			query: () => ({url: 'customer/match-overview', method: 'get'}),
			transformResponse: (body) => body?.data || [],
			providesTags: ['Match'],
		}),
		getSuggestedProperties: builder.query({
			// arg: customerId (số) hoặc {customerId, demandId} để lọc theo 1 nhu cầu.
			query: (arg) => {
				const customerId = typeof arg === 'object' ? arg.customerId : arg;
				const demandId = typeof arg === 'object' ? arg.demandId : undefined;
				return {
					url: `customer/${customerId}/match-properties`,
					method: 'get',
					params: demandId ? {demand_id: demandId} : undefined,
				};
			},
			transformResponse: (body) => body?.data || [],
			providesTags: ['Match'],
		}),
		getMatchingCustomers: builder.query({
			query: (propertyId) => ({url: `property/${propertyId}/match-customers`, method: 'get'}),
			transformResponse: (body) => body?.data || [],
			providesTags: ['Match'],
		}),
		getCustomerMatches: builder.query({
			query: (customerId) => ({url: `customer/${customerId}/matches`, method: 'get'}),
			transformResponse: (body) => body?.data || [],
			providesTags: ['Match'],
		}),
		sendPropertyToCustomer: builder.mutation({
			query: ({customerId, propertyId, demand_id, note}) => ({
				url: `customer/${customerId}/matches`,
				method: 'post',
				data: {property_id: propertyId, demand_id, note},
			}),
			invalidatesTags: ['Match', 'Interaction', 'Customer'],
		}),
		updateMatchStatus: builder.mutation({
			query: ({customerId, matchId, status, note}) => ({
				url: `customer/${customerId}/matches/${matchId}`,
				method: 'put',
				data: {status, note},
			}),
			invalidatesTags: ['Match'],
		}),
	}),
});

export const {
	useGetMatchOverviewQuery,
	useGetSuggestedPropertiesQuery,
	useGetMatchingCustomersQuery,
	useGetCustomerMatchesQuery,
	useSendPropertyToCustomerMutation,
	useUpdateMatchStatusMutation,
} = matchingApiSlice;
