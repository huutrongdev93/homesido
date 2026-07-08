import {apiSlice} from './apiSlice';

/**
 * Dashboard tổng hợp (trang chủ) — RTK Query. Gộp mọi số liệu trong 1 endpoint.
 * providesTags ['Customer','Care','Property'] → khi các module này đổi dữ liệu (thêm khách,
 * hoàn thành chăm sóc, sửa BĐS...) dashboard tự refetch.
 */
export const dashboardApiSlice = apiSlice.injectEndpoints({
	endpoints: (builder) => ({
		getDashboard: builder.query({
			query: () => ({url: 'dashboard', method: 'get'}),
			transformResponse: (body) => body?.data || null,
			providesTags: ['Customer', 'Care', 'Property'],
		}),
	}),
});

export const {useGetDashboardQuery} = dashboardApiSlice;
