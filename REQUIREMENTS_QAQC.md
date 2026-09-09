# TÀI LIỆU YÊU CẦU CHỨC NĂNG — KHOA QAQC

Plugin: `io.eclo.qaqc` (Kho QAQC)
Source: `C:\xampp\htdocs\Tan\io.eclo.qaqc` (git repo → `github.com/hoanggtan02/io.eclo.qaqc.git`)
Framework: ECLO (app NGOCHIENNEW), DB production `103.82.193.26/ngochien_database`

## 1. Mục tiêu

Quản lý ngọc thô theo từng **Lô sản xuất** đi qua 5 kho nội bộ theo thứ tự, mỗi lô chứa nhiều **loại ngọc** (pearl) khác nhau, mỗi loại có đơn vị kg và/hoặc viên.

```
Nhập kho Vệ Sinh (VS) → Kho Khoan Xuyên (KX) → Kho Lưu Trữ (LT) → Kho Chế Tác (CT) → Kho Thành Phẩm (TP)
```

Nguyên tắc chung trên **mọi màn hình**:
- Danh sách kho luôn hiển thị **theo TỪNG DÒNG NGỌC** (mỗi hàng = 1 loại ngọc thuộc 1 lô), KHÔNG gom theo mã lô.
- Sau mỗi lần chuyển kho phải **tạo phiếu chuyển kho** lưu lịch sử, và có **action xem phiếu** để người dùng xem lại chi tiết từng lần chuyển.

## 2. Quy trình nghiệp vụ

### Bước 1 — Tạo lô & Nhập kho Vệ Sinh (VS)
- Gộp **1 bước duy nhất**: cùng lúc khai báo + thực nhận ngọc thô vào VS.
- Nhập: **Mã lô** (bắt buộc, không được trùng `production_batches.code`, tìm ở `production_stage_movement_items`), **ghi chú**, và **nhiều dòng chi tiết** — mỗi dòng: 1 loại ngọc + kg thực nhận và/hoặc số viên thực nhận (tối thiểu 1 trong 2 > 0). Cho phép nhập **viên hoặc ký** tùy loại ngọc.
- Trong **1 transaction**: tạo `production_batches` (status='A', current_stage=VS) → `production_batch_items` (weight_kg_initial / amount_initial) → `production_stage_movements` (type='import', stage=VS) → `production_stage_movement_items`.
- Chống trùng mã: check trước khi tạo + check lại trong transaction (race condition).

### Bước 2 — Chuyển kho nội bộ (VS ↔ KX, 2 chiều)
- Màn **chuyển kho đa lô**: hiện toàn bộ dòng ngọc còn tồn ở kho nguồn (nhiều lô), tick chọn, nhập kg/viên chuyển (auto-fill = toàn bộ tồn khi tick).
- **VS → KX**: chuyển nguyên trạng (kg + viên). Giảm so với tồn → phần chênh lệch ghi **hao hụt** (`weight_kg_hao_hut` / `amount_hao_hut`).
- **KX → VS** (ngược): như trên; số viên chuyển không được vượt tồn.
- Mỗi lô được tick → tạo **cặp phiếu** (export kho nguồn chốt hao hụt + import kho đích) trong 1 transaction.

### Bước 3 — KX → LT : Chuyển đổi trừ kg, nhập tay viên (chế độ CONVERT — bước khoan xuyên)
- Người dùng **chọn ngọc** cần chuyển, nhập **Kg đưa khoan** + **Viên sau khoan** (viên bắt buộc > 0) → hệ thống ghi nhận **hao hụt = kg đưa khoan − số viên tạo ra** (quy đổi hao hụt).
- Xuất khỏi KX: `weight_kg` = kg khoan (trừ khỏi tồn KX), `weight_kg_hao_hut` = kg chưa khoan còn lại ở KX.
- Nhập vào LT: `weight_kg = 0` (LT **chỉ lưu viên**), `amount` = số viên nhập (sau khoan xuyên).
- Lưu ý dev: `weight_kg_hao_hut` / `amount_hao_hut` **không tham gia tính tồn** (tồn = import − export theo `weight_kg`/`amount`); là cột ghi chú nghiệp vụ.

### Bước 4 — LT → CT : Thẩm định ngọc (Appraisal)
- Nút "Thẩm định ngọc" thay cho "chuyển kho" ở LT. Bước này **thao tác TỪNG VIÊN**:
  - Với mỗi viên: nhập **mã ngọc tay** (validate không trùng) + size/màu.
  - Thẩm định xong → chuyển sang **Kho Chế Tác** theo nhóm: Vàng (`crafting[...]`), Bạc (`craftingsilver[...]`), Chuỗi (`craftingchain[...]`).
- Ghi `ingredient` (type=2 = ngọc) + lịch sử `production_appraisal` (có cột `group_crafting`, `price`, `cost`); nhập **giá**.

### Bước 5 — CT → TP : Nghiệm thu Chế tác
- `/crafting/{id}` + `/crafting-process/{id}`: nghiệm thu lô CT, tạo sản phẩm (mã SP, tên, nhóm, đơn vị, giá/cost, nhân viên, hao hụt đai...), chuyển sang TP.

### Bước 6 — TP : 3 hướng xuất
- **Xuất bán** `/export-sell/{id}` (khách hàng + giá bán).
- **Xuất Kho TP** `/export-products/{id}` (sang kho/branch khác).
- **Xuất Kho NL** `/export-ingredient/{id}` (xuất nguyên liệu).

## 3. Quy tắc tồn kho (CỐT LÕI)

- **Tồn = Σ(nhập) − Σ(xuất)** cho từng cặp **(batch, stage, pearl)**, chỉ trên dòng `deleted=0`.
- **Lô song song 2 kho**: chuyển từng phần nên 1 lô có thể còn tồn ở 2 kho cùng lúc → danh sách kho **KHÔNG lọc theo `current_stage`**, chỉ show dòng có tồn thật tại kho đó.
- **`current_stage`** = "mũi tiến xa nhất còn tồn": tính lại sau mỗi chuyển = stage có tồn > 0 cao nhất theo `['VS'=>1,'KX'=>2,'LT'=>3,'CT'=>4,'TP'=>5]`.
- Helpers tái sử dụng: `getBatchStockAtStage`, `getStageStockDetails` (tồn chi tiết theo batch+pearl của 1 kho), `getBatchStagesWithStock`.

## 4. MÔ TẢ GIAO DIỆN CHI TIẾT (theo từng kho)

### 4.0 Màn xem phiếu chuyển kho (Lịch sử) — ĐÃ XÁC NHẬN
- **Yêu cầu (đã chốt)**: mọi bước chuyển kho (kể cả nhập vào VS, chuyển VS↔KX, khoan xuyên KX→LT, thẩm định LT→CT) đều **tạo phiếu** + ghi lịch sử.
- **Lịch sử chuyển của TỪNG KHO**: mỗi kho (VS/KX/LT) có **1 trang riêng** liệt kê các phiếu của chính kho đó (cả phiếu nhập + phiếu xuất).
- Mỗi phiếu hiển thị: **Mã phiếu** · **Loại phiếu** (Nhập/Xuất/Chuyển) · **Mã lô** · **Kho nguồn → Kho đích** · **Ngày** · **Người thao tác** · **Số dòng ngọc**.
- Có nút **"Xem chi tiết"** → bấm vào **load modal** lên xem chi tiết: liệt kê từng dòng ngọc (loại ngọc, mã lô, số kg, số viên, hao hụt kg/viên).
- Vị trí truy cập: nút **"Lịch sử chuyển"** ở góc trên màn kho (cạnh bộ lọc), mở sang trang lịch sử riêng của kho đó.

### 4.1 KHO VỆ SINH (VS) — `/stage/VS`
**Chức năng chính:**
- **Action "+ Nhập ngọc"**: mở màn nhập ngọc mới vào kho — người dùng khai báo lô + **nhập viên hoặc nhập ký** (nhiều dòng; mỗi dòng: loại ngọc + số kg và/hoặc số viên), lưu trong 1 lần → kinh khi tạo lô.
- **Hiển thị các ngọc hiện có trong Kho Vệ Sinh** theo bảng dưới (mỗi hàng = 1 loại ngọc của 1 lô):

```
| Loại Ngọc | Mã lô | Số ký/viên | Ngày vào kho |
|-----------|-------|------------|--------------|
| NN        | ML01  | 12 kg      | 09/09/2026   |
| MA        | ML01  | 4 kg       | 09/09/2026   |
| MA        | ML02  | 1 kg       | 09/09/2026   |
```

- Cột **Số ký/viên**: hiển thị đúng đơn vị theo loại ngọc (kg hoặc viên; nếu loại có cả 2 thì hiện `12 kg · 5 viên`).

**Action chuyển kho (chung, 1 nút duy nhất ở góc trên trang):**
- Nút **"Chuyển kho"** ở header (cạnh nút "Lịch sử chuyển") → bấm vào **chuyển sang 1 trang khác** (giao diện chuyển kho VS→KX):
  - Liệt kê tất cả các dòng ngọc đang có ở **Kho Vệ Sinh** có thể chuyển.
  - Người dùng **tick chọn các ngọc** muốn chuyển (nhiều dòng 1 lúc).
  - Với mỗi dòng: nhập/chọn **số ký hoặc số viên** cần chuyển (mặc định auto-fill toàn bộ tồn khi tick).
  - Xác nhận → tạo phiếu chuyển → ngọc sang **Kho Khoan Xuyên**, lưu lịch sử.
- Lịch sử nhập/chuyển xem qua nút **"Lịch sử chuyển"** ở header (xem mục 4.0). *(đã chốt: KHÔNG làm nút xem phiếu theo từng dòng)*

### 4.2 KHO KHOAN XUYÊN (KX) — `/stage/KX`
**Hiển thị: giống hệt Kho Vệ Sinh** — bảng các ngọc hiện có ở Kho Khoan Xuyên:

```
| Loại Ngọc | Mã lô | Số ký/viên | Ngày vào kho |
|-----------|-------|------------|--------------|
| NN        | ML01  | 8 kg       | 10/09/2026   |
```

**Action — Chuyển kho (1 nút chung ở header, như VS):**
- Nút **"Chuyển kho"** ở header → mở màn chuyển kho đa lô của KX, **chọn hướng chuyển**:
- Giao diện như VS→KX nhưng ngược: tick chọn ngọc + nhập số ký/viên → chuyển ngược về VS, lưu phiếu + lịch sử.
- Tồn viên chuyển không được vượt tồn viên hiện tại.

**Action 2 — Chuyển sang Kho Lưu Trữ (KX → LT, khoan xuyên):**
- Nút chuyển kho → chọn hướng **"Sang Kho Lưu Trữ"**.
- Giao diện chuyển với **chế độ quy đổi hao hụt**:
  - Tick chọn ngọc cần chuyển.
  - Nhập **Kg đưa khoan** (trừ khỏi tồn KX).
  - Nhập **Số viên được tạo ra sau khi khoan xuyên** (viên bắt buộc > 0).
  - Hệ thống tự ghi nhận **hao hụt = kg đưa khoan − viên tạo ra**, lưu vào phiếu.
- Kết quả: **Kho Lưu Trữ chỉ lưu trữ dạng viên** (không lưu kg).

### 4.3 KHO LƯU TRỮ (LT) — `/stage/LT`
**Đặc điểm:** lưu trữ ngọc đã **khoan xuyên nên chỉ tồn dạng viên** (kg về 0).

**Hiển thị:**

```
| Loại Ngọc | Mã lô | Số viên   | Ngày vào kho | Thao tác            |
|-----------|-------|-----------|--------------|---------------------|
| NN        | ML01  | 12 viên   | 11/09/2026   | [Thẩm định ngọc]    |
```

**Action — Thẩm định ngọc (LT → CT):**
- Nút **"Thẩm định ngọc"** theo từng dòng (mỗi dòng = 1 lô) → mở màn làm việc **từng viên**:
  - Danh sách từng viên của ngọc trong lô; với mỗi viên: nhập **mã ngọc** + **size/màu**.
  - Chọn **kho chế tác đích**: **Bạc / Vàng / Chuỗi** (tương ứng công thức chế tác).
  - Nhập **giá** (nếu có).
- Thẩm định xong → viên đó chuyển sang **Kho Chế Tác (CT)** theo nhóm đã chọn; lưu phiếu + lịch sử.

## 5. Màn chuyển kho đa lô `/stage-transfer/{code}` (gọi chung các giao diện chuyển ở 4.1/4.2)

- Cho kho VS/KX; kho đích theo `['VS'=>['KX'], 'KX'=>['VS','LT'], 'LT'=>[]]`.
- Bảng: checkbox + mã lô + ngọc + tồn kg + tồn viên + ô nhập kg + ô nhập viên.
- JS đổi nhãn theo chế độ convert (KX→LT: "Kg đưa khoan"/"Viên sau khoan"), auto-fill kg, hiện gợi ý hao hụt.
- Validate client + server: ≥1 dòng tick; convert bắt buộc viên > 0; kg/viên không vượt tồn.
- Sau khi chuyển: tính lại `current_stage` từng lô; tạo phiếu; trả về trang trước (`HTTP_REFERER`).

## 6. Ràng buộc KỸ THUẬT

- Framework ECLO load plugin từ `plugins/` + bảng `plugins` (status='A'). **Hiện `NGOCHIENNEW/plugins` chưa có `io.eclo.qaqc`** → trước khi test: copy plugin vào `C:\xampp\htdocs\NGOCHIENNEW\plugins\io.eclo.qaqc` (deploy local).
- **Form có nút `data-action="submit"`** (framework submit AJAX): JS trong template PHẢI **luôn `e.preventDefault()`** trong native submit listener (chống submit 2 lần — Enter gây POST phụ), thêm cờ single-fire (timeout ~4s), khi hợp lệ gọi `.click()` lên nút framework. Đã áp dụng tại `vs-add-post.html` và `stage-transfer-post.html`.
  - `main.js` (framework): handler `click submit` [data-action] ở ~dòng 2261; `$this.attr("disabled")` là getter (không disable nút thật sự).
- Route nằm trong `$app->group($setting['manager'] . "/qaqc", ...)` với `->setPermissions([...])`; quyền khai báo trong `requests.php`.
- Route `/transfer/{id}` cũ **đã xóa**; các nút cũ phải trỏ `/qaqc/stage-transfer/{code}` (pjax-load).

## 7. Câu hỏi mở / xác nhận
1. Màn chi tiết kho VS/KX/LT hiển thị per-pearl (đã xác nhận).
2. Nút "Chuyển kho" → **1 nút chung duy nhất ở góc trên trang** cho VS/KX (đã xác nhận) — mở `/qaqc/stage-transfer/{code}`.
3. Bảng mỗi kho theo cột **Loại Ngọc | Mã lô | Số ký/viên | Ngày vào kho** (đã xác nhận); cột **Thao tác** chỉ hiện ở LT (nút "Thẩm định ngọc" theo dòng).
4. **Màn lịch sử chuyển kho** (mục 4.0): 1 trang riêng cho từng kho, nút "Xem chi tiết" load modal (đã xác nhận); **KHÔNG làm nút xem phiếu theo từng dòng**.
5. Trang chuyển kho: **1 nút duy nhất** mở màn, người dùng **chọn đích ngay trên màn** (đã xác nhận) — giữ thiết kế `/stage-transfer/{code}` hiện tại (VS chỉ có 1 đích KX; KX có dropdown Về VS / Sang LT).
6. Deploy local trước: copy vào `NGOCHIENNEW/plugins/io.eclo.qaqc` rồi test; sau mới lên server.