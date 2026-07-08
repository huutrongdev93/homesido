import {Modal} from "antd";
import classNames from "classnames/bind";
import FontAwesomeIcon from "~/components/FontAwesome";
import styles from "./ModalForm.module.scss";

const cx = classNames.bind(styles);

/**
 * Modal form dùng chung: header gradient + icon, body nằm trong scope `.form`
 * (để input/select áp style chuẩn của hệ thống), footer nút gradient.
 *
 * Props: open, icon, title, subtitle, onCancel, onOk, okText, cancelText,
 * loading, width, children.
 */
function ModalForm({
	open,
	icon = "fa-light fa-pen-to-square",
	title,
	subtitle,
	onCancel,
	onOk,
	okText = "Lưu",
	cancelText = "Hủy",
	loading = false,
	width = 540,
	children,
}) {
	return (
		<Modal
			open={open}
			onCancel={onCancel}
			title={null}
			footer={null}
			closable={false}
			centered
			width={width}
			destroyOnHidden
			maskClosable={!loading}
			className={cx('modal')}
		>
			<div className={cx('head')}>
				<span className={cx('head-icon')}><FontAwesomeIcon icon={icon} /></span>
				<div className={cx('head-text')}>
					<h3 className={cx('head-title')}>{title}</h3>
					{subtitle && <p className={cx('head-sub')}>{subtitle}</p>}
				</div>
				<button type="button" className={cx('head-close')} onClick={onCancel} aria-label="Đóng">
					<FontAwesomeIcon icon="fa-light fa-xmark" />
				</button>
			</div>

			<div className={cx('body', 'form')}>
				{children}
			</div>

			<div className={cx('foot')}>
				<button type="button" className={cx('btn', 'btn-ghost')} onClick={onCancel} disabled={loading}>
					{cancelText}
				</button>
				<button type="button" className={cx('btn', 'btn-primary')} onClick={onOk} disabled={loading}>
					{loading && <FontAwesomeIcon icon="fa-light fa-spinner" spin className={cx('btn-spin')} />}
					<span>{okText}</span>
				</button>
			</div>
		</Modal>
	);
}

export default ModalForm;
