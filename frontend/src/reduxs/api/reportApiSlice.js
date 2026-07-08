import {apiSlice} from './apiSlice';

/**
 * Báo cáo (GĐ2) — tổng hợp read-only. 1 query getReport(params) trả toàn bộ mục (funnel/sources/sales/team).
 * Xuất Excel không qua RTK (blob) — xem ~/api/reportFileApi.
 */
export const reportApiSlice = apiSlice.injectEndpoints({
	endpoints: (builder) => ({
		getReport: builder.query({
			query: (params) => ({url: 'report', method: 'get', params}),
			transformResponse: (body) => body?.data || null,
			providesTags: ['Report'],
		}),
	}),
});

export const {useGetReportQuery} = reportApiSlice;
