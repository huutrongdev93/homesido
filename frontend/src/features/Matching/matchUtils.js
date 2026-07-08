/**
 * Tiện ích dùng chung cho Matching (trang /matching + drawer khách + panel BĐS).
 */

/** Định dạng giá VNĐ → "x tỷ" / "x tr" / "Thỏa thuận" (0). */
export const fmtPrice = (v) => {
	const n = Number(v) || 0;
	if (n <= 0) return 'Thỏa thuận';
	if (n >= 1e9) return `${(n / 1e9).toFixed(n % 1e9 === 0 ? 0 : 1)} tỷ`;
	if (n >= 1e6) return `${Math.round(n / 1e6)} tr`;
	return n.toLocaleString('vi-VN');
};

/** Màu tag theo điểm khớp (0–100). */
export const matchScoreColor = (s) => (s >= 70 ? 'green' : s >= 40 ? 'gold' : s > 0 ? 'blue' : 'default');
