import {configureStore} from "@reduxjs/toolkit";
import {setupListeners} from "@reduxjs/toolkit/query";
import rootReducer from "./reducer";
import {apiSlice} from "~/reduxs/api/apiSlice";

const store = configureStore({
	reducer: rootReducer,
	middleware: (getDefaultMiddleware) =>
		getDefaultMiddleware().concat(apiSlice.middleware),
});

// Bật refetch khi focus lại tab / reconnect mạng cho RTK Query.
setupListeners(store.dispatch);

export default store;
