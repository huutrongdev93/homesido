import {useRef} from "react";
import {App as AntdApp, Modal, Progress, Spin} from "antd";
import {FontAwesomeIcon} from "~/components";
import {rtkErrorMessage} from "~/reduxs/api/apiSlice";
import {
	useGetPropertyMediaQuery,
	useUploadPropertyMediaMutation,
	useDeletePropertyMediaMutation,
	useReorderPropertyMediaMutation,
	useSetPropertyCoverMutation,
	useGetStorageUsageQuery,
} from "~/reduxs/api/propertyApiSlice";
import style from "../style/Property.module.scss";

/** Đổi số byte → chuỗi ngắn gọn (KB/MB/GB). */
export const formatBytes = (n) => {
	const b = Number(n) || 0;
	if (b < 1024) return `${b} B`;
	if (b < 1024 * 1024) return `${(b / 1024).toFixed(0)} KB`;
	if (b < 1024 * 1024 * 1024) return `${(b / 1024 / 1024).toFixed(1)} MB`;
	return `${(b / 1024 / 1024 / 1024).toFixed(2)} GB`;
};

/**
 * Modal quản lý media (ảnh/video/tài liệu) của 1 BĐS: upload nhiều file, xóa, sắp xếp thứ tự.
 * Header hiện dung lượng đã dùng của user (gói theo dung lượng). Props: open, property, canEdit, onClose.
 */
function PropertyMediaModal({open, property, canEdit, onClose}) {

	const {notification, modal} = AntdApp.useApp();
	const inputRef = useRef(null);

	const id = property?.id;
	const skip = !open || !id;

	const {data: media = [], isFetching} = useGetPropertyMediaQuery(id, {skip});
	const {data: usage = {used_bytes: 0, quota_bytes: 0}} = useGetStorageUsageQuery(undefined, {skip: !open});

	const [uploadMedia, {isLoading: uploading}] = useUploadPropertyMediaMutation();
	const [deleteMedia] = useDeletePropertyMediaMutation();
	const [reorderMedia] = useReorderPropertyMediaMutation();
	const [setCover] = useSetPropertyCoverMutation();

	const pick = () => inputRef.current?.click();

	const onFiles = async (e) => {
		const files = Array.from(e.target.files || []);
		e.target.value = '';
		if (!files.length) return;

		const formData = new FormData();
		files.forEach((f) => formData.append('files[]', f));

		try {
			await uploadMedia({id, formData}).unwrap();
			notification.success({message: 'Thành công', description: `Đã tải lên ${files.length} tệp`});
		} catch (err) {
			notification.error({message: 'Lỗi', description: rtkErrorMessage(err, 'Tải lên thất bại')});
		}
	};

	const remove = (item) => {
		modal.confirm({
			title: 'Xóa tệp',
			content: 'Xóa tệp này khỏi bất động sản? Dung lượng sẽ được hoàn lại.',
			okText: 'Xóa', okButtonProps: {danger: true}, cancelText: 'Hủy',
			onOk: async () => {
				try {
					await deleteMedia({id, mediaId: item.id}).unwrap();
					notification.success({message: 'Thành công', description: 'Đã xóa tệp'});
				} catch (err) {
					notification.error({message: 'Lỗi', description: rtkErrorMessage(err, 'Xóa thất bại')});
					return Promise.reject();
				}
			},
		});
	};

	// Đổi chỗ 2 phần tử rồi gửi thứ tự mới (mảng id) lên server.
	const move = (index, dir) => {
		const next = index + dir;
		if (next < 0 || next >= media.length) return;
		const order = media.map((m) => m.id);
		[order[index], order[next]] = [order[next], order[index]];
		reorderMedia({id, order});
	};

	// Đặt/bỏ ảnh đại diện. Bấm lại ảnh đang là đại diện = bỏ chọn (về ảnh đầu tiên).
	const chooseCover = async (item) => {
		try {
			await setCover({id, mediaId: item.is_cover ? 0 : item.id}).unwrap();
			notification.success({message: 'Thành công', description: item.is_cover ? 'Đã bỏ ảnh đại diện' : 'Đã đặt làm ảnh đại diện'});
		} catch (err) {
			notification.error({message: 'Lỗi', description: rtkErrorMessage(err, 'Cập nhật ảnh đại diện thất bại')});
		}
	};

	const quota = Number(usage.quota_bytes) || 0;
	const used = Number(usage.used_bytes) || 0;
	const percent = quota > 0 ? Math.min(100, Math.round((used / quota) * 100)) : 0;

	return (
		<Modal
			open={open}
			onCancel={onClose}
			footer={null}
			width={720}
			title={property ? `Media — ${property.title}` : 'Media'}
			destroyOnClose
		>
			<div className={style.storageBar}>
				<div className={style.storageHead}>
					<span><FontAwesomeIcon icon="fa-light fa-database" /> Dung lượng của bạn</span>
					<span>{formatBytes(used)}{quota > 0 ? ` / ${formatBytes(quota)}` : ' (không giới hạn)'}</span>
				</div>
				{quota > 0 && <Progress percent={percent} size="small" status={percent >= 100 ? 'exception' : 'active'} />}
			</div>

			{canEdit && (
				<div className={style.mediaUpload}>
					<button type="button" className={style.uploadBtn} onClick={pick} disabled={uploading}>
						{uploading ? <Spin size="small" /> : <FontAwesomeIcon icon="fa-light fa-cloud-arrow-up" />}
						<span>{uploading ? 'Đang tải lên...' : 'Tải ảnh / video lên'}</span>
					</button>
					<input ref={inputRef} type="file" multiple accept="image/*,video/*" onChange={onFiles} style={{display: 'none'}} />
				</div>
			)}

			{isFetching ? (
				<div className={style.mediaLoading}><Spin /></div>
			) : media.length === 0 ? (
				<div className={style.mediaEmpty}>Chưa có ảnh/video nào</div>
			) : (
				<div className={style.mediaGrid}>
					{media.map((m, i) => (
						<div key={m.id} className={`${style.mediaItem} ${m.is_cover ? style.mediaCover : ''}`}>
							<div className={style.mediaThumb}>
								{m.type === 'image'
									? <img src={m.url} alt="" />
									: m.type === 'video'
										? <video src={m.url} muted />
										: <FontAwesomeIcon icon="fa-light fa-file" />}
								{m.type === 'video' && <span className={style.playBadge}><FontAwesomeIcon icon="fa-solid fa-play" /></span>}
								{m.is_cover && (
									<span className={style.coverBadge}><FontAwesomeIcon icon="fa-solid fa-star" /> Đại diện</span>
								)}
							</div>
							<div className={style.mediaMeta}>{formatBytes(m.size)}</div>
							{canEdit && (
								<div className={style.mediaActions}>
									{m.type === 'image' && (
										<button
											type="button"
											className={m.is_cover ? style.coverActive : ''}
											onClick={() => chooseCover(m)}
											title={m.is_cover ? 'Bỏ ảnh đại diện' : 'Đặt làm ảnh đại diện'}
										>
											<FontAwesomeIcon icon={m.is_cover ? 'fa-solid fa-star' : 'fa-light fa-star'} />
										</button>
									)}
									<button type="button" onClick={() => move(i, -1)} disabled={i === 0} title="Lên trước">
										<FontAwesomeIcon icon="fa-light fa-arrow-left" />
									</button>
									<button type="button" onClick={() => move(i, 1)} disabled={i === media.length - 1} title="Xuống sau">
										<FontAwesomeIcon icon="fa-light fa-arrow-right" />
									</button>
									<button type="button" className={style.mediaDanger} onClick={() => remove(m)} title="Xóa">
										<FontAwesomeIcon icon="fa-light fa-trash" />
									</button>
								</div>
							)}
						</div>
					))}
				</div>
			)}
		</Modal>
	);
}

export default PropertyMediaModal;
