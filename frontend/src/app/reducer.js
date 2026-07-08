import {combineReducers} from "@reduxjs/toolkit";
import {authReducer} from '~/reduxs/Auth/authSlice';
import {apiSlice} from '~/reduxs/api/apiSlice';

const rootReducer = combineReducers({
	auth                    : authReducer,
	[apiSlice.reducerPath]  : apiSlice.reducer,
});

export default rootReducer;