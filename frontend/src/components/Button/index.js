import style from "./Button.module.scss";
import className from 'classnames/bind';
import {Link} from "react-router-dom";
import {forwardRef} from "react";
import Loading from "../Loading";
const cn = className.bind(style);
const Button = forwardRef(({
   	to,
	href,
	small = false,
	large = false,
	primary = false,
	red = false,
	blue = false,
	yellow = false,
	green = false,
	white = false,
	grey = false,
	outline = false,
	background = false,
	rounded = false,
	disabled = false,
	noneBorder = false,
	children,
	className,
	leftIcon,
	rightIcon,
	onClick,
	loading = false,
	...passProps }, ref
) => {
	let Comp = 'button';

	const props = {onClick, ...passProps}

	if(to) {
		props.to = to
		Comp = Link
	}
	else if(href) {
		props.href = href
		Comp = 'a'
	}

	if(loading) disabled =true;

	if(disabled) {
		// Gỡ mọi event handler (on*) khi nút bị vô hiệu — tránh Link/a vẫn bấm được.
		Object.keys(props).forEach((key) => {
			if(key.startsWith('on') && typeof props[key] === 'function') {
				delete props[key];
			}
		})
		// Nút thật thì gắn hẳn attribute disabled (chặn cả bàn phím / implicit form submit).
		if(Comp === 'button') props.disabled = true;
	}

	const classes = cn('wrapper', {primary, red, blue, yellow, green, white, grey, rounded, outline, background, noneBorder, small, large, disabled, [className] : className}) + ' button';

	return (
		<Comp className={classes} {...props} ref={ref}>
			{loading && <Loading noFixed spin className={cn('buttonLoading', 'icon')} />}
			{(!loading && leftIcon) && <span className={cn('icon') + ' icon'}>{leftIcon}</span>}
			{children && <span className={cn('title')+ ' title'}>{children}</span>}
			{rightIcon && <span className={cn('icon')+ ' icon'}>{rightIcon}</span>}
		</Comp>
	)
});

export default Button;