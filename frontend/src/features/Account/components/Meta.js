import className from "classnames/bind";
import {FontAwesomeIcon} from "~/components";
import style from "../style/Account.module.scss";

const cn = className.bind(style);

/**
 * Một item thông tin: icon (class FontAwesome) + nhãn nhỏ + giá trị.
 */
function Meta({icon, label, children}) {
	return (
		<div className={cn('meta')}>
			<span className={cn('meta-icon')}><FontAwesomeIcon icon={icon} /></span>
			<div className={cn('meta-body')}>
				<span className={cn('meta-label')}>{label}</span>
				<span className={cn('meta-value')}>{children || '—'}</span>
			</div>
		</div>
	);
}

export default Meta;
