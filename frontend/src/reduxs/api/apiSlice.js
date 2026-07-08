import {createApi} from '@reduxjs/toolkit/query/react';
import request from '~/utils/http';

/**
 * baseQuery bọc axios instance `request` (utils/http) để RTK Query dùng chung
 * interceptor sẵn có: tự gắn Bearer token + tự refresh khi 401.
 *
 * `request` đã trả về body (response.data) nhờ interceptor, nên ở đây result
 * chính là body API: {status, code, message, data}.
 */
const axiosBaseQuery = () => async ({url, method = 'get', data, params, headers}) => {
	try {
		// `headers` cho phép endpoint gửi multipart (FormData) — ghi đè Content-Type mặc định JSON.
		const body = await request({url, method, data, params, ...(headers ? {headers} : {})});
		// BE có thể trả HTTP 200 nhưng body báo lỗi nghiệp vụ (response()->error() chỉ set code trong body,
		// không đổi HTTP status). Coi đó là lỗi để mutation .unwrap() ném đúng.
		if (body && body.status === 'error') {
			return {error: {status: body.code || 400, data: body}};
		}
		return {data: body};
	} catch (error) {
		return {
			error: {
				status: error?.response?.status,
				data: error?.response?.data || {message: error?.message},
			},
		};
	}
};

/**
 * Base API service. Mỗi feature tự inject endpoint của mình qua
 * `apiSlice.injectEndpoints(...)` (xem reduxs/api/notificationApiSlice.js).
 *
 * tagTypes khai báo tập trung ở đây để các endpoint dùng cache-invalidation.
 * Module nghiệp vụ mới tự bổ sung tag của mình vào mảng này.
 */
export const apiSlice = createApi({
	reducerPath: 'api',
	baseQuery: axiosBaseQuery(),
	tagTypes: ['Notification', 'Customer', 'Property', 'Care', 'Interaction', 'Demand', 'LeadSource', 'Project', 'PropertyOwner', 'CareTemplate', 'PropertyMedia', 'Storage'],
	endpoints: () => ({}),
});

/** Lấy message lỗi từ shape lỗi của RTK Query (baseQuery ở trên). */
export const rtkErrorMessage = (error, fallback = 'Có lỗi xảy ra, vui lòng thử lại') =>
	error?.data?.message || fallback;
