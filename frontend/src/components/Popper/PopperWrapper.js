import classNames from 'classnames/bind';
import styles from './Popper.module.scss';

const cn = classNames.bind(styles);

function PopperWrapper({children, className, style}) {
	return (
		<div className={cn('wrapper', className)} style={style}>{children}</div>
	)
}

export default PopperWrapper;