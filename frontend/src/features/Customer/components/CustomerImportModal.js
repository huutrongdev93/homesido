import {useEffect, useRef, useState} from "react";
import {App as AntdApp, Alert, Table} from "antd";
import {ModalForm, FontAwesomeIcon} from "~/components";
import {rtkErrorMessage} from "~/reduxs/api/apiSlice";
import {useImportCustomersMutation} from "~/reduxs/api/customerApiSlice";
import {downloadImportTemplate} from "~/api/customerFileApi";
import style from "../style/Customer.module.scss";

const ACCEPT = '.xlsx,.xls,.csv';

const ERROR_COLUMNS = [
	{title: 'Dòng', dataIndex: 'row', key: 'row', width: 64},
	{title: 'Họ tên', dataIndex: 'name', key: 'name'},
	{title: 'SĐT', dataIndex: 'phone', key: 'phone', width: 120},
	{title: 'Lý do bỏ qua', dataIndex: 'message', key: 'message'},
];

/**
 * Modal nhập khách từ Excel/CSV: chọn tệp → nhập → hiện tổng kết (đã nhập / bỏ qua) + bảng dòng lỗi.
 * Props: open, onClose (đóng + báo page refetch qua invalidate của mutation).
 */
function CustomerImportModal({open, onClose}) {

	const {notification} = AntdApp.useApp();
	const inputRef = useRef(null);

	const [file, setFile] = useState(null);
	const [result, setResult] = useState(null);

	const [importCustomers, {isLoading}] = useImportCustomersMutation();

	useEffect(() => {
		if (open) {
			setFile(null);
			setResult(null);
		}
	}, [open]);

	const pick = () => inputRef.current?.click();

	const onFile = (e) => {
		const f = e.target.files?.[0] || null;
		e.target.value = '';
		setFile(f);
		setResult(null);
	};

	const submit = async () => {
		if (!file) {
			notification.warning({message: 'Chưa chọn tệp', description: 'Vui lòng chọn tệp Excel/CSV để nhập.'});
			return;
		}
		const formData = new FormData();
		formData.append('file', file);
		try {
			const summary = await importCustomers(formData).unwrap();
			setResult(summary);
			notification.success({
				message: 'Đã nhập xong',
				description: `Thêm mới ${summary.created} khách, bỏ qua ${summary.skipped} dòng.`,
			});
		} catch (err) {
			notification.error({message: 'Lỗi', description: rtkErrorMessage(err, 'Nhập khách thất bại')});
		}
	};

	return (
		<ModalForm
			open={open}
			icon="fa-light fa-file-import"
			title="Nhập khách từ Excel"
			subtitle="Chống trùng SĐT tự động — dòng trùng/thiếu sẽ được bỏ qua và liệt kê bên dưới"
			onCancel={onClose}
			onOk={submit}
			okText="Nhập tệp"
			cancelText="Đóng"
			loading={isLoading}
			width={640}
		>
			<div className={style.importPick}>
				<input ref={inputRef} type="file" accept={ACCEPT} hidden onChange={onFile} />
				<button type="button" className={style.importDrop} onClick={pick}>
					<FontAwesomeIcon icon="fa-light fa-cloud-arrow-up" />
					<span>{file ? file.name : 'Chọn tệp .xlsx, .xls hoặc .csv'}</span>
				</button>
				<button type="button" className={style.importTpl} onClick={downloadImportTemplate}>
					<FontAwesomeIcon icon="fa-light fa-download" /> Tải file mẫu
				</button>
			</div>

			<p className={style.importHint}>
				Hàng đầu tiên là tiêu đề cột. Cột bắt buộc: <strong>Họ tên</strong> và <strong>Số điện thoại</strong>.
				Khách nhập vào sẽ do bạn phụ trách.
			</p>

			{result && (
				<div className={style.importResult}>
					<Alert
						type={result.skipped > 0 ? 'warning' : 'success'}
						showIcon
						message={`Đã thêm mới ${result.created}/${result.total} khách${result.skipped > 0 ? `, bỏ qua ${result.skipped} dòng` : ''}.`}
					/>
					{result.errors?.length > 0 && (
						<Table
							className={style.importErrors}
							rowKey="row"
							size="small"
							columns={ERROR_COLUMNS}
							dataSource={result.errors}
							pagination={result.errors.length > 8 ? {pageSize: 8} : false}
							scroll={{y: 240}}
						/>
					)}
				</div>
			)}
		</ModalForm>
	);
}

export default CustomerImportModal;
