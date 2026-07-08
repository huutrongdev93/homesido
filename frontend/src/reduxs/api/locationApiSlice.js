import {apiSlice} from './apiSlice';

/**
 * Địa giới hành chính (tỉnh → phường) — dữ liệu tham chiếu công khai (BE LocationApi/Location2).
 * Trả sẵn [{value, label}] để đổ thẳng vào SelectField. RTK Query cache theo tham số → tỉnh nạp
 * 1 lần, phường nạp theo từng province_id.
 */
export const locationApiSlice = apiSlice.injectEndpoints({
	endpoints: (builder) => ({
		getProvinces: builder.query({
			query: () => ({url: 'location/provinces', method: 'get'}),
			transformResponse: (body) => body?.data || [],
		}),
		getWards: builder.query({
			query: (provinceId) => ({url: 'location/wards', method: 'get', params: {province_id: provinceId}}),
			transformResponse: (body) => body?.data || [],
		}),
	}),
});

export const {useGetProvincesQuery, useGetWardsQuery} = locationApiSlice;
