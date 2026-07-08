import {getNotification} from "./notification";

export function renderDate(time, format = 'daytime') {
	time = time ? new Date(time * 1000) : null;

	if (!(time instanceof Date)) return '';

	const day = ("0" + time.getDate()).slice(-2);
	const month = ("0" + (time.getMonth() + 1)).slice(-2);
	const year = time.getFullYear();
	const hours = ("0" + time.getHours()).slice(-2);
	const minutes = ("0" + time.getMinutes()).slice(-2);

	if (format === 'month') {
		return `${month}/${year}`;
	}

	if (format === 'fullTime') {
		return `${hours}:${minutes} ${day}/${month}/${year}`;
	}

	return `${day}/${month}/${year}`;
}

export function apiError(messageDefault, error, response = null) {

	let message = null;

	if(error) {
		message = messageDefault;

		if(error.code === 'ERR_NETWORK') {
			message = 'Kết nối server thất bại vui lòng thử lại';
		}
		if(error.code === 'ERR_BAD_REQUEST') {
			message = 'Lỗi server vui lòng liên hệ kỹ thuật';
		}
		if(error.code === 'ERR_TIMED_OUT') {
			message = 'Server phản hồi quá lâu vui lòng thử lại';
		}
		if(error?.response) {
			if(error.response?.message) message = error.response.message
			if(error.response?.data?.message) message = error.response.data.message
		}
	}
	else if(typeof response === 'undefined' || response === null) {
		message = messageDefault;
	}
	// Lỗi nghiệp vụ HTTP 200: body {status:'error', ...} — check theo status thay vì
	// so code với "200" (code phụ thuộc kiểu string/number của BE; nhất quán apiSlice.js).
	else if(response?.status === 'error') {
		message = (response?.message) ? response.message : messageDefault;
	}
	else if(!response?.data) {
		message = (response?.message) ? response.message : messageDefault;
	}

	if(message) getNotification().error({title: 'Lỗi', description: message});

	return message;
}

export const handleRequest = promise => {
	return promise.then(data => ([undefined, data, null])).catch(error => ([error, undefined, null]));
}
