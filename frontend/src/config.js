// Cấu hình tĩnh của frontend — nguồn sự thật cho các giá trị KHÔNG cần database
// (tên/tiêu đề site, màu chủ đạo, bo góc, font...). Sửa ở `config.json`.
//
// Màu chủ đạo được dùng ở 2 nơi: antd theme (`index.js`) và CSS variable `--primary`
// (`assets/style/styles.scss`). `applyThemeConfig()` đồng bộ giá trị từ config xuống
// CSS variables lúc khởi động để cả hai luôn khớp — chỉ cần đổi 1 chỗ ở `config.json`.
import config from './config.json';

// "#DC2626" → "220,38,38" (định dạng cho các var --*-code dùng trong rgba()).
function hexToRgbCode(hex) {
    const value = hex.replace('#', '');
    const full = value.length === 3 ? value.split('').map((c) => c + c).join('') : value;
    const int = parseInt(full, 16);
    // eslint-disable-next-line no-bitwise
    return [(int >> 16) & 255, (int >> 8) & 255, int & 255].join(',');
}

// Ghi màu chủ đạo từ config xuống :root để SCSS (--primary...) khớp antd theme.
export function applyThemeConfig() {
    const { colorPrimary, colorPrimaryDark } = config.theme;
    const root = document.documentElement;
    const code = hexToRgbCode(colorPrimary);

    root.style.setProperty('--primary', colorPrimary);
    root.style.setProperty('--primary-code', code);
    root.style.setProperty('--primary-pale', `rgba(${code}, 0.06)`);
    root.style.setProperty('--primary-600', colorPrimary);
    root.style.setProperty('--primary-700', colorPrimaryDark);
}

export default config;
