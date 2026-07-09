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

// Màu tag trạng thái đợt thu (deal_payments.status).
export const PAYMENT_STATUS_TAG = {
	planned: 'gold',
	paid: 'success',
};

// Màu tag trạng thái nhắc hẹn (deal_reminders.status).
export const REMINDER_STATUS_TAG = {
	pending: 'processing',
	done: 'default',
};

// Màu chấm dòng thời gian theo loại hoạt động (deal_activities.type).
export const ACTIVITY_DOT = {
	created: '#f59e0b',
	status: '#2563eb',
	payment: '#16a34a',
	payment_plan: '#0891b2',
	payment_paid: '#16a34a',
	payment_delete: '#ef4444',
	commission: '#7c3aed',
	reminder: '#0891b2',
	reminder_done: '#64748b',
	update: '#94a3b8',
};
