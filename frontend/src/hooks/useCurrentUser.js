import {useSelector} from "react-redux";
import {currentUserSelector} from "~/reduxs/Auth/authSlice";

export default function useCurrentUser() {
	return useSelector(currentUserSelector);
}