# Matching khách ↔ BĐS (GĐ2)

Biến tiêu chí `customer_demands` (nhu cầu khách) thành **gợi ý hành động**: gợi ý BĐS phù hợp cho
1 khách, gợi ý khách phù hợp cho 1 BĐS, và **"gửi sản phẩm cho khách"** (ghi lịch sử + 1 tương tác
vào timeline). Nền DB: [`../database.md`](../database.md) §4b (`property_customer_matches`).

**Ý tưởng cốt lõi:** so khớp **tính on-the-fly** (không precompute — tránh lỗi thời) bằng
`MatchEngine` (hàm thuần, giống `LeadScorer`). Bảng `property_customer_matches` chỉ **log hành động
gửi SP** (như `customer_transfers`), không cache match.

## Bản đồ file (front-to-back)

```
Matching (GĐ2)
├─ FE  src/features/Matching/pages/Matching.js               # trang /matching: 3 tab (Tabs) — TAB MẶC ĐỊNH "Cơ hội của tôi" (bảng cặp khách-của-tôi ↔ BĐS khớp, KHÔNG cần chọn tay — màn hình đích của thông báo đẩy) + "Tìm BĐS cho khách" (DebounceSelect khách → bảng BĐS gợi ý, cột ảnh đại diện) + "Tìm khách cho BĐS" (DebounceSelect BĐS → bảng khách gợi ý); nút Gửi mở SendMatchModal. DebounceSelect gọi thẳng GET api/customer|property?keyword= (không route riêng)
│  ├─ src/features/Matching/components/SendMatchModal.js     # modal xác nhận gửi SP + ghi chú (ModalForm + RHF + TextAreaField); presentational — dùng chung ở trang + drawer + panel
│  ├─ src/features/Matching/matchUtils.js                    # fmtPrice (tỷ/tr) + matchScoreColor (tag điểm) — dùng chung
│  ├─ src/features/Matching/style/Matching.module.scss       # .picker (ô chọn) + .reasonChip (chip lý do)
│  ├─ src/reduxs/api/matchingApiSlice.js                     # RTK Query: getMatchOverview (Cơ hội của tôi) + getSuggestedProperties/getMatchingCustomers/getCustomerMatches + sendPropertyToCustomer/updateMatchStatus; tag 'Match'
│  ├─ src/reduxs/api/apiSlice.js                             # tag 'Match' khai trong tagTypes
│  ├─ src/routes/PrivateRoutes.js                            # route { path:'/matching', component:Matching, cap:'matching_view' } (DefaultLayout)
│  ├─ src/layout/Sidebar/NavBarData.js                       # menu "Kinh doanh" → "Khớp lệnh", gate useCan('matching_view')
│  ├─ src/features/Customer/components/CustomerDetailDrawer.js  # section "Gợi ý bất động sản" (useGetSuggestedPropertiesQuery + nút Gửi → SendMatchModal), gate matching_view/matching_send
│  ├─ src/features/Property/components/PropertyDetailPanel.js   # card "KHÁCH HÀNG PHÙ HỢP" (useGetMatchingCustomersQuery + nút Gửi), gate matching_view/matching_send
│  └─ src/context/AppProvider.js                             # appData.matching.statuses (enum tĩnh: sent/interested/rejected)
└─ BE  routes/api.php  (route lồng trong prefix api/customer + api/property, middleware jwt)
       ├─ app/Services/Matching/MatchEngine.php              # HÀM THUẦN: matchQueryForDemand (query kho theo 1 nhu cầu) + matchesProperty (1 BĐS thỏa 1 nhu cầu?) + score/reasons (0–100)
       ├─ app/Services/Matching/MatchScanner.php             # AUTO-MATCHING tick: quét BĐS/nhu cầu cờ match_scanned=0 → digest Notifier cho sales → set cờ 1 (xem §Auto-matching)
       ├─ app/Console/schedule.php                           # đăng ký tick 'match-scan-tick' (everyMinute)
       ├─ database/matching-scan.php                         # migration cột match_scanned (properties + customer_demands) + backfill hàng cũ=1
       ├─ app/Controllers/Api/CustomerApi.php                # matchOverview (Cơ hội của tôi — cặp khách↔BĐS) / matchProperties / matches (lịch sử) / sendMatch / updateMatchStatus + helpers demandsToMatch/bestScoreFor/transformMatchProperty (kèm `thumbnail` — ảnh đại diện BĐS)
       ├─ app/Services/Storage/PropertyMediaService.php      # thumbnails($rows) — giải ảnh đại diện lô BĐS (dùng chung list BĐS + gợi ý matching); xem [media.md](media.md)
       ├─ app/Controllers/Api/PropertyApi.php                # matchCustomers (gợi ý khách cho BĐS)
       ├─ app/Controllers/Api/UtilsApi.php::index            # enum matching.statuses cho FE
       ├─ app/Roles/RoleCapabilitiesMatching.php             # cap: matching_view / matching_send
       ├─ app/Roles/register.php                             # khai nhóm cap 'matching' (label "Khớp lệnh (Matching)")
       ├─ app/Models/PropertyCustomerMatch.php               # bảng property_customer_matches
       └─ bảng DB: property_customer_matches (database/matching.php)
```

## Auto-matching (tick nền — chủ động đẩy cơ hội)

Bản gốc matching là **kéo** (sales tự mở tab dò). Auto-matching biến thành **đẩy**: khi có BĐS/nhu cầu
mới, tick nền tự so khớp và **báo cho sales phụ trách khách** — không thao tác tay.

- **Cơ chế:** cờ int `match_scanned` trên `properties` + `customer_demands` (0=chưa quét, 1=đã quét;
  KHÔNG dùng datetime NULL vì base Model tự điền `''` cho cột không truyền). Điểm ghi đặt cờ 0:
  `PropertyApi::add` (BĐS mới), `CustomerApi::addDemand` (nhu cầu mới), `CustomerApi::updateDemand`
  (đổi tiêu chí → quét lại). Tick `match-scan-tick` (`MatchScanner::tick`, mỗi phút) nhặt cờ 0, so
  khớp **tái dùng `MatchEngine`** (on-the-fly), rồi set cờ 1.
- **2 chiều + thông báo digest** (gom theo sales, 1 thông báo/sales/lô/chiều, `Notifier::send` type `info`, link `/matching`):
  - BĐS mới `available` khớp nhu cầu active → báo sales **phụ trách các khách** khớp: *"Có N BĐS mới khớp khách của bạn"*.
  - Nhu cầu mới/đổi có BĐS khớp trong kho (của sales + kho chung `shared`) → báo sales phụ trách khách: *"N khách của bạn vừa có BĐS phù hợp"*.
- **Chống spam/tải:** mỗi bản ghi quét đúng 1 lần (cờ→1) nên không cần `sendUnique`; lô giới hạn
  `PROPERTY_BATCH=100` / `DEMAND_BATCH=200`; chiều nhu cầu chỉ báo khách có sales (`assigned_user_id>0`,
  bỏ qua kho chung). Migration **backfill toàn bộ dữ liệu cũ = 1** (guard `hasColumn`, chạy 1 lần) để
  lần bật đầu tiên không bắn thông báo hàng loạt cho kho/nhu cầu hiện có.
- **Quét lại khi khách ĐỔI CHỦ (GĐ2.1a):** khách ở kho chung bị `scanNewDemands` bỏ qua (chưa có sales)
  nhưng vẫn set cờ=1 → nếu không xử lý, người nhận sau này KHÔNG bao giờ được báo. Đã bịt: helper
  `CustomerApi::rescanDemandsForNewOwner($customerId)` reset `match_scanned=0` cho nhu cầu `is_active=1`
  của khách, gọi tại MỌI điểm đổi chủ: `update` (nhận từ kho chung / admin đổi phụ trách — chỉ khi
  `assigned_user_id` thực sự đổi), `addInteraction` + `sendMatch` (auto-claim từ kho chung), `transfer`
  (bàn giao X→Y). Tick kế sẽ quét lại và báo BĐS khớp cho chủ mới. Guard `hasColumn` (an toàn khi
  chưa migrate). Không thêm cột/route/cap.
- **Giới hạn có chủ đích (GĐ2.1):** chỉ BĐS/nhu cầu **mới** (hoặc khách đổi chủ, xem trên) kích hoạt.
  **Sửa BĐS (kể cả chuyển sang `available`) KHÔNG quét lại** (tránh churn thông báo mỗi lần sửa) — nếu
  cần, nâng cấp bằng cách reset cờ khi `status` chuyển vào `available` ở `PropertyApi::update`.
- **Bật:** cần chạy `GET api/utils/database` (áp `database/matching-scan.php`) + cron gọi `schedule-run` mỗi phút.

## "Cơ hội của tôi" (match-overview — màn hình đích của thông báo)

Thông báo đẩy auto-matching ("N khách của bạn vừa có BĐS phù hợp") link `/matching`. Trước đây trang này
mở ra **trống** (2 tab đều phải chọn tay khách/BĐS) → sales không biết khách nào ↔ BĐS nào. Đã thêm **tab
mặc định "Cơ hội của tôi"** hiện thẳng danh sách cặp:

- **Endpoint** `GET api/customer/match-overview` (`CustomerApi::matchOverview`, cap `matching_view`, đặt
  TRƯỚC `/{id}` trong routes để không bị nuốt). Trả **mảng phẳng** các cặp `{customer_*, property..., score,
  reasons, demand_id, already_sent}`, sắp theo `score` giảm dần.
- **Logic** (on-the-fly, tái dùng `MatchEngine`): nạp **khách CỦA TÔI** (`assigned_user_id = tôi`) + nhu cầu
  `is_active=1`, nạp **kho khả kiến 1 lần** (available, của tôi + `shared` khi không có `property_view_all`),
  rồi so từng nhu cầu × từng BĐS trong PHP (`matchesProperty`+`score`+`reasons`) → gộp theo cặp (khách,BĐS)
  giữ điểm cao nhất. Trần: `OVERVIEW_CUSTOMER_CAP=300` / `OVERVIEW_STOCK_CAP=800` / `OVERVIEW_PAIR_LIMIT=100`.
- **Chỉ khách của tôi** (bỏ kho chung) — khớp ngữ nghĩa thông báo per-sales; quyền `property_view_all` mở
  rộng kho khả kiến nhưng vẫn chỉ khách mình phụ trách. Nút Gửi tái dùng `SendMatchModal` + `sendPropertyToCustomer`.

## Cap / Route

- **Cap** (gate trong controller; administrator/root bypass):
  `matching_view` (xem gợi ý + vào trang /matching), `matching_send` (gửi SP cho khách + đổi trạng thái).
- **Route Khách** (`jwt`, prefix `api/customer`): `GET /match-overview` (Cơ hội của tôi — cặp khách↔BĐS, đặt
  TRƯỚC `/{id}`), `GET /{id}/match-properties?demand_id=` (gợi ý BĐS),
  `GET /{id}/matches` (lịch sử đã gửi), `POST /{id}/matches` (gửi SP — body `property_id`,`demand_id?`,`note?`),
  `PUT /{id}/matches/{matchId}` (đổi `status`/`note`).
- **Route BĐS** (`jwt`, prefix `api/property`): `GET /{id}/match-customers` (gợi ý khách).
- **Response**: các endpoint gợi ý trả **mảng phẳng** (không phân trang) — trần `MATCH_LIMIT = 50`.
- **Ảnh đại diện BĐS**: payload gợi ý BĐS (match-properties) + lịch sử (matches) kèm field `thumbnail`
  (URL, null nếu chưa có ảnh) — giải qua `PropertyMediaService::thumbnails()` (1 truy vấn/lô, cùng logic
  danh sách BĐS: cover đã chọn else ảnh đầu). FE hiện ô ảnh ở bảng /matching + mục gợi ý trong drawer khách.

## Match engine (MatchEngine)

- **Chỉ nhu cầu `buy`/`rent`** so với kho (buy→`sale`, rent→`rent`); `sell`/`consign` bỏ qua.
- **Hard filter** (loại cứng): `status='available'` + `transaction_type` + `property_type` (nếu nhu cầu có)
  + `province_code` (nếu có) + giá `price` trong `[budget_min×0.9, budget_max×1.1]` (nếu `budget_max>0`).
- **Score 0–100** (mềm, cộng dồn): base 40 + cùng phường +20 + giá trong khoảng chuẩn +15 + diện tích
  (`area_usable`, thiếu thì `area_land`) trong khoảng +15 + đủ `bedrooms_min` +5 + đúng hướng +5.
- `reasons()` trả nhãn VN song song điểm (chip "Đúng phường/xã", "Trong tầm giá"…).
- 2 chiều dùng chung: chiều Khách→BĐS dựng query (`matchQueryForDemand`); chiều BĐS→Khách lọc sơ
  nhu cầu ở SQL (loại/khu vực) rồi verify chính xác bằng `matchesProperty` ở PHP (1 nguồn logic).

## Data-scope

- **match-properties** (Khách→BĐS): scope KHÁCH qua `findOwned`; BĐS gợi ý áp scope **kho chung**
  (của tôi + `visibility='shared'`) khi không có `property_view_all`.
- **match-customers** (BĐS→Khách): scope BĐS qua `findViewable`; khách gợi ý áp scope **của tôi + kho
  chung chưa khóa** khi không có `customer_view_all`.

## Gửi SP cho khách (POST /{id}/matches)

1. **Upsert** 1 dòng `property_customer_matches` theo cặp (khách, BĐS) — gửi lại thì cập nhật
   `score`/`status='sent'`/`note` thay vì tạo trùng. Điểm = nhu cầu khớp tốt nhất (`bestScoreFor`).
2. Tạo 1 `customer_interaction` (type `note`, "Đã gửi SP `<code>` — `<title>`", direction `out`) → timeline.
3. Auto-claim khách kho chung (giống `addInteraction`) + `Customer::touch()` (gia hạn khóa, gỡ cờ nguội)
   + `LeadScorer::recompute()`.
→ FE invalidate `['Match','Interaction','Customer']` → drawer/timeline/list tự refetch.

## Gotcha

- **Không precompute match** — mọi gợi ý tính lúc gọi API. Không có "bảng match" để đồng bộ; sửa
  nhu cầu/BĐS là gợi ý đổi ngay lần gọi kế.
- **`status` là string** (`sent`/`interested`/`rejected`), KHÔNG enum — base Model ép `''` cho cột
  không truyền (xem [`../database.md`](../database.md) §2). Whitelist ở `updateMatchStatus`.
- **DebounceSelect** ở trang /matching gọi thẳng `~/utils/http` (`request`) tới `GET api/customer|property`
  — KHÔNG qua RTK Query (giống `customerFileApi`); `fetchOptions` trả `[{value,label}]`.
- **Ngân sách khách lưu VNĐ** (form nhập triệu ×1e6) để so trực tiếp `properties.price` (VNĐ) — xem
  [customer.md](customer.md) (Nhu cầu) + [property.md](property.md) (giá).
- Gotcha chung (base Model ép `''`/`0`, data-scope thủ công, lỗi nghiệp vụ `->setStatusCode(422)`,
  "Invalid token" che exception): xem [customer.md](customer.md), [../database.md](../database.md) §2.
