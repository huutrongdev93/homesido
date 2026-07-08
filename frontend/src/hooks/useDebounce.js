import {useEffect, useState} from "react";

/**
 * Trả về giá trị sau khi ngừng thay đổi `delay` ms — dùng cho ô tìm kiếm lịch sử
 * (gõ xong mới gọi API, tránh spam request theo từng phím).
 */
function useDebounce(value, delay = 400) {
	const [debounced, setDebounced] = useState(value);

	useEffect(() => {
		const timer = setTimeout(() => setDebounced(value), delay);
		return () => clearTimeout(timer);
	}, [value, delay]);

	return debounced;
}

export default useDebounce;
