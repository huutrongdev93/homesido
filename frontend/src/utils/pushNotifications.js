/**
 * Helper thông báo đẩy (Web Push) trên thiết bị — dùng bởi NotificationBell.
 *
 * Service worker (public/serviceWorker.js) đã được index.html đăng ký ở scope gốc;
 * ở đây chỉ xin quyền Notification + subscribe/unsubscribe PushManager rồi đồng bộ
 * subscription với backend (api/notifications/push/*).
 *
 * Lưu ý hỗ trợ: cần HTTPS (hoặc localhost). iOS Safari 16.4+ chỉ nhận push khi user
 * đã "Thêm vào màn hình chính" (PWA) — chưa cài thì pushSupported() trả false luôn.
 */

/** Trình duyệt này có dùng được Web Push không. */
export function pushSupported() {
	return "serviceWorker" in navigator && "PushManager" in window && "Notification" in window;
}

/** Quyền hiện tại: 'granted' | 'denied' | 'default' (chưa hỏi). */
export function pushPermission() {
	return pushSupported() ? Notification.permission : "denied";
}

/** Chuyển khoá VAPID public (base64url) sang Uint8Array cho PushManager.subscribe. */
function urlBase64ToUint8Array(base64String) {
	const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
	const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
	const raw = window.atob(base64);
	return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)));
}

/** Registration của service worker gốc (đã active — index.html đăng ký lúc load). */
async function swRegistration() {
	return navigator.serviceWorker.ready;
}

/** Subscription hiện có của thiết bị này (null = chưa bật). */
export async function getCurrentSubscription() {
	if (!pushSupported()) return null;
	try {
		const registration = await swRegistration();
		return await registration.pushManager.getSubscription();
	} catch (e) {
		return null;
	}
}

/**
 * Bật thông báo trên thiết bị này: xin quyền → subscribe với khoá VAPID.
 * Trả về object subscription (JSON) để POST lên api/notifications/push/subscribe.
 * Ném Error với message tiếng Việt khi bị chặn/lỗi — UI hiển thị thẳng.
 */
export async function subscribePush(publicKey) {
	if (!pushSupported()) {
		throw new Error("Trình duyệt này không hỗ trợ thông báo đẩy.");
	}

	const permission = await Notification.requestPermission();

	if (permission !== "granted") {
		throw new Error("Bạn đã chặn thông báo — hãy cho phép trong cài đặt trình duyệt (biểu tượng ổ khoá cạnh thanh địa chỉ).");
	}

	const registration = await swRegistration();

	const subscription = await registration.pushManager.subscribe({
		userVisibleOnly: true,
		applicationServerKey: urlBase64ToUint8Array(publicKey),
	});

	return subscription.toJSON();
}

/**
 * Tắt thông báo trên thiết bị này. Trả về JSON của subscription vừa huỷ (để báo
 * backend xoá) hoặc null nếu vốn chưa bật.
 */
export async function unsubscribePush() {
	const subscription = await getCurrentSubscription();

	if (!subscription) return null;

	const json = subscription.toJSON();

	await subscription.unsubscribe();

	return json;
}
