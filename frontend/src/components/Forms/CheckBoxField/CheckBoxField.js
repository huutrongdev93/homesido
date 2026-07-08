import {Checkbox} from "antd";
import {forwardRef} from "react";

const CheckBoxField = forwardRef(({ children, name, onChange, label, value, errors, ...props }, ref) => {
	return (
		<div className="form-group">
			{label && <><label htmlFor={name}>{label}</label><br/></>}
			<Checkbox name={name} onChange={onChange} {...props} checked={value} ref={ref}>{children}</Checkbox>
			{errors && errors[name]?.message && (<p className="error-message">{errors[name]?.message}</p>)}
		</div>
	)
});

export default CheckBoxField;