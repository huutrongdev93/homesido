import {apiSlice} from './apiSlice';

/**
 * Lịch hẹn dẫn khách (appointments) — GĐ2.
 *
 * - getAppointments: danh sách phân trang + lọc (status/customer_id/property_id/from/to).
 * - complete: chốt buổi hẹn (done + kết quả → tạo tương tác timeline) hoặc no_show → invalidate
 *   'Appointment','Interaction','Customer' để timeline + điểm khách refetch.
 * - cancel (DELETE) → chuyển status=canceled.
 */
export const appointmentApiSlice = apiSlice.injectEndpoints({
	endpoints: (builder) => ({
		getAppointments: builder.query({
			query: (params) => ({url: 'appointment', method: 'get', params}),
			transformResponse: (body) => body?.data || {items: [], total: 0},
			providesTags: ['Appointment'],
		}),
		addAppointment: builder.mutation({
			query: (data) => ({url: 'appointment', method: 'post', data}),
			invalidatesTags: ['Appointment'],
		}),
		updateAppointment: builder.mutation({
			query: ({id, ...data}) => ({url: `appointment/${id}`, method: 'put', data}),
			invalidatesTags: ['Appointment'],
		}),
		completeAppointment: builder.mutation({
			query: ({id, ...data}) => ({url: `appointment/${id}/complete`, method: 'put', data}),
			invalidatesTags: ['Appointment', 'Interaction', 'Customer'],
		}),
		cancelAppointment: builder.mutation({
			query: (id) => ({url: `appointment/${id}`, method: 'delete'}),
			invalidatesTags: ['Appointment'],
		}),
	}),
});

export const {
	useGetAppointmentsQuery,
	useAddAppointmentMutation,
	useUpdateAppointmentMutation,
	useCompleteAppointmentMutation,
	useCancelAppointmentMutation,
} = appointmentApiSlice;
