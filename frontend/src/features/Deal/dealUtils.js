// Định dạng tiền VNĐ → "x tỷ" / "x triệu" (gọn cho bảng/thẻ).
export const fmtMoney = (v) => {
	const n = Number(v) || 0;
	if (n === 0) return '0';
	if (n >= 1e9) return `${(n / 1e9).toLocaleString('vi-VN', {maximumFractionDigits: 2})} tỷ`;
	if (n >= 1e6) return `${(n / 1e6).toLocaleString('vi-VN', {maximumFractionDigits: 0})} triệu`;
	return n.toLocaleString('vi-VN');
};

// Màu tag theo giai đoạn giao dịch.
export const DEAL_STATUS_TAG = {
	deposit: 'gold',
	contract: 'processing',
	completed: 'success',
	canceled: 'default',
};
