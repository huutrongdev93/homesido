import {apiSlice} from './apiSlice';

/**
 * CRUD Khách hàng (core CRM) — RTK Query (chuẩn cho danh mục/CRUD server-state).
 *
 * - getCustomers: danh sách có filter + phân trang server-side. Trả {items, total, page, pageSize}.
 *   params: {page, pageSize, keyword, pipeline_stage, temperature, assigned_user_id}.
 * - Các mutation invalidatesTags ['Customer'] → list tự refetch, không phải tự cập nhật state.
 */
export const customerApiSlice = apiSlice.injectEndpoints({
	endpoints: (builder) => ({
		getCustomers: builder.query({
			query: (params) => ({url: 'customer', method: 'get', params}),
			transformResponse: (body) => body?.data || {items: [], total: 0, page: 1, pageSize: 20},
			providesTags: ['Customer'],
		}),
		getCustomer: builder.query({
			query: (id) => ({url: `customer/${id}`, method: 'get'}),
			transformResponse: (body) => body?.data || null,
			providesTags: ['Customer'],
		}),
		addCustomer: builder.mutation({
			query: (data) => ({url: 'customer', method: 'post', data}),
			invalidatesTags: ['Customer'],
		}),
		updateCustomer: builder.mutation({
			query: ({id, ...data}) => ({url: `customer/${id}`, method: 'put', data}),
			invalidatesTags: ['Customer'],
		}),
		deleteCustomer: builder.mutation({
			query: (id) => ({url: `customer/${id}`, method: 'delete'}),
			invalidatesTags: ['Customer'],
		}),
		// Danh sách nhân viên có thể nhận bàn giao (cho select trong modal bàn giao).
		getAssignableUsers: builder.query({
			query: () => ({url: 'customer/users', method: 'get'}),
			transformResponse: (body) => body?.data || [],
		}),
		// Bàn giao khách cho nhân viên khác — invalidate 'Customer' để list/drawer refetch.
		transferCustomer: builder.mutation({
			query: ({id, ...data}) => ({url: `customer/${id}/transfer`, method: 'post', data}),
			invalidatesTags: ['Customer'],
		}),

		// Nhu cầu / tiêu chí của khách (cho Matching GĐ2) — CRUD trong drawer chi tiết.
		getCustomerDemands: builder.query({
			query: (customerId) => ({url: `customer/${customerId}/demands`, method: 'get'}),
			transformResponse: (body) => body?.data || [],
			providesTags: ['Demand'],
		}),
		addDemand: builder.mutation({
			query: ({customerId, ...data}) => ({url: `customer/${customerId}/demands`, method: 'post', data}),
			invalidatesTags: ['Demand'],
		}),
		updateDemand: builder.mutation({
			query: ({customerId, id, ...data}) => ({url: `customer/${customerId}/demands/${id}`, method: 'put', data}),
			invalidatesTags: ['Demand'],
		}),
		deleteDemand: builder.mutation({
			query: ({customerId, id}) => ({url: `customer/${customerId}/demands/${id}`, method: 'delete'}),
			invalidatesTags: ['Demand'],
		}),
	}),
});

export const {
	useGetCustomersQuery,
	useGetCustomerQuery,
	useAddCustomerMutation,
	useUpdateCustomerMutation,
	useDeleteCustomerMutation,
	useGetAssignableUsersQuery,
	useTransferCustomerMutation,
	useGetCustomerDemandsQuery,
	useAddDemandMutation,
	useUpdateDemandMutation,
	useDeleteDemandMutation,
} = customerApiSlice;
