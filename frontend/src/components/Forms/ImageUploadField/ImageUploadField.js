import {useRef} from "react";
import className from "classnames/bind";
import {Spin} from "antd";
import FontAwesomeIcon from "../../FontAwesome";
import style from "./ImageUploadField.module.scss";

const cn = className.bind(style);

/**
 * Field upload 1 ảnh (presentational). Logic upload do component cha sở hữu:
 * - `preview`: URL ảnh hiện tại để hiển thị (null = chưa có).
 * - `uploading`: đang tải lên.
 * - `onFile(file)`: cha nhận File rồi tự upload + cập nhật value/preview.
 * - `onRemove()`: gỡ ảnh.
 */
function ImageUploadField({label, preview, uploading, onFile, onRemove, error, hint, accept = "image/*"}) {

	const inputRef = useRef(null);

	const pick = () => inputRef.current?.click();

	const change = (e) => {
		const file = e.target.files?.[0];
		if (file) onFile(file);
		e.target.value = '';
	};

	return (
		<div className="form-group">
			{label && <label>{label}</label>}

			<div className={cn('wrap')}>
				<div className={cn('preview', {empty: !preview})} onClick={pick}>
					{uploading
						? <Spin />
						: preview
							? <img src={preview} alt="preview" />
							: <span className={cn('placeholder')}><FontAwesomeIcon icon="fa-light fa-image" /></span>}
				</div>

				<div className={cn('side')}>
					<button type="button" className={cn('btn')} onClick={pick} disabled={uploading}>
						<FontAwesomeIcon icon="fa-light fa-upload" /> {preview ? 'Đổi ảnh' : 'Chọn ảnh'}
					</button>
					{preview && (
						<button type="button" className={cn('btn', 'btn-remove')} onClick={onRemove} disabled={uploading}>
							<FontAwesomeIcon icon="fa-light fa-trash-can" /> Xóa ảnh
						</button>
					)}
					{hint && <p className={cn('hint')}>{hint}</p>}
				</div>
			</div>

			<input ref={inputRef} type="file" accept={accept} onChange={change} style={{display: 'none'}} />

			{error && <p className="error-message">{error}</p>}
		</div>
	);
}

export default ImageUploadField;
