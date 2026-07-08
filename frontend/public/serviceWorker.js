/**
 * Service worker của Base App — đăng ký sẵn trong index.html (scope gốc).
 *
 * 1. Offline fallback: request lỗi mạng → trả offline.html (giữ hành vi cũ).
 * 2. Web Push: nhận thông báo đẩy từ backend (push_queue → WebPushClient) và hiển thị
 *    notification hệ điều hành trên máy tính/mobile; bấm vào mở đúng trang liên quan.
 *    Payload JSON: {type, title, message, link} — cùng định dạng thông báo in-app.
 *
 * Đổi CACHE_NAME khi sửa file để trình duyệt thay service worker cũ.
 */
const CACHE_NAME = "base-app-v2";
// Chỉ cache offline.html — index.html không được serve từ cache ở fetch handler
// (cache nó chỉ tạo bản stale nằm chết trong Cache Storage).
const urlsToCache = ["offline.html"];

self.addEventListener("install", (event) => {
	event.waitUntil(
		caches.open(CACHE_NAME).then((cache) => cache.addAll(urlsToCache))
	);
	self.skipWaiting();
});

self.addEventListener("activate", (event) => {
	event.waitUntil(
		Promise.all([
			self.clients.claim(),
			// Dọn cache của các phiên bản service worker cũ.
			caches.keys().then((cacheNames) => Promise.all(
				cacheNames.filter((name) => name !== CACHE_NAME).map((name) => caches.delete(name))
			)),
		])
	);
});

// Offline fallback: chỉ can thiệp điều hướng trang (không đụng request API/asset).
self.addEventListener("fetch", (event) => {
	if (event.request.mode !== "navigate") return;
	event.respondWith(
		fetch(event.request).catch(() => caches.match("offline.html"))
	);
});

/* ------------------------------------------------------------------------
 | Web Push — thông báo đẩy trên thiết bị
 * ----------------------------------------------------------------------- */

self.addEventListener("push", (event) => {
	let data = {};
	try {
		data = event.data ? event.data.json() : {};
	} catch (e) {
		data = {title: event.data ? event.data.text() : ""};
	}

	const title = data.title || "Base App";

	event.waitUntil(
		self.registration.showNotification(title, {
			body: data.message || "",
			icon: "assets/images/logo-64x64.png",
			badge: "assets/images/logo-32x32.png",
			data: {link: data.link || ""},
		})
	);
});

// Bấm notification: focus tab ứng dụng đang mở (điều hướng tới link) hoặc mở tab mới.
// Thông báo KHÔNG có link → chỉ focus tab hiện có, không navigate (đừng kéo user
// đang làm việc về trang chủ vô cớ).
self.addEventListener("notificationclick", (event) => {
	event.notification.close();

	const base = self.registration.scope.replace(/\/$/, "");
	const link = (event.notification.data && event.notification.data.link) || "";
	const url = base + (link || "/");

	event.waitUntil(
		self.clients.matchAll({type: "window", includeUncontrolled: true}).then((windowClients) => {
			for (const client of windowClients) {
				if (client.url.indexOf(base) === 0 && "focus" in client) {
					if (link) client.navigate(url);
					return client.focus();
				}
			}
			return self.clients.openWindow(url);
		})
	);
});
