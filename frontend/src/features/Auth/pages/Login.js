import className from 'classnames/bind';
import style from '../style/Login.module.scss';
import {useEffect} from "react";
import {useSelector} from "react-redux";
import {App as AntdApp} from "antd";
import {AuthLoginForm} from "~/features/Auth/components";
import {authErrorSelector} from "~/reduxs/Auth/authSlice";
import {globalNavigate} from "~/routes/GlobalHistory";
import {FontAwesomeIcon} from "~/components";

const cn = className.bind(style);

const FEATURES = [
    {icon: 'fa-light fa-user-lock', text: 'Xác thực JWT & phân quyền linh hoạt'},
    {icon: 'fa-light fa-user-secret', text: 'Đăng nhập vào tài khoản khác (impersonation)'},
    {icon: 'fa-light fa-bell', text: 'Thông báo đẩy tới PC & mobile qua service worker'},
];

function Login() {

    const {notification} = AntdApp.useApp();

    const error = useSelector(authErrorSelector);

    const isLoggedIn = Boolean(localStorage.getItem('access_token'));

    useEffect(() => {
        if (error) {
            notification.error({
                title: 'Thất bại',
                description: error
            });
        }
    }, [error, notification]);

    useEffect(() => {
        if (isLoggedIn) {
            // Không prefix REACT_APP_HOMEPAGE — Router đã có basename (App.js).
            globalNavigate("/");
        }
    }, [isLoggedIn]);

    return (
        <div className={cn('login-container')}>
            <div className={cn('login-connect')}>
                <div className={cn('mask')}></div>

                {/* decorative orbs */}
                <span className={cn('orb', 'orb-1')}></span>
                <span className={cn('orb', 'orb-2')}></span>
                <div className={cn('grid-overlay')}></div>

                <div className={cn('brand')}>
                    <span className={cn('brand-badge')}><FontAwesomeIcon icon="fa-light fa-chart-line"/></span>
                    <span className={cn('brand-name')}>Base App</span>
                </div>

                <div className={cn('content')}>
                    <h2>Nền tảng gốc,<br/>quản trị tập trung.</h2>
                    <p>Bộ khung sẵn có: xác thực, phân quyền, hồ sơ cá nhân và thông báo đẩy — sẵn sàng để dựng dự án mới.</p>

                    <div className={cn('features')}>
                        {FEATURES.map((f, i) => (
                            <div className={cn('feature')} key={i}>
                                <span className={cn('feature-icon')}><FontAwesomeIcon icon={f.icon}/></span>
                                <span>{f.text}</span>
                            </div>
                        ))}
                    </div>

                    {/* Widget trang trí trung tính (tên app + các cột gạch minh hoạ) */}
                    <div className={cn('score-card')}>
                        <div className={cn('score-ring')}>
                            <span className={cn('score-value')}><FontAwesomeIcon icon="fa-light fa-cubes"/></span>
                            <span className={cn('score-label')}>Base App</span>
                        </div>
                        <div className={cn('score-bars')}>
                            <span style={{'--h': '60%'}}></span>
                            <span style={{'--h': '85%'}}></span>
                            <span style={{'--h': '45%'}}></span>
                            <span style={{'--h': '95%'}}></span>
                            <span style={{'--h': '70%'}}></span>
                        </div>
                    </div>
                </div>
            </div>

            <div className={cn('login-form')}>
                <div className={cn('login-form-wrapper')}>
                    <div className={cn('login-logo')}>
                        <span className={cn('login-logo-badge')}><FontAwesomeIcon icon="fa-light fa-chart-line"/></span>
                        <span className={cn('login-logo-name')}>Base App</span>
                    </div>
                    <div className={cn('login-heading')}>
                        <h1>Chào mừng trở lại 👋</h1>
                        <p>Đăng nhập để tiếp tục.</p>
                    </div>
                    <div className={cn('login-form-inner')}>
                        <AuthLoginForm/>
                    </div>
                    <div className={cn('login-footer')}>
                        <div>© {new Date().getFullYear()} Base App.</div>
                    </div>
                </div>
            </div>
        </div>
    )
}

export default Login;
