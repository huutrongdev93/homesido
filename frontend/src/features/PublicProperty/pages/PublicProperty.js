import {useEffect, useMemo, useState} from "react";
import {useParams, Link} from "react-router-dom";
import {Image} from "antd";
import {FontAwesomeIcon} from "~/components";
import {useGetPublicPropertyQuery} from "~/reduxs/api/publicPropertyApiSlice";
import config from "~/config";
import style from "../style/PublicProperty.module.scss";

/** Số gọn: bỏ phần thập phân thừa (32.00 → 32; 4.5 giữ nguyên). */
const fmtNum = (v) => {
	const n = Number(v);
	if (!n) return '0';
	return n.toLocaleString('vi-VN', {maximumFractionDigits: 2});
};

/** Giá dạng dài "4.5 triệu" / "3.5 tỷ"; thêm "/tháng" khi cho thuê. */
const fmtPriceLong = (v, transaction) => {
	const n = Number(v);
	if (!n) return 'Thỏa thuận';
	let text;
	if (n >= 1e9) text = `${(n / 1e9).toLocaleString('vi-VN', {maximumFractionDigits: 2})} tỷ`;
	else if (n >= 1e6) text = `${(n / 1e6).toLocaleString('vi-VN', {maximumFractionDigits: 2})} triệu`;
	else text = `${n.toLocaleString('vi-VN')} đ`;
	return transaction === 'rent' ? `${text}/tháng` : text;
};

/** Link tới trang công khai 1 BĐS (kèm basename khi deploy dưới subpath). */
const publicUrl = (code) => `${process.env.REACT_APP_HOMEPAGE || ''}/p/${encodeURIComponent(code)}`;

/** Card 1 BĐS liên quan ("BĐS dành cho bạn"). */
function RelatedCard({item}) {
	return (
		<Link to={publicUrl(item.code)} className={style.relCard} reloadDocument>
			<div className={style.relThumb}>
				{item.thumbnail
					? <img src={item.thumbnail} alt={item.title} loading="lazy" />
					: <FontAwesomeIcon icon="fa-light fa-image" />}
				<span className={style.relBadge}>{item.transaction_type === 'rent' ? 'Cho thuê' : 'Bán'}</span>
			</div>
			<div className={style.relBody}>
				<div className={style.relTitle}>{item.title || 'Chưa đặt tiêu đề'}</div>
				<div className={style.relPrice}>{fmtPriceLong(item.price, item.transaction_type)}
					{item.area > 0 && <span className={style.relArea}> · {fmtNum(item.area)} m²</span>}
				</div>
				{item.province_name && (
					<div className={style.relLoc}><FontAwesomeIcon icon="fa-light fa-location-dot" /> {item.province_name}</div>
				)}
			</div>
		</Link>
	);
}

/**
 * Trang CÔNG KHAI 1 BĐS — gửi link cho khách xem, không cần đăng nhập.
 * Route `/p/:code` (layout null). Fetch qua useGetPublicPropertyQuery (endpoint public, không token).
 */
function PublicProperty() {

	const {code} = useParams();
	const {data: p, isLoading, isError, error} = useGetPublicPropertyQuery(code, {skip: !code});

	const [active, setActive] = useState(0);   // index ảnh lớn đang xem

	// Tiêu đề tab theo BĐS.
	useEffect(() => {
		document.title = p?.title ? `${p.title} — ${config.app.name}` : config.app.name;
		return () => { document.title = config.app.title; };
	}, [p]);

	// Chỉ ảnh/video hiển thị trong gallery (tài liệu bỏ qua).
	const gallery = useMemo(() => (p?.media || []).filter((m) => m.type === 'image' || m.type === 'video'), [p]);

	// Địa chỉ đầy đủ (address + phường + tỉnh).
	const fullAddress = useMemo(() => {
		if (!p) return '';
		return [p.address, p.ward_name, p.province_name].filter(Boolean).join(', ');
	}, [p]);

	// Query bản đồ: ưu tiên tọa độ, else địa chỉ.
	const mapQuery = useMemo(() => {
		if (!p) return '';
		if (p.latitude && p.longitude) return `${p.latitude},${p.longitude}`;
		return fullAddress;
	}, [p, fullAddress]);

	if (isLoading) {
		return (
			<div className={style.stateWrap}>
				<FontAwesomeIcon icon="fa-light fa-spinner" spin className={style.stateIcon} />
				<div>Đang tải thông tin bất động sản…</div>
			</div>
		);
	}

	if (isError || !p) {
		const msg = error?.data?.message || 'Bất động sản không tồn tại hoặc đã ngừng hiển thị.';
		return (
			<div className={style.stateWrap}>
				<FontAwesomeIcon icon="fa-light fa-house-circle-xmark" className={style.stateIcon} />
				<h2>Không tìm thấy bất động sản</h2>
				<p>{msg}</p>
			</div>
		);
	}

	const area = p.area_usable || p.area_land;

	// Đặc điểm — chỉ hiện field có giá trị.
	const specs = [
		area && {icon: 'fa-light fa-ruler-combined', label: 'Diện tích', value: `${fmtNum(area)} m²`},
		p.price > 0 && {icon: 'fa-light fa-dollar-sign', label: 'Mức giá', value: fmtPriceLong(p.price, p.transaction_type)},
		p.bedrooms > 0 && {icon: 'fa-light fa-bed', label: 'Phòng ngủ', value: `${p.bedrooms} phòng ngủ`},
		p.bathrooms > 0 && {icon: 'fa-light fa-bath', label: 'Phòng tắm', value: `${p.bathrooms} phòng tắm`},
		p.floors > 0 && {icon: 'fa-light fa-layer-group', label: 'Số tầng', value: `${p.floors} tầng`},
		p.direction_label && {icon: 'fa-light fa-compass', label: 'Hướng', value: p.direction_label},
		p.road_type_label && {icon: 'fa-light fa-road', label: 'Vị trí', value: p.road_type_label},
		p.legal_label && {icon: 'fa-light fa-file-contract', label: 'Pháp lý', value: p.legal_label},
		p.furniture_label && {icon: 'fa-light fa-couch', label: 'Nội thất', value: p.furniture_label},
		p.property_type_label && {icon: 'fa-light fa-folder-open', label: 'Loại hình', value: p.property_type_label},
	].filter(Boolean);

	const cover = gallery[active] || gallery[0];

	return (
		<div className={style.page}>
			<div className={style.container}>

				{/* Thư viện ảnh */}
				{gallery.length > 0 && (
					<section className={style.galleryCard}>
						<div className={style.galleryMain}>
							<Image.PreviewGroup>
								{cover?.type === 'video' ? (
									<video className={style.mainMedia} src={cover.url} controls />
								) : (
									<Image className={style.mainMedia} src={cover?.url} alt={p.title} rootClassName={style.mainImgRoot} />
								)}
								{/* Ảnh còn lại để PreviewGroup gom nhóm lightbox (ẩn; bỏ ảnh đang hiển thị để không trùng) */}
								<div className={style.hiddenPreview}>
									{gallery.map((m, i) => (m.type === 'image' && i !== active
										? <Image key={i} src={m.url} alt="" />
										: null))}
								</div>
							</Image.PreviewGroup>
							{gallery.length > 1 && (
								<span className={style.galleryCount}>{active + 1} / {gallery.length}</span>
							)}
						</div>
						{gallery.length > 1 && (
							<div className={style.thumbs}>
								{gallery.map((m, i) => (
									<button type="button" key={i}
										className={`${style.thumb} ${i === active ? style.thumbActive : ''}`}
										onClick={() => setActive(i)}>
										{m.type === 'video'
											? <><video src={m.url} muted /><span className={style.thumbPlay}><FontAwesomeIcon icon="fa-solid fa-play" /></span></>
											: <img src={m.url} alt="" loading="lazy" />}
									</button>
								))}
							</div>
						)}
					</section>
				)}

				{/* Header: badge + tiêu đề + địa chỉ + giá */}
				<section className={style.card}>
					<div className={style.badges}>
						<span className={`${style.badge} ${style.badgePrimary}`}>{p.transaction_label || (p.transaction_type === 'rent' ? 'Cho thuê' : 'Bán')}</span>
						{p.property_type_label && <span className={style.badge}>{p.property_type_label}</span>}
						<span className={style.badge}>{p.status_label}</span>
					</div>
					<h1 className={style.title}>{p.title || 'Chưa đặt tiêu đề'}</h1>
					{fullAddress && (
						<div className={style.address}>
							<FontAwesomeIcon icon="fa-light fa-location-dot" /> {fullAddress}
						</div>
					)}
					<div className={style.priceRow}>
						<span className={style.price}>{fmtPriceLong(p.price, p.transaction_type)}</span>
						{area > 0 && <span className={style.dot}>·</span>}
						{area > 0 && <span className={style.areaBig}>{fmtNum(area)} m²</span>}
						{p.price_per_m2 > 0 && <span className={style.pricePerM2}>({fmtPriceLong(p.price_per_m2)}/m²)</span>}
					</div>
				</section>

				{/* Đặc điểm bất động sản */}
				{specs.length > 0 && (
					<section className={style.card}>
						<h2 className={style.sectionTitle}>ĐẶC ĐIỂM BẤT ĐỘNG SẢN</h2>
						<div className={style.specGrid}>
							{specs.map((s, i) => (
								<div key={i} className={style.spec}>
									<FontAwesomeIcon icon={s.icon} className={style.specIcon} />
									<span className={style.specLabel}>{s.label}</span>
									<span className={style.specValue}>{s.value}</span>
								</div>
							))}
						</div>
					</section>
				)}

				{/* Mô tả chi tiết */}
				{p.description && (
					<section className={style.card}>
						<h2 className={style.sectionTitle}>MÔ TẢ CHI TIẾT</h2>
						<div className={style.description}>{p.description}</div>
					</section>
				)}

				{/* Bản đồ */}
				{mapQuery && (
					<section className={style.card}>
						<div className={style.mapHead}>
							<h2 className={style.sectionTitle}>XEM TRÊN BẢN ĐỒ</h2>
							<a className={style.mapLink} href={`https://www.google.com/maps?q=${encodeURIComponent(mapQuery)}`} target="_blank" rel="noreferrer">
								<FontAwesomeIcon icon="fa-brands fa-google" /> Xem trên Google Maps
							</a>
						</div>
						<iframe
							title="Bản đồ"
							className={style.map}
							src={`https://maps.google.com/maps?q=${encodeURIComponent(mapQuery)}&z=15&output=embed`}
							loading="lazy"
							referrerPolicy="no-referrer-when-downgrade"
						/>
					</section>
				)}

				{/* BĐS liên quan */}
				{p.related?.length > 0 && (
					<section className={style.card}>
						<h2 className={style.sectionTitle}>BẤT ĐỘNG SẢN DÀNH CHO BẠN</h2>
						<div className={style.relGrid}>
							{p.related.map((item) => <RelatedCard key={item.code} item={item} />)}
						</div>
					</section>
				)}

				<footer className={style.footer}>
					<FontAwesomeIcon icon="fa-light fa-shield-check" /> Thông tin được cung cấp bởi {config.app.name}
				</footer>
			</div>

			{/* Liên hệ NV phụ trách — thanh cố định dưới (mobile) + card nổi (desktop) */}
			{p.contact && (
				<div className={style.contactBar}>
					<div className={style.contactInfo}>
						<div className={style.contactAvatar}><FontAwesomeIcon icon="fa-light fa-user-tie" /></div>
						<div>
							<div className={style.contactLabel}>Liên hệ tư vấn</div>
							<div className={style.contactName}>{p.contact.name}</div>
						</div>
					</div>
					<div className={style.contactActions}>
						<a className={`${style.contactBtn} ${style.contactCall}`} href={`tel:${p.contact.phone}`}>
							<FontAwesomeIcon icon="fa-light fa-phone" /> Gọi ngay
						</a>
						<a className={`${style.contactBtn} ${style.contactZalo}`} href={`https://zalo.me/${p.contact.phone.replace(/[^0-9]/g, '')}`} target="_blank" rel="noreferrer">
							<FontAwesomeIcon icon="fa-solid fa-comment-dots" /> Chat Zalo
						</a>
					</div>
				</div>
			)}
		</div>
	);
}

export default PublicProperty;
