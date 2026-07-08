import {createSlice} from "@reduxjs/toolkit";
const authSlice = createSlice({
	name : 'auth',
	initialState : {
		loading : false,
		isLoggedIn : false,
		error : null,
		currentUser : undefined,
	},
	reducers : {
		login(state) {
			state.loading = true;
			state.error = null;
			state.isLoggedIn = false;
		},
		// Token/headers KHÔNG xử lý ở đây — reducer phải thuần; interceptor
		// utils/http.js tự gắn Authorization + loginAsToken theo localStorage mỗi request.
		loginSuccess(state, action) {
			state.loading       = false;
			state.error         = null;
			state.isLoggedIn    = true;
			state.currentUser   = action.payload;
		},
		update(state, action) {
			state.currentUser   = action.payload;
			return state;
		},
		loginFailed(state, action) {
			state.loading       = false;
			state.error = action.payload;
		},
		logout(state) {
			state.isLoggedIn = false;
			state.currentUser = undefined;
		}
	}
});

//Action
export const authActions = authSlice.actions;
//Reducers
export const authReducer = authSlice.reducer;
//Selectors
export const currentUserSelector = (state) => state.auth.currentUser;
export const authErrorSelector = (state) => state.auth.error;
export const isLoginSelector = (state) => state.auth.isLoggedIn;
export const authLoadingSelector = (state) => state.auth.loading;
