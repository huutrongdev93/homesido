import { useEffect, useState } from "react";

// Đồng bộ với _breakpoints.scss: md=768 (mobile), xl=1300 (tablet).
const QUERY_MOBILE = '(max-width: 767px)';
const QUERY_TABLET = '(max-width: 1299px)';

function compute(mqMobile, mqTablet)
{
	const isMobile = mqMobile.matches;
	const isTablet = !isMobile && mqTablet.matches;

	return {isMobile, isTablet, isDesktop: !isMobile && !isTablet};
}

/**
 * Cờ responsive qua matchMedia (chỉ re-render khi đổi breakpoint,
 * không re-render từng pixel như resize listener cũ).
 */
function useViewport() {
	const [flags, setFlags] = useState(() =>
		compute(window.matchMedia(QUERY_MOBILE), window.matchMedia(QUERY_TABLET))
	);

	useEffect(() => {
		const mqMobile = window.matchMedia(QUERY_MOBILE);
		const mqTablet = window.matchMedia(QUERY_TABLET);

		const update = () => setFlags(compute(mqMobile, mqTablet));

		mqMobile.addEventListener('change', update);
		mqTablet.addEventListener('change', update);

		return () => {
			mqMobile.removeEventListener('change', update);
			mqTablet.removeEventListener('change', update);
		};
	}, []);

	return flags;
}

export default useViewport;
