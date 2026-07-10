import {Fragment, useEffect} from "react";
import {BrowserRouter as Router, Routes, Route} from 'react-router-dom';
import {App as AntdApp} from "antd";
import {privateRoutes, publicRoutes, PrivateRoutes} from "~/routes";
import RequireCap from "~/routes/RequireCap";
import {DefaultLayout} from "~/layout";
import {GlobalHistory} from "~/routes/GlobalHistory";
import {useCurrentUser} from "~/hooks";
import {bindNotification, routerBasename} from "~/utils";
import config from "~/config";

function resolveLayout(route) {
  if (route.layout) return route.layout;
  if (route.layout === null) return Fragment;
  return DefaultLayout;
}

function App() {

  const {notification} = AntdApp.useApp();

  useCurrentUser();

  // Gắn instance notification có context theme cho code ngoài component (apiError).
  useEffect(() => { bindNotification(notification); }, [notification])

  useEffect(() => { document.title = config.app.title; }, [])

  return (
      // basename = REACT_APP_HOMEPAGE + /{tenant key} (multi-tenant path-based). Mọi path/navigate/
      // Link trong app KHÔNG tự cộng prefix — react-router tự áp basename. Chỉ các chỗ dùng
      // window.location.assign/reload (full URL) mới tự prefix (homePrefix).
      <Router basename={routerBasename()}>
        <GlobalHistory />
        <div className="App">
          <Routes>
            {publicRoutes.map((route) => {
              const Layout = resolveLayout(route);
              const Page = route.component;
              return (
                  <Route key={route.path} path={route.path} element={<Layout><Page /></Layout>}/>
              )
            })}
            {privateRoutes.map((route) => {
              const Layout = resolveLayout(route);
              const Page = route.component;
              // Route có `cap` → gate quyền, không đủ quyền hiện màn NoPermission (giữ layout/sidebar).
              const page = route.cap ? <RequireCap cap={route.cap}><Page /></RequireCap> : <Page />;
              return (
                  <Route key={route.path} path={route.path} element={<PrivateRoutes><Layout>{page}</Layout></PrivateRoutes>}/>
              )
            })}
          </Routes>
        </div>
      </Router>
  );
}

export default App;
