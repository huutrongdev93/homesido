import style from './FileUpload.module.scss';
import className from 'classnames/bind';
import {forwardRef} from "react";
import {
	Icon
} from "../../index";
const cn = className.bind(style);

const FileUpload = forwardRef(({ name, description, onChange, files, setFiles, ...props }, ref) => {

	const uploadHandler = (event) => {

		const fileList = event.target.files;

		if(!fileList) return;

		// upload file
		const formData = new FormData();

		for (const file of Object.values(fileList)) {
			formData.append(`files[]`, file, file.name);
		}

		onChange(formData, files, setFiles)

		event.target.value = '';
	}

	return (
		<>
			<div className={cn("file-card")}>
				<div className={cn("file-inputs")}>
					<input name={name} type="file" multiple onChange={uploadHandler} {...props} ref={ref} />
					<button><span>{Icon.plus}</span> Upload</button>
				</div>
				{
					description &&  <p className="color-grey mb-2">{description}</p>
				}
			</div>
		</>
	)
});

export default FileUpload