import dayjs from "dayjs";
import request from "~/utils/http";

/**
 * Tải file từ API (blob) rồi kích hoạt download ở trình duyệt.
 *
 * `request` (utils/http) đã trả về response.data qua interceptor → với responseType 'blob'
 * giá trị trả về CHÍNH là Blob. Dùng cho xuất Excel / tải template (không phải state nên
 * không đi qua RTK Query).
 */
const download = async (url, params, filename) => {
	const blob = await request({url, method: 'get', params, responseType: 'blob'});

	const href = window.URL.createObjectURL(blob);
	const a = document.createElement('a');
	a.href = href;
	a.download = filename;
	document.body.appendChild(a);
	a.click();
	a.remove();
	window.URL.revokeObjectURL(href);
};

/** Xuất khách hàng ra Excel theo filter hiện tại (keyword/pipeline_stage/temperature/assigned_user_id). */
export const exportCustomers = (params = {}) =>
	download('customer/export', params, `khach-hang-${dayjs().format('YYYYMMDD-HHmmss')}.xlsx`);

/** Tải file mẫu để nhập khách. */
export const downloadImportTemplate = () =>
	download('customer/import-template', {}, 'mau-nhap-khach-hang.xlsx');
