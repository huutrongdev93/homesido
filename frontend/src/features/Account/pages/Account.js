import {useSearchParams} from "react-router-dom";
import {useCurrentUser} from "~/hooks";
import AccountHeader from "../components/AccountHeader";
import AccountFormInfo from "../components/Forms/AccountFormInfo";
import AccountFormPassword from "../components/Forms/AccountFormPassword";

/**
 * Hồ sơ cá nhân — 1 route `/account` với tabs (?tab=info|password) thay cho 2 route
 * `/profile` + `/account/password` cũ (2 route cũ redirect về đây, giữ link không gãy).
 */
function Account() {

	const [params, setParams] = useSearchParams();
	const tab = params.get('tab') === 'password' ? 'password' : 'info';

	const currentUser = useCurrentUser();

	return (
		<div className="container">
			<AccountHeader active={tab} onChange={(key) => setParams(key === 'info' ? {} : {tab: key})} />

			{tab === 'info' ? (
				<AccountFormInfo currentUser={currentUser} />
			) : (
				<AccountFormPassword />
			)}
		</div>
	)
}

export default Account;
