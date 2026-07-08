import FileItem from "./FileItem";

const FileList = ({ files, removeFile }) => {
	return (
		<ul className="file-list">
			{files && files.map((f, index) => (<FileItem key={f.name+index} file={f} deleteFile={removeFile} />))}
		</ul>
	)
}

export default FileList