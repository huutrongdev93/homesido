import {Button, FontAwesomeIcon} from "~/components";

/**
 * Trang 404 — thân thiện thay cho dòng "Page Not Found" trơ trọi: biểu tượng + giải thích
 * + nút quay về trang chủ.
 */
function PageNotFound() {
	return (
		<div className="container" style={{
			minHeight: '60vh', display: 'flex', flexDirection: 'column',
			alignItems: 'center', justifyContent: 'center', textAlign: 'center', gap: 14,
		}}>
			<FontAwesomeIcon icon="fa-light fa-compass-slash" style={{fontSize: 64, color: '#c4b5fd'}} />
			<h1 style={{fontSize: 40, fontWeight: 700, margin: 0, color: '#1f2937'}}>404</h1>
			<p style={{fontSize: 16, color: '#6b7280', maxWidth: 460, margin: 0}}>
				Không tìm thấy trang bạn yêu cầu. Có thể liên kết đã cũ hoặc bạn gõ nhầm địa chỉ.
			</p>
			<div style={{display: 'flex', gap: 10, marginTop: 6, flexWrap: 'wrap', justifyContent: 'center'}}>
				<Button primary to="/" leftIcon={<FontAwesomeIcon icon="fa-light fa-house" />}>
					Về trang chủ
				</Button>
			</div>
		</div>
	);
}

export default PageNotFound;
