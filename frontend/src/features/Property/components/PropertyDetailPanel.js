import {useContext, useMemo} from "react";
import dayjs from "dayjs";
import {AppContext} from "~/context/AppProvider";
import {FontAwesomeIcon} from "~/components";
import {useGetPropertyQuery} from "~/reduxs/api/propertyApiSlice";
import {useGetProvincesQuery, useGetWardsQuery} from "~/reduxs/api/locationApiSlice";
import {formatBytes} from "./PropertyMediaModal";
import style from "../style/Property.module.scss";

/** Icon theo loại tài liệu (pdf/word/excel/…) suy từ đuôi file. */
const docIcon = (name = '') => {
	const ext = name.split('.').pop().toLowerCase();
	if (['pdf'].includes(ext)) return 'fa-light fa-file-pdf';
	if (['doc', 'docx'].includes(ext)) return 'fa-light fa-file-word';
	if (['xls', 'xlsx', 'csv'].includes(ext)) return 'fa-light fa-file-excel';
	if (['zip', 'rar', '7z'].includes(ext)) return 'fa-light fa-file-zipper';
	return 'fa-light fa-file';
};

const toMap = (arr = []) => arr.reduce((m, o) => ({...m, [o.value]: o.label}), {});

/** Số gọn: bỏ phần thập phân thừa (32.00 → 32; 4.5 giữ nguyên). */
const fmtNum = (v) => {
	const n = Number(v);
	if (!n) return '0';
	return n.toLocaleString('vi-VN', {maximumFractionDigits: 2});
};

/** Giá dạng dài "4.5 triệu" / "3.5 tỷ"; thêm "/tháng" khi cho thuê. */
const fmtPriceLong = (v, transaction) => {
	const n = Number(v);
	if (!n) return '—';
	let text;
	if (n >= 1e9) text = `${(n / 1e9).toLocaleString('vi-VN', {maximumFractionDigits: 2})} tỷ`;
	else if (n >= 1e6) text = `${(n / 1e6).toLocaleString('vi-VN', {maximumFractionDigits: 2})} triệu`;
	else text = `${n.toLocaleString('vi-VN')} đ`;
	return transaction === 'rent' ? `${text}/Tháng` : text;
};

/**
 * Panel chi tiết BĐS hiển thị INLINE trong hàng bảng (expandedRowRender) — mô phỏng thiết kế demo:
 * header (badge + tiêu đề + giá + nút), khối THÔNG SỐ (spec cards động theo field có giá trị), khối VỊ TRÍ.
 *
 * Props: property (record từ list), canEdit, onEdit(record), onMedia(record).
 * Tự fetch detail (media + field mới nhất) qua useGetPropertyQuery; tên tỉnh/phường tra từ locationApiSlice.
 */
function PropertyDetailPanel({property, canEdit, onEdit, onMedia}) {

	const {appData} = useContext(AppContext);
	const enums = useMemo(() => (appData && appData.property) || {}, [appData]);

	const maps = useMemo(() => ({
		type:      toMap(enums.property_types),
		trans:     toMap(enums.transaction_types),
		status:    toMap(enums.statuses),
		direction: toMap(enums.directions),
		road:      toMap(enums.road_types),
		legal:     toMap(enums.legal_statuses),
		furniture: toMap(enums.furnitures),
	}), [enums]);

	const {data: detail, isFetching} = useGetPropertyQuery(property.id, {skip: !property?.id});
	const p = detail || property;

	const {data: provinces = []} = useGetProvincesQuery();
	const {data: wards = []} = useGetWardsQuery(p.province_code, {skip: !p.province_code});

	const provinceName = useMemo(() => provinces.find((x) => x.value === p.province_code)?.label || '', [provinces, p.province_code]);
	const wardName = useMemo(() => wards.find((x) => x.value === p.ward_code)?.label || '', [wards, p.ward_code]);

	const media = useMemo(() => detail?.media || [], [detail]);
	// Ảnh đại diện = media ảnh đầu tiên (nếu có).
	const cover = useMemo(() => media.find((m) => m.type === 'image')?.url || '', [media]);

	const STATUS_COLORS = {available: '#16a34a', deposited: '#d97706', sold: '#dc2626', rented: '#2563eb', inactive: '#64748b'};
	const statusColor = STATUS_COLORS[p.status] || '#64748b';

	// Spec cards — chỉ hiện field có giá trị.
	const area = p.area_usable || p.area_land;
	const specs = [
		area && {icon: 'fa-light fa-ruler-combined', value: `${fmtNum(area)} m²`, label: 'Diện tích'},
		p.bedrooms > 0 && {icon: 'fa-light fa-bed', value: `${p.bedrooms} PN`, label: 'Phòng ngủ'},
		p.bathrooms > 0 && {icon: 'fa-light fa-bath', value: `${p.bathrooms} Phòng tắm`, label: 'Phòng tắm'},
		p.floors > 0 && {icon: 'fa-light fa-layer-group', value: `${p.floors} Tầng`, label: 'Số tầng'},
		p.direction && {icon: 'fa-light fa-compass', value: maps.direction[p.direction] || p.direction, label: 'Hướng'},
		p.road_type && {icon: 'fa-light fa-road', value: maps.road[p.road_type] || p.road_type, label: 'Vị trí / Đường vào'},
		p.legal_status && {icon: 'fa-light fa-file-contract', value: maps.legal[p.legal_status] || p.legal_status, label: 'Pháp lý'},
		p.furniture && {icon: 'fa-light fa-couch', value: maps.furniture[p.furniture] || p.furniture, label: 'Nội thất'},
		{icon: 'fa-light fa-folder-open', value: maps.type[p.property_type] || '—', label: 'Loại hình'},
	].filter(Boolean);

	return (
		<div className={style.detailPanel}>
			{/* Header */}
			<div className={style.dHead}>
				<div className={style.dThumb}>
					{cover
						? <img src={cover} alt={p.title} />
						: <FontAwesomeIcon icon="fa-light fa-image" />}
				</div>

				<div className={style.dHeadMain}>
					<div className={style.dBadges}>
						<span className={style.dBadge}>
							<FontAwesomeIcon icon="fa-light fa-tag" /> {maps.trans[p.transaction_type] || p.transaction_type}
						</span>
						<span className={style.dBadge}>
							<FontAwesomeIcon icon="fa-light fa-house" /> {maps.type[p.property_type] || 'BĐS'}
						</span>
						<span className={style.dBadge} style={{color: statusColor, borderColor: statusColor}}>
							<FontAwesomeIcon icon="fa-light fa-circle-check" /> {maps.status[p.status] || p.status}
						</span>
						<span className={`${style.dBadge} ${style.dBadgeId}`}># {p.id}</span>
					</div>
					<h3 className={style.dTitle}>{p.title}</h3>
					<div className={style.dCode}>Mã: {p.code}</div>
				</div>

				<div className={style.dHeadSide}>
					<div className={style.dPriceLabel}>GIÁ</div>
					<div className={style.dPrice}>{fmtPriceLong(p.price, p.transaction_type)}</div>
					<div className={style.dActions}>
						{canEdit && (
							<button type="button" className={`${style.dBtn} ${style.dBtnPrimary}`} onClick={() => onEdit(property)}>
								<FontAwesomeIcon icon="fa-light fa-pen-to-square" /> Chỉnh sửa
							</button>
						)}
						<button type="button" className={style.dBtn} onClick={() => onMedia(property)}>
							<FontAwesomeIcon icon="fa-light fa-images" /> Ảnh / video
						</button>
					</div>
				</div>
			</div>

			{/* Thông số */}
			<div className={style.dCard}>
				<div className={style.dCardHead}>
					<FontAwesomeIcon icon="fa-light fa-sliders" /> THÔNG SỐ
				</div>
				<div className={style.dSpecs}>
					{specs.map((s, i) => (
						<div key={i} className={style.dSpec}>
							<FontAwesomeIcon icon={s.icon} className={style.dSpecIcon} />
							<div className={style.dSpecText}>
								<div className={style.dSpecValue}>{s.value}</div>
								<div className={style.dSpecLabel}>{s.label}</div>
							</div>
						</div>
					))}
				</div>
			</div>

			{/* Thư viện ảnh / video / tài liệu */}
			<div className={style.dCard}>
				<div className={style.dCardHead}>
					<FontAwesomeIcon icon="fa-light fa-photo-film" /> THƯ VIỆN
					<span className={style.dCardCount}>{media.length}</span>
				</div>
				{media.length === 0 ? (
					<div className={style.dMediaEmpty}>
						<FontAwesomeIcon icon="fa-light fa-image" /> Chưa có ảnh / video / tài liệu
					</div>
				) : (
					<div className={style.mediaGrid}>
						{media.map((m) => (
							<a key={m.id} className={style.mediaItem} href={m.url} target="_blank" rel="noreferrer" title={m.original_name || ''}>
								<div className={style.mediaThumb}>
									{m.type === 'image'
										? <img src={m.url} alt={m.original_name || ''} />
										: m.type === 'video'
											? <video src={m.url} muted />
											: <FontAwesomeIcon icon={docIcon(m.original_name)} />}
									{m.type === 'video' && <span className={style.playBadge}><FontAwesomeIcon icon="fa-solid fa-play" /></span>}
								</div>
								<div className={style.mediaMeta}>
									{m.type === 'document' && m.original_name && (
										<div className={style.dMediaName}>{m.original_name}</div>
									)}
									{formatBytes(m.size)}
								</div>
							</a>
						))}
					</div>
				)}
			</div>

			{/* Vị trí */}
			{(p.address || provinceName || wardName) && (
				<div className={style.dCard}>
					<div className={style.dCardHead}>
						<FontAwesomeIcon icon="fa-light fa-location-dot" /> VỊ TRÍ
					</div>
					{p.address && (
						<div className={style.dAddress}>
							<FontAwesomeIcon icon="fa-light fa-map-pin" /> {p.address}
						</div>
					)}
					<div className={style.dLocTags}>
						{wardName && <span className={style.dLocTag}><FontAwesomeIcon icon="fa-light fa-building" /> {wardName}</span>}
						{provinceName && <span className={style.dLocTag}><FontAwesomeIcon icon="fa-light fa-map" /> {provinceName}</span>}
					</div>
				</div>
			)}

			{/* Footer */}
			<div className={style.dFoot}>
				<span><FontAwesomeIcon icon="fa-light fa-calendar" /> Ngày tạo: {p.created ? dayjs(p.created).format('DD/MM/YYYY HH:mm') : '—'}</span>
				<span><FontAwesomeIcon icon="fa-light fa-eye" /> {p.visibility === 'shared' ? 'Kho chung' : 'Riêng của tôi'}</span>
				{isFetching && <span className={style.dLoading}><FontAwesomeIcon icon="fa-light fa-spinner" spin /> Đang tải…</span>}
			</div>
		</div>
	);
}

export default PropertyDetailPanel;
