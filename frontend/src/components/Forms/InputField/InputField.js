import {Input} from "antd";
import {forwardRef} from "react";

const InputField = forwardRef(({ name, onChange, onBlur, label, value, defaultValue, errors, placeholder, className, ...props }, ref) => {
	if(typeof label == 'string' && typeof placeholder == 'undefined') placeholder = label;
	return (
		<div className="form-group mb-2">
			{label && <label htmlFor={name}>{label}</label>}
			<Input
				name={name}
		        value={value}
		        onChange={onChange}
		        onBlur={onBlur}
				placeholder={placeholder}
				defaultValue={defaultValue}
		        {...props}
		        className={[(errors && errors[name]) ? 'error' : '', 'form-control', className].filter(Boolean).join(' ')}
				ref={ref}
			/>
			{errors && errors[name]?.message && (<p className="error-message">{errors[name]?.message}</p>)}
		</div>
	)
});

export default InputField;