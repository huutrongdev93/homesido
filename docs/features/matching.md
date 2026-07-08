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
├─ FE  src/features/Matching/pages/Matching.js               # trang /matching: 2 tab (Tabs) — "Tìm BĐS cho khách" (DebounceSelect khách → bảng BĐS gợi ý) + "Tìm khách cho BĐS" (DebounceSelect BĐS → bảng khách gợi ý); nút Gửi mở SendMatchModal. DebounceSelect gọi thẳng GET api/customer|property?keyword= (không route riêng)
│  ├─ src/features/Matching/components/SendMatchModal.js     # modal xác nhận gửi SP + ghi chú (ModalForm + RHF + TextAreaField); presentational — dùng chung ở trang + drawer + panel
│  ├─ src/features/Matching/matchUtils.js                    # fmtPrice (tỷ/tr) + matchScoreColor (tag điểm) — dùng chung
│  ├─ src/features/Matching/style/Matching.module.scss       # .picker (ô chọn) + .reasonChip (chip lý do)
│  ├─ src/reduxs/api/matchingApiSlice.js                     # RTK Query: getSuggestedProperties/getMatchingCustomers/getCustomerMatches + sendPropertyToCustomer/updateMatchStatus; tag 'Match'
│  ├─ src/reduxs/api/apiSlice.js                             # tag 'Match' khai trong tagTypes
│  ├─ src/routes/PrivateRoutes.js                            # route { path:'/matching', component:Matching, cap:'matching_view' } (DefaultLayout)
│  ├─ src/layout/Sidebar/NavBarData.js                       # menu "Kinh doanh" → "Khớp lệnh", gate useCan('matching_view')
│  ├─ src/features/Customer/components/CustomerDetailDrawer.js  # section "Gợi ý bất động sản" (useGetSuggestedPropertiesQuery + nút Gửi → SendMatchModal), gate matching_view/matching_send
│  ├─ src/features/Property/components/PropertyDetailPanel.js   # card "KHÁCH HÀNG PHÙ HỢP" (useGetMatchingCustomersQuery + nút Gửi), gate matching_view/matching_send
│  └─ src/context/AppProvider.js                             # appData.matching.statuses (enum tĩnh: sent/interested/rejected)
└─ BE  routes/api.php  (route lồng trong prefix api/customer + api/property, middleware jwt)
       ├─ app/Services/Matching/MatchEngine.php              # HÀM THUẦN: matchQueryForDemand (query kho theo 1 nhu cầu) + matchesProperty (1 BĐS thỏa 1 nhu cầu?) + score/reasons (0–100)
       ├─ app/Controllers/Api/CustomerApi.php                # matchProperties / matches (lịch sử) / sendMatch / updateMatchStatus + helpers demandsToMatch/bestScoreFor/transformMatchProperty
       ├─ app/Controllers/Api/PropertyApi.php                # matchCustomers (gợi ý khách cho BĐS)
       ├─ app/Controllers/Api/UtilsApi.php::index            # enum matching.statuses cho FE
       ├─ app/Roles/RoleCapabilitiesMatching.php             # cap: matching_view / matching_send
       ├─ app/Roles/register.php                             # khai nhóm cap 'matching' (label "Khớp lệnh (Matching)")
       ├─ app/Models/PropertyCustomerMatch.php               # bảng property_customer_matches
       └─ bảng DB: property_customer_matches (database/matching.php)
```

## Cap / Route

- **Cap** (gate trong controller; administrator/root bypass):
  `matching_view` (xem gợi ý + vào trang /matching), `matching_send` (gửi SP cho khách + đổi trạng thái).
- **Route Khách** (`jwt`, prefix `api/customer`): `GET /{id}/match-properties?demand_id=` (gợi ý BĐS),
  `GET /{id}/matches` (lịch sử đã gửi), `POST /{id}/matches` (gửi SP — body `property_id`,`demand_id?`,`note?`),
  `PUT /{id}/matches/{matchId}` (đổi `status`/`note`).
- **Route BĐS** (`jwt`, prefix `api/property`): `GET /{id}/match-customers` (gợi ý khách).
- **Response**: các endpoint gợi ý trả **mảng phẳng** (không phân trang) — trần `MATCH_LIMIT = 50`.

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
