import {apiSlice} from './apiSlice';

/**
 * CRUD Bất động sản (kho hàng) — RTK Query. Khuôn giống customerApiSlice.
 *
 * - getProperties: list filter + phân trang. params: {page,pageSize,keyword,property_type,transaction_type,status}.
 * - Mutation invalidatesTags ['Property'] → list tự refetch.
 */
export const propertyApiSlice = apiSlice.injectEndpoints({
	endpoints: (builder) => ({
		getProperties: builder.query({
			query: (params) => ({url: 'property', method: 'get', params}),
			transformResponse: (body) => body?.data || {items: [], total: 0, page: 1, pageSize: 20},
			providesTags: ['Property'],
		}),
		getProperty: builder.query({
			query: (id) => ({url: `property/${id}`, method: 'get'}),
			transformResponse: (body) => body?.data || null,
			providesTags: ['Property'],
		}),
		addProperty: builder.mutation({
			query: (data) => ({url: 'property', method: 'post', data}),
			invalidatesTags: ['Property'],
		}),
		updateProperty: builder.mutation({
			query: ({id, ...data}) => ({url: `property/${id}`, method: 'put', data}),
			invalidatesTags: ['Property'],
		}),
		deleteProperty: builder.mutation({
			query: (id) => ({url: `property/${id}`, method: 'delete'}),
			invalidatesTags: ['Property'],
		}),
	}),
});

export const {
	useGetPropertiesQuery,
	useGetPropertyQuery,
	useAddPropertyMutation,
	useUpdatePropertyMutation,
	useDeletePropertyMutation,
} = propertyApiSlice;
