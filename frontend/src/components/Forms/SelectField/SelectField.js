import {Select} from "antd";
import {forwardRef} from "react";

const SelectField = forwardRef(({ children, name, value, defaultValue, onChange, label, options, placeholder, errors, ...props }, ref) => {
	if(label && typeof placeholder == 'undefined') placeholder = label;
	if(typeof value == 'undefined') value = defaultValue;

	function removeAccents(str) {
		if(typeof str != "string") return str;
		// normalize('NFD') tách dấu khỏi ký tự dựng sẵn — thiếu bước này thì regex
		// combining marks (U+0300–U+036F) không match được tiếng Việt có dấu.
		return str.normalize('NFD').replaceAll(/[̀-ͯ]/g, '').replaceAll('đ', 'd').replaceAll('Đ', 'D');
	}

	function filterOption(input, option) {
		const plainText     = removeAccents(option.label);
		if(typeof plainText != "string") return false;
		const searchValue   = removeAccents(input);
		return plainText.toLowerCase().indexOf(searchValue.toLowerCase()) !== -1;
	}

	return (
		<div className="form-group">
			{label && <label htmlFor={name}>{label}</label>}
			<Select name={name} value={value} showSearch filterOption={filterOption} {...props} onChange={onChange} options={options} placeholder={placeholder} ref={ref}>
				{children}
			</Select>
			{errors && errors[name]?.message && (<p className="error-message">{errors[name]?.message}</p>)}
		</div>
	)
});

export default SelectField;
