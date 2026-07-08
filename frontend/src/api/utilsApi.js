import request from "~/utils/http";

const utilsApi = {
	gets : async () => {
		const url = '/utils';
		return await request.get(url);
	}
};

export default utilsApi;