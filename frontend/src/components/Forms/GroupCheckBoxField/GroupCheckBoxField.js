import {Checkbox} from "antd";
import {forwardRef} from "react";

const GroupCheckBoxField = forwardRef(({ name, options, onChange, label, errors, ...props }, ref) => {
	return (
		<div className="form-group">
			{label && <><label htmlFor={name}>{label}</label><br/></>}
			<Checkbox.Group options={options} onChange={onChange} {...props} ref={ref} />
			{errors && errors[name]?.message && (<p className="error-message">{errors[name]?.message}</p>)}
		</div>
	)
});

export default GroupCheckBoxField;