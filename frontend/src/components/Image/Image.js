import style from './Image.module.scss';
import {forwardRef, useState} from "react";
import classNames from "classnames";
import images from '~/assets/images';

const Image = forwardRef(({ src, alt, className, fallback: customFallback = images.noImage, ...props }, ref) => {
	const [fallback, setFallback] = useState('');

	// Ảnh lỗi → chuyển sang ảnh fallback (mặc định no-image).
	const handleError = () => {
		setFallback(customFallback);
	};

	return (
		<img
			className={classNames(style.wrapper, className)}
			ref={ref}
			src={fallback || src}
			alt={alt}
			{...props}
			onError={handleError}
		/>
	);
});

export default Image;