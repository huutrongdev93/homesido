// Icon FontAwesome dạng <i>. Forward mọi prop còn lại (style, onClick, aria-*...) xuống thẻ <i>.
function FontAwesomeIcon({icon, className = '', spin, ...props}) {
	const classes = [icon, className, spin ? 'fa-spin' : ''].filter(Boolean).join(' ');
	return (<i className={classes} {...props}></i>)
}
export default FontAwesomeIcon
