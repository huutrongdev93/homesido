import style from './Loading.module.scss';
import className from 'classnames/bind';
const cn = className.bind(style);

function Loading({noFixed, noBar, bar, spin, className, style}) {
	const classes = cn('loading-wrapper', {noFixed, noBar, bar, spin, [className] : className});
	return (<div className={classes} style={style}>
		<div className={cn('loader-box')}>
			<p className={cn('loader')}><span className={cn('loader-text')}>B</span></p>
			<span className={cn('header')}>Đang tải…</span>
		</div>
		<p className={cn('loader-bar')}></p>
	</div>);
}

export default Loading;