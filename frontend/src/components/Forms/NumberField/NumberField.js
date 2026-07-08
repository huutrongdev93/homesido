import {InputNumber} from "antd";
import {forwardRef} from "react";

const NumberField = forwardRef(({ name, onChange, onBlur, label, value, defaultValue, errors, placeholder, min, max, step, ...props }, ref) => {
	if(label && typeof placeholder == 'undefined') placeholder = label;
	if(typeof value == 'undefined') value = defaultValue;
	return (
		<div className="form-group">
			{label && <label htmlFor={name}>{label}</label>}
			<InputNumber
				name={name}
				value={value}
				onChange={onChange}
				onBlur={onBlur}
				placeholder={placeholder}
				min={min}
				max={max}
				step={step}
				style={{width: '100%'}}
				{...props}
				className={[(errors && errors[name]) ? 'error' : '', 'form-control'].filter(Boolean).join(' ')}
				ref={ref}
			/>
			{errors && errors[name]?.message && (<p className="error-message">{errors[name]?.message}</p>)}
		</div>
	)
});

export default NumberField;

