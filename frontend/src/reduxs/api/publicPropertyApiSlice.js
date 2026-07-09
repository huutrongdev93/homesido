import {apiSlice} from './apiSlice';

/**
 * Trang công khai 1 BĐS (link gửi khách xem) — RTK Query, KHÔNG cần đăng nhập.
 *
 * Endpoint BE `api/public/property/{code}` nằm ngoài middleware jwt. Interceptor axios
 * (utils/http.js) chỉ gắn Bearer khi có token và chỉ refresh khi đang có token → khách
 * vãng lai (không token) gọi vẫn an toàn.
 */
export const publicPropertyApiSlice = apiSlice.injectEndpoints({
	endpoints: (builder) => ({
		getPublicProperty: builder.query({
			query: (code) => ({url: `public/property/${encodeURIComponent(code)}`, method: 'get'}),
			transformResponse: (body) => body?.data || null,
		}),
	}),
});

export const {useGetPublicPropertyQuery} = publicPropertyApiSlice;
