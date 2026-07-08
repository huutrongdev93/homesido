import {Input} from "antd";
import {forwardRef} from "react";
const {TextArea} = Input;

const TextAreaField = forwardRef(({ name, onChange, onBlur, label, value, defaultValue, errors, placeholder, ...props }, ref) => {
	if(label && typeof placeholder == 'undefined') placeholder = label;
	if(typeof value == 'undefined') value = defaultValue;
	return (
		<div className="form-group">
			{label && <label htmlFor={name}>{label}</label>}
			<TextArea
				name={name}
				value={value}
				onChange={onChange}
				onBlur={onBlur}
				placeholder={placeholder}
				allowClear
				{...props}
				className={[(errors && errors[name]) ? 'error' : '', 'form-control'].filter(Boolean).join(' ')}
				ref={ref}
			/>
			{errors && errors[name]?.message && (<p className="error-message">{errors[name]?.message}</p>)}
		</div>
	)
});

export default TextAreaField;