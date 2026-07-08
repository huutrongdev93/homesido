import {forwardRef} from "react";
import { DatePicker } from "antd";
import dayjs from 'dayjs';
import 'dayjs/locale/vi';
dayjs.locale('vi');
const { RangePicker } = DatePicker;
const rangePresets = [
	{
		label: 'Hôm nay',
		value: [dayjs().startOf('day'), dayjs().endOf('day')],
	},
	{
		label: 'Hôm qua',
		value: [dayjs().add(-1, 'd').startOf('day'), dayjs().add(-1, 'd').endOf('day')],
	},
	{
		label: '7 Ngày trước',
		value: [dayjs().add(-7, 'd').startOf('day'), dayjs().endOf('day')],
	},
	{
		label: 'Tuần này',
		value: [dayjs().startOf('week'), dayjs().endOf('week')],
	},
	{
		label: 'Tuần trước',
		value: [dayjs().subtract(1, 'week').startOf('week'), dayjs().subtract(1, 'week').endOf('week')],
	},
	{
		label: 'Tháng này',
		value: [dayjs().startOf('month'), dayjs().endOf('month')],
	},
	{
		label: 'Tháng trước',
		value: [dayjs().subtract(1, 'month').startOf('month'), dayjs().subtract(1, 'month').endOf('month')],
	}
];

const DateRangeField = forwardRef(({ name, onChange, onBlur, label, value, placeholder, picker, errors, ...props }, ref) => {

	if(label && typeof placeholder == 'undefined') placeholder = label;

	return (
		<div className="form-group">
			{label && <label htmlFor={name}>{label}</label>}
			<RangePicker
				name={name}
				value={value}
				presets={rangePresets}
				onChange={onChange}
				onBlur={onBlur}
				className={[(errors && errors[name]) ? 'error' : '', 'form-control'].filter(Boolean).join(' ')}
				placeholder={placeholder}
				picker={picker}
				popupStyle={{zIndex:10000}}
				{...props}
				ref={ref}
			/>
			{errors && errors[name]?.message && (<p className="error-message">{errors[name]?.message}</p>)}
		</div>
	)
});

export default DateRangeField;