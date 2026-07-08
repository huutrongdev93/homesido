import FontAwesomeIcon from "../../FontAwesome";
import style from './FileUpload.module.scss';
import {useState} from "react";
import className from 'classnames/bind';
import Button from "../../Button";
import {Image} from "antd";
const cn = className.bind(style);
const FileItem = ({ file, deleteFile }) => {

	const [visible, setVisible] = useState(false);

	let extension = 'unknown';

	let icon = <FontAwesomeIcon icon="fa-light fa-files" />;

	if (file.name.endsWith(".png")
		|| file.name.endsWith(".jpg")
		|| file.name.endsWith(".jpeg")
		|| file.name.endsWith(".bmp")
		|| file.name.endsWith(".svg")
		|| file.name.endsWith(".ico")
		|| file.name.endsWith(".webp")
		|| file.name.endsWith(".gif")) {
		extension = 'image';
		icon = <FontAwesomeIcon icon="fa-duotone fa-image" />
	} else if (file.name.endsWith(".xls") || file.name.endsWith(".xlsx")) {
		extension = 'excel';
		icon = <FontAwesomeIcon icon="fa-duotone fa-file-excel" />
	} else if (file.name.endsWith(".pdf")) {
		extension = 'pdf';
		icon = <FontAwesomeIcon icon="fa-duotone fa-file-pdf" />
	} else if (file.name.endsWith(".doc") || file.name.endsWith(".docx")) {
		extension = 'word';
		icon = <FontAwesomeIcon icon="fa-duotone fa-file-word" />
	}

	const onclickDelete = () => {
		deleteFile(file.id)
	}

	return (
		<>
			<li className={cn("file-item", {[extension] : extension})+' gap-1'} key={file.name}>
				<div className={cn("icon")}>{icon}</div>
				<p>{file.name}</p>
				<div className={cn("actions")}>
					{(extension === 'image') && <Button type="button" background blue onClick={() => setVisible(true)}>Xem</Button>}
					{(extension === 'excel' || extension === 'pdf' || extension === 'word') &&
						<Button background small blue target={"_blank"} href={"https://docs.google.com/gview?url=" + file.path}>Xem</Button>}
					{(extension !== 'image') && <Button background small blue href={file.path}>Tải</Button>}
					{deleteFile && <Button background small red leftIcon={<FontAwesomeIcon icon="fa-light fa-trash-can" />} onClick={onclickDelete}></Button>}
				</div>
				{
					(extension === 'image') &&
					<Image
						rootClassName={cn("image")}
						style={{display: 'none'}}
						preview={{visible, src: file.path, onVisibleChange: (value) => {setVisible(value);},}}
					/>
				}
			</li>
		</>
	)
}

export default FileItem