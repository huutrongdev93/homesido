import {notification as staticNotification} from "antd";

/**
 * Cầu nối notification.
 *
 * antd v6 cảnh báo: gọi notification static (import trực tiếp từ "antd") không
 * nhận được context (theme) của <App>. Cách đúng là dùng App.useApp() trong
 * component. Nhưng các hàm tiện ích/saga (vd apiError) không phải component nên
 * không gọi hook được.
 *
 * Giải pháp: sau khi <App> mount, App.js gắn instance notification (từ
 * App.useApp()) qua bindNotification(). Code ngoài component gọi getNotification()
 * để lấy instance có context. Trước khi gắn (rất sớm) thì tạm fallback static.
 */
let contextNotification = null;

export const bindNotification = (instance) => {
	contextNotification = instance;
};

export const getNotification = () => contextNotification ?? staticNotification;
