import className from "classnames/bind";
import FontAwesomeIcon from "../FontAwesome";
import style from "./PageHeader.module.scss";

const cn = className.bind(style);

/**
 * Header trang dùng chung: ô icon gradient + tiêu đề + mô tả + vùng hành động phải.
 *
 * Props:
 *  - icon      : class FontAwesome (vd "fa-light fa-gauge-high").
 *  - title     : tiêu đề (string/node).
 *  - subtitle  : mô tả ngắn.
 *  - actions   : node bên phải (nút/link).
 */
function PageHeader({icon, title, subtitle, actions}) {
	return (
		<div className={cn('header')}>
			{icon && (
				<div className={cn('icon')}>
					<FontAwesomeIcon icon={icon} />
				</div>
			)}
			<div className={cn('text')}>
				<h1 className={cn('title')}>{title}</h1>
				{subtitle && <p className={cn('sub')}>{subtitle}</p>}
			</div>
			{actions && <div className={cn('actions')}>{actions}</div>}
		</div>
	);
}

export default PageHeader;
