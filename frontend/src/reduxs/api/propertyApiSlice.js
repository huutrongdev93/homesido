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

		// ── Media (ảnh/video/tài liệu) của 1 BĐS ──
		getPropertyMedia: builder.query({
			query: (id) => ({url: `property/${id}/media`, method: 'get'}),
			transformResponse: (body) => body?.data || [],
			providesTags: (result, error, id) => [{type: 'PropertyMedia', id}],
		}),
		// Upload nhiều file (FormData). Ghi đè Content-Type để axios set multipart boundary.
		uploadPropertyMedia: builder.mutation({
			query: ({id, formData}) => ({
				url: `property/${id}/media`, method: 'post', data: formData,
				headers: {'Content-Type': 'multipart/form-data'},
			}),
			invalidatesTags: (result, error, {id}) => [{type: 'PropertyMedia', id}, 'Storage'],
		}),
		deletePropertyMedia: builder.mutation({
			query: ({id, mediaId}) => ({url: `property/${id}/media/${mediaId}`, method: 'delete'}),
			invalidatesTags: (result, error, {id}) => [{type: 'PropertyMedia', id}, 'Storage'],
		}),
		reorderPropertyMedia: builder.mutation({
			query: ({id, order}) => ({url: `property/${id}/media/reorder`, method: 'put', data: {order}}),
			invalidatesTags: (result, error, {id}) => [{type: 'PropertyMedia', id}],
		}),

		// Dung lượng đã dùng của user hiện tại (cho gói theo dung lượng).
		getStorageUsage: builder.query({
			query: () => ({url: 'storage', method: 'get'}),
			transformResponse: (body) => body?.data || {used_bytes: 0, quota_bytes: 0},
			providesTags: ['Storage'],
		}),
	}),
});

export const {
	useGetPropertiesQuery,
	useGetPropertyQuery,
	useAddPropertyMutation,
	useUpdatePropertyMutation,
	useDeletePropertyMutation,
	useGetPropertyMediaQuery,
	useUploadPropertyMediaMutation,
	useDeletePropertyMediaMutation,
	useReorderPropertyMediaMutation,
	useGetStorageUsageQuery,
} = propertyApiSlice;
