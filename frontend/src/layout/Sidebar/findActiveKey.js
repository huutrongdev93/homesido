/**
 * Tìm key menu đang active theo pathname (khớp prefix dài nhất).
 *
 * Duyệt đệ quy để đi xuyên qua item type:'group' (group không có `to`
 * nhưng children của nó thì có) và submenu lồng nhau.
 *
 * @param navItems mảng items của antd Menu (mỗi item có thể kèm `to`)
 * @param pathname location.pathname hiện tại
 * @param fallback key trả về khi không khớp mục nào (mặc định 'home')
 */
export default function findActiveKey(navItems, pathname, fallback = 'home')
{
	const allItems = [];

	const collect = (items) => {
		for (const item of items)
		{
			if (!item) continue;

			if (item.to) allItems.push({key: item.key, to: item.to});

			if (Array.isArray(item.children)) collect(item.children);
		}
	};

	collect(navItems);

	if (allItems.length === 0) return fallback;

	allItems.sort((a, b) => b.to.length - a.to.length);

	for (const item of allItems)
	{
		if (pathname.startsWith(item.to)) return item.key;
	}

	return fallback;
}
