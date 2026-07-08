import images from "~/assets/images";

/**
 * Avatar hiển thị cho user (base chưa có chức năng upload avatar).
 *
 * Base dùng chung nên chỉ trả về 1 ảnh mặc định trung tính. Đây là ĐIỂM MỞ RỘNG:
 * dự án con muốn avatar theo chức vụ/giới tính/ảnh upload thì thay logic tại đây
 * (mọi nơi hiển thị avatar đều đi qua hàm này — ProfileRow, LoginAsModal, Account).
 *
 * @param user object user (chưa dùng — giữ chữ ký để dự án con mở rộng)
 */
export function resolveUserAvatar(user) {
	return images.avatarDefault;
}
