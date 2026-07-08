import {forwardRef} from "react";
import { DatePicker } from "antd";
import dayjs from 'dayjs';
import 'dayjs/locale/vi';
dayjs.locale('vi');

const DateField = forwardRef(({
								  name,
								  onChange,
								  onBlur,
								  label,
								  value,
								  placeholder,
								  picker,
								  showTime,
								  errors,
								  ...props }, ref) =>
{
	let dateFormat = "DD/MM/YYYY";

	if (picker === "month")
    {
		dateFormat = "MM/YYYY";
	}
    else if (showTime)
    {
		dateFormat = "DD/MM/YYYY HH:mm";
	}

	if(label && typeof placeholder == 'undefined') placeholder = label;

	// Configure showTime to hide OK button and apply time immediately
	const showTimeConfig = showTime ? { format: 'HH:mm' } : false;

	return (
		<div className="form-group">
			{label && <label htmlFor={name}>{label}</label>}
			<DatePicker
				name={name}
				ref={ref}
				picker={picker}
				showTime={showTimeConfig}
				needConfirm={false}
				popupStyle={{zIndex:10000}}
				className={[(errors && errors[name]) ? 'error' : '', 'form-control'].filter(Boolean).join(' ')}
				placeholder={placeholder}
				format={dateFormat}
				value={value ? dayjs(value) : null}
				onChange={(date) => {
					onChange(date ? date.valueOf() : null);
				}}
				onBlur={onBlur}
				{...props}
			/>
			{errors && errors[name]?.message && (<p className="error-message">{errors[name]?.message}</p>)}
		</div>
	)
});

DateField.displayName = 'DateField';

export default DateField;