import dayjs from "dayjs";
import request from "~/utils/http";

/**
 * Tải báo cáo Excel (blob) rồi kích hoạt download. `request` (utils/http) trả về response.data,
 * với responseType 'blob' chính là Blob. Không đi qua RTK Query (không phải state). Xem customerFileApi.
 */
export const exportReport = async (params = {}) => {
	const blob = await request({url: 'report/export', method: 'get', params, responseType: 'blob'});

	const href = window.URL.createObjectURL(blob);
	const a = document.createElement('a');
	a.href = href;
	a.download = `bao-cao-${dayjs().format('YYYYMMDD-HHmmss')}.xlsx`;
	document.body.appendChild(a);
	a.click();
	a.remove();
	window.URL.revokeObjectURL(href);
};
