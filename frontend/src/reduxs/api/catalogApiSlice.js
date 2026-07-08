import {apiSlice} from './apiSlice';

/**
 * Danh mục phụ (cấu hình) — nguồn khách / dự án / chủ nhà / kịch bản chăm sóc.
 *
 * Mỗi danh mục: list (nạp dropdown ở form + bảng quản lý) + add/update/delete (chỉ admin).
 * List trả mảng phẳng (danh mục nhỏ, không phân trang). Mutation invalidate tag riêng để
 * bảng quản lý + dropdown ở form tự refetch. Endpoint đọc mở cho view cap; ghi gate `permission` ở BE.
 */
export const catalogApiSlice = apiSlice.injectEndpoints({
	endpoints: (builder) => ({
		// ── Nguồn khách ──
		getLeadSources: builder.query({
			query: () => ({url: 'lead-source', method: 'get'}),
			transformResponse: (body) => body?.data || [],
			providesTags: ['LeadSource'],
		}),
		addLeadSource: builder.mutation({
			query: (data) => ({url: 'lead-source', method: 'post', data}),
			invalidatesTags: ['LeadSource'],
		}),
		updateLeadSource: builder.mutation({
			query: ({id, ...data}) => ({url: `lead-source/${id}`, method: 'put', data}),
			invalidatesTags: ['LeadSource'],
		}),
		deleteLeadSource: builder.mutation({
			query: (id) => ({url: `lead-source/${id}`, method: 'delete'}),
			invalidatesTags: ['LeadSource'],
		}),

		// ── Dự án ──
		getProjects: builder.query({
			query: () => ({url: 'project', method: 'get'}),
			transformResponse: (body) => body?.data || [],
			providesTags: ['Project'],
		}),
		addProject: builder.mutation({
			query: (data) => ({url: 'project', method: 'post', data}),
			invalidatesTags: ['Project'],
		}),
		updateProject: builder.mutation({
			query: ({id, ...data}) => ({url: `project/${id}`, method: 'put', data}),
			invalidatesTags: ['Project'],
		}),
		deleteProject: builder.mutation({
			query: (id) => ({url: `project/${id}`, method: 'delete'}),
			invalidatesTags: ['Project'],
		}),

		// ── Chủ nhà ──
		getPropertyOwners: builder.query({
			query: () => ({url: 'property-owner', method: 'get'}),
			transformResponse: (body) => body?.data || [],
			providesTags: ['PropertyOwner'],
		}),
		addPropertyOwner: builder.mutation({
			query: (data) => ({url: 'property-owner', method: 'post', data}),
			invalidatesTags: ['PropertyOwner'],
		}),
		updatePropertyOwner: builder.mutation({
			query: ({id, ...data}) => ({url: `property-owner/${id}`, method: 'put', data}),
			invalidatesTags: ['PropertyOwner'],
		}),
		deletePropertyOwner: builder.mutation({
			query: (id) => ({url: `property-owner/${id}`, method: 'delete'}),
			invalidatesTags: ['PropertyOwner'],
		}),

		// ── Kịch bản chăm sóc ──
		getCareTemplates: builder.query({
			query: () => ({url: 'care-template', method: 'get'}),
			transformResponse: (body) => body?.data || [],
			providesTags: ['CareTemplate'],
		}),
		addCareTemplate: builder.mutation({
			query: (data) => ({url: 'care-template', method: 'post', data}),
			invalidatesTags: ['CareTemplate'],
		}),
		updateCareTemplate: builder.mutation({
			query: ({id, ...data}) => ({url: `care-template/${id}`, method: 'put', data}),
			invalidatesTags: ['CareTemplate'],
		}),
		deleteCareTemplate: builder.mutation({
			query: (id) => ({url: `care-template/${id}`, method: 'delete'}),
			invalidatesTags: ['CareTemplate'],
		}),
	}),
});

export const {
	useGetLeadSourcesQuery,
	useAddLeadSourceMutation,
	useUpdateLeadSourceMutation,
	useDeleteLeadSourceMutation,
	useGetProjectsQuery,
	useAddProjectMutation,
	useUpdateProjectMutation,
	useDeleteProjectMutation,
	useGetPropertyOwnersQuery,
	useAddPropertyOwnerMutation,
	useUpdatePropertyOwnerMutation,
	useDeletePropertyOwnerMutation,
	useGetCareTemplatesQuery,
	useAddCareTemplateMutation,
	useUpdateCareTemplateMutation,
	useDeleteCareTemplateMutation,
} = catalogApiSlice;
