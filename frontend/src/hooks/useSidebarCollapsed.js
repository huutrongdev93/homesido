import {useCallback, useEffect, useState} from "react";

const STORAGE_KEY = 'base.sidebar.collapsed';

/**
 * Trạng thái thu gọn sidebar (desktop/tablet), lưu qua localStorage.
 *
 * Chưa từng toggle thủ công → mặc định theo viewport (tablet thu gọn,
 * desktop mở rộng); khi xoay máy/đổi cỡ màn hình vẫn bám theo mặc định đó.
 * Đã toggle rồi → tôn trọng lựa chọn của user qua reload.
 */
function useSidebarCollapsed(defaultCollapsed)
{
	const [state, setState] = useState(() => {
		const stored = localStorage.getItem(STORAGE_KEY);
		return {
			hasStored: stored !== null,
			collapsed: stored !== null ? stored === '1' : Boolean(defaultCollapsed),
		};
	});

	useEffect(() => {
		setState((prev) => prev.hasStored ? prev : {...prev, collapsed: Boolean(defaultCollapsed)});
	}, [defaultCollapsed]);

	const toggle = useCallback(() => {
		setState((prev) => {
			localStorage.setItem(STORAGE_KEY, prev.collapsed ? '0' : '1');
			return {hasStored: true, collapsed: !prev.collapsed};
		});
	}, []);

	return {collapsed: state.collapsed, toggle};
}

export default useSidebarCollapsed;
