import {Radio} from "antd";
import {forwardRef} from "react";

const GroupRadioField = forwardRef(({ children, name, options, onChange, label, value, errors, ...props }, ref) => {
	return (
		<div className="form-group">
			{label && <><label htmlFor={name}>{label}</label><br/></>}
			<Radio.Group onChange={onChange} value={value} {...props} ref={ref}>
				{
					options.map((option) => <Radio key={option.value} value={option.value}>{option.label}</Radio>)
				}
			</Radio.Group>
			{errors && errors[name]?.message && (<p className="error-message">{errors[name]?.message}</p>)}
		</div>
	)
});

export default GroupRadioField;