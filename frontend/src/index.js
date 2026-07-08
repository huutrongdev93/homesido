import React from 'react';
import ReactDOM from 'react-dom/client';
import {Provider} from "react-redux";
import store from "./app/store";
import App from './App';
import reportWebVitals from './reportWebVitals';
import GlobalStyles from "./assets/style";
import AppProvider from "~/context/AppProvider";
import { ConfigProvider, App as AntdApp } from "antd";
import vi_VN from "antd/locale/vi_VN";
import config, { applyThemeConfig } from "~/config";

// Đồng bộ màu chủ đạo (config.json) xuống CSS variables cho SCSS trước khi render.
applyThemeConfig();

const root = ReactDOM.createRoot(document.getElementById('root'));
root.render(
    // <React.StrictMode>
    <GlobalStyles>
        <Provider store={store}>
            <ConfigProvider
                locale={vi_VN}
                theme={{
                    token: {
                        colorPrimary: config.theme.colorPrimary,
                        borderRadius: config.theme.borderRadius,
                        fontFamily: config.theme.fontFamily,
                        fontSize: 14,
                        colorText: '#111827',
                        colorTextSecondary: '#6b7280',
                        colorBorder: '#e5e9f0',
                        colorBorderSecondary: '#eef0f3',
                        colorBgLayout: '#f6f8fb',
                        boxShadowSecondary: '0 12px 32px -12px rgba(16,24,40,.16)',
                    },
                    components: {
                        Menu: {
                            activeBarHeight:0,
                            activeBarWidth:0,
                            itemBorderRadius: 10,
                            itemHeight: 42,
                            horizontalItemBorderRadius: '8px', // bo góc item
                            horizontalItemSelectedBg: config.theme.colorPrimaryDark, // màu nền khi chọn
                            horizontalItemSelectedColor: '#fff', // màu nền khi chọn
                            subMenuItemSelectedColor: '#000' // màu nền submenu
                        },
                        Button: {
                            // Kích thước thoáng + bo góc mềm hơn cho toàn bộ nút
                            controlHeight: 38,
                            controlHeightSM: 30,
                            controlHeightLG: 46,
                            borderRadius: 10,
                            borderRadiusSM: 8,
                            borderRadiusLG: 12,
                            paddingInline: 18,
                            paddingInlineSM: 12,
                            fontWeight: 600,
                            defaultColor: '#344054',
                            defaultBorderColor: '#e3e8ef',
                            // Bóng đổ tinh chỉnh lại trong styles.scss (.ant-btn) để có hover lift
                            primaryShadow: 'none',
                            defaultShadow: 'none',
                            dangerShadow: 'none',
                        },
                        Table: {
                            headerBg: '#f8fafc',
                            headerColor: '#475569',
                            headerSplitColor: 'transparent',
                            rowHoverBg: '#f8fafc',
                            cellPaddingBlock: 12,
                        },
                        Tabs: {
                            itemSelectedColor: config.theme.colorPrimary,
                            inkBarColor: config.theme.colorPrimary,
                            titleFontSize: 14,
                        },
                        Modal: {
                            borderRadiusLG: 16,
                        },
                        Tag: {
                            borderRadiusSM: 6,
                        },
                        // Đồng bộ CHIỀU CAO mọi ô nhập liệu = 44px (khớp form dùng chung,
                        // trước đây các input antd thô ngoài scope `.form` chỉ 32px). Chỉ
                        // áp cho nhóm control nhập liệu — KHÔNG đụng Button/Segmented/...
                        Input: {controlHeight: 44, controlHeightLG: 48, controlHeightSM: 36},
                        InputNumber: {controlHeight: 44, controlHeightLG: 48, controlHeightSM: 36},
                        Select: {controlHeight: 44, controlHeightLG: 48, controlHeightSM: 36},
                        DatePicker: {controlHeight: 44, controlHeightLG: 48, controlHeightSM: 36},
                        Cascader: {controlHeight: 44, controlHeightLG: 48, controlHeightSM: 36},
                        TreeSelect: {controlHeight: 44, controlHeightLG: 48, controlHeightSM: 36},
                        AutoComplete: {controlHeight: 44, controlHeightLG: 48, controlHeightSM: 36},
                        Mentions: {controlHeight: 44, controlHeightLG: 48, controlHeightSM: 36},
                    },
                }}
            >
                <AntdApp component={false}>
                    <AppProvider>
                        <App />
                    </AppProvider>
                </AntdApp>
            </ConfigProvider>
        </Provider>
    </GlobalStyles>
    // </React.StrictMode>
);

// If you want to start measuring performance in your app, pass a function
// to log results (for example: reportWebVitals(console.log))
// or send to an analytics endpoint. Learn more: https://bit.ly/CRA-vitals
reportWebVitals();
