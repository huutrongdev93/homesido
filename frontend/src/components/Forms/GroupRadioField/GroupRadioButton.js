import {Radio} from "antd";
import {forwardRef} from "react";

const GroupRadioButton = forwardRef(({ children, name, options, onChange, label, value, errors, ...props }, ref) => {
	return (
		<div className="form-group">
			{label && <><label htmlFor={name}>{label}</label><br/></>}
			<Radio.Group onChange={onChange} value={value} buttonStyle="solid" {...props} ref={ref}>
				{
					options.map((option) => <Radio.Button key={option.value} value={option.value}>{option.label}</Radio.Button>)
				}
			</Radio.Group>
			{errors && errors[name]?.message && (<p className="error-message">{errors[name]?.message}</p>)}
		</div>
	)
});

export default GroupRadioButton;