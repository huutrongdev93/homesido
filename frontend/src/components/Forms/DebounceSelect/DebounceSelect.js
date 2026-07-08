import {useEffect, useMemo, useRef, useState} from "react";
import {Select, Spin} from 'antd';
import debounce from 'lodash/debounce';
import {forwardRef} from "react";

// Hằng module-level làm default — nếu dùng `= []` inline thì mỗi render tạo mảng mới
// → dep của useEffect dưới đổi liên tục → setOptions lặp vô hạn.
const EMPTY_OPTIONS = [];

const DebounceSelect = forwardRef(({ fetchOptions, name, value, label, placeholder, errors, onChange, optionsDefault = EMPTY_OPTIONS, ...props }, ref) => {
	const [fetching, setFetching] = useState(false);
	const [options, setOptions] = useState(optionsDefault);
	const fetchRef = useRef(0);
	useEffect(() => {
		optionsDefault && setOptions(optionsDefault)
	}, [optionsDefault])
	const debounceFetcher = useMemo(() => {
		const loadOptions = (value) => {
			fetchRef.current += 1;
			const fetchId = fetchRef.current;
			setOptions([]);
			setFetching(true);
			fetchOptions(value).then((newOptions) => {
				if (fetchId !== fetchRef.current) {
					return;
				}
				setOptions(newOptions);
				setFetching(false);
			});
		};
		return debounce(loadOptions, 500);
	}, [fetchOptions]);
	return (
		<div className="form-group">
			{label && <label htmlFor={name}>{label}</label>}
			<Select
				value={value}
				name={name}
				placeholder={placeholder}
				filterOption={false}
		        onSearch={debounceFetcher} showSearch={true}
		        notFoundContent={fetching ? <Spin size="small" /> : null}
				onChange={onChange}
                options={options}
				ref={ref}
				{...props}
			/>
			{errors && errors[name]?.message && (<p className="error-message">{errors[name]?.message}</p>)}
		</div>
	)
});

export default DebounceSelect;