<?php

if (!defined('ECLO'))
    die("Hacking attempt");

use ECLO\App;

$template = __DIR__ . '/../templates';
$jatbi = $app->getValueData('jatbi');
$common = $jatbi->getPluginCommon('io.eclo.proposal');
$setting = $app->getValueData('setting');

$app->group($setting['manager'] . "/qaqc", function ($app) use ($jatbi, $setting, $template) {

    $app->router('', ['GET'], function ($vars) use ($app, $jatbi) {
        $app->redirect($jatbi->url('/qaqc/stage/VS'));
    });
    $app->router('/', ['GET'], function ($vars) use ($app, $jatbi) {
        $app->redirect($jatbi->url('/qaqc/stage/VS'));
    });

    // ============================================================
    // 1. LÔ SẢN XUẤT (production_batches + production_batch_items)
    //    Danh sách + Tạo mới + Sửa
    //    Mỗi lô (production_batches) có thể gồm NHIỀU dòng chi tiết
    //    (production_batch_items) — mỗi dòng 1 loại ngọc + kg và/hoặc
    //    viên riêng. Cùng 1 loại ngọc có thể xuất hiện ở nhiều dòng
    //    (vd Akoya vừa có dòng kg vừa có dòng viên riêng).
    // ============================================================

    // Hàm dùng chung: đọc các dòng chi tiết của 1 lô + gộp thông tin pearl
    $batchItemsWithPearl = function ($batchId) use ($app) {
        $items = $app->select("production_batch_items", "*", [
            "batch" => $batchId,
            "deleted" => 0,
            "ORDER" => ["id" => "ASC"],
        ]);

        $pearlIds = array_values(array_unique(array_column($items, 'pearl')));
        $pearlMap = [];
        if (!empty($pearlIds)) {
            $app->select("pearl", ["id", "name", "unit_mode"], ["id" => $pearlIds], function ($p) use (&$pearlMap) {
                $pearlMap[$p['id']] = $p;
            });
        }

        foreach ($items as &$it) {
            $it['pearl_name'] = $pearlMap[$it['pearl']]['name'] ?? null;
            $it['unit_mode'] = $pearlMap[$it['pearl']]['unit_mode'] ?? null;
        }
        unset($it);

        return $items;
    };

    // ============================================================
    // 1b. GIAI ĐOẠN 2 + 3 — LUỒNG CHUYỂN KHO NỘI BỘ THEO LÔ
    //     Bảng MỚI, không đụng tới warehouses/warehouses_details cũ
    //     (bảng đó là kho bán lẻ, gắn chặt store/branch/vendor/price):
    //       production_stage_movements       — 1 phiếu nhập/xuất theo lô, theo kho (stage)
    //       production_stage_movement_items  — chi tiết theo từng loại ngọc trong phiếu
    //     Xem file SQL đi kèm để tạo 2 bảng này.
    // ============================================================

    // Thứ tự luồng kho nội bộ dùng cho engine "chuyển kho". Nếu code kho
    // thực tế trong bảng warehouse_stages khác VS/KX/LT/CT thì sửa map này.
    $stageFlow = [
        'VS' => 'KX',
        'KX' => 'LT',
        'LT' => 'CT',
    ];

    $getStageByCode = function ($code) use ($app) {
        return $app->get("warehouse_stages", ["id", "code", "name"], ["code" => $code]);
    };

    $getStageById = function ($id) use ($app) {
        if (empty($id)) return null;
        return $app->get("warehouse_stages", ["id", "code", "name"], ["id" => $id]);
    };

    // Tồn hiện tại của 1 lô, tách theo từng loại ngọc, tại 1 kho (stage) cụ thể.
    // = tổng các phiếu nhập (type=import) - tổng các phiếu xuất (type=export) tại kho đó.
    // Trả về mảng khoá theo pearl_id: ['weight_kg'=>.., 'amount'=>.., 'pearl_name'=>.., 'unit_mode'=>.., 'last_date'=>..]
    $getBatchStockAtStage = function ($batchId, $stageId) use ($app) {
        $stock = [];

        $app->select("production_stage_movement_items", [
            "[><]production_stage_movements" => ["movement" => "id"],
        ], [
            "production_stage_movements.type",
            "production_stage_movements.date",
            "production_stage_movement_items.pearl",
            "production_stage_movement_items.weight_kg",
            "production_stage_movement_items.weight_gr",
            "production_stage_movement_items.amount",
            "production_stage_movement_items.weight_kg_hao_hut",
            "production_stage_movement_items.weight_gr_hao_hut",
            "production_stage_movement_items.amount_hao_hut",
        ], [
            "production_stage_movements.batch" => $batchId,
            "production_stage_movements.stage" => $stageId,
            "production_stage_movements.deleted" => 0,
            "production_stage_movement_items.deleted" => 0,
        ], function ($r) use (&$stock) {
            $pid = $r['pearl'];
            if (!isset($stock[$pid])) {
                $stock[$pid] = ['pearl' => $pid, 'weight_kg' => 0, 'weight_gr' => 0, 'amount' => 0, 'last_date' => $r['date']];
            }
            if ($r['type'] === 'import') {
                $stock[$pid]['weight_kg'] += floatval($r['weight_kg']);
                $stock[$pid]['weight_gr'] += floatval($r['weight_gr'] ?? 0);
                $stock[$pid]['amount'] += floatval($r['amount']);
                if ($r['date'] > $stock[$pid]['last_date']) {
                    $stock[$pid]['last_date'] = $r['date'];
                }
            } else {
                $stock[$pid]['weight_kg'] -= (floatval($r['weight_kg']) + floatval($r['weight_kg_hao_hut'] ?? 0));
                $stock[$pid]['weight_gr'] -= (floatval($r['weight_gr'] ?? 0) + floatval($r['weight_gr_hao_hut'] ?? 0));
                $stock[$pid]['amount'] -= (floatval($r['amount']) + floatval($r['amount_hao_hut'] ?? 0));
            }
        });

        $pearlIds = array_keys($stock);
        if (!empty($pearlIds)) {
            $app->select("pearl", ["id", "name", "unit_mode"], ["id" => $pearlIds], function ($p) use (&$stock) {
                $stock[$p['id']]['pearl_name'] = $p['name'];
                $stock[$p['id']]['unit_mode'] = $p['unit_mode'];
            });
        }

        // Chỉ giữ lại những dòng còn tồn thật sự (bỏ dòng đã chuyển hết = 0)
        foreach ($stock as $pid => $s) {
            if ($s['weight_kg'] <= 0.0001 && $s['amount'] <= 0.0001 && $s['weight_gr'] <= 0.0001) {
                unset($stock[$pid]);
            }
        }

        return $stock;
    };

    $app->router('/batch', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template, $batchItemsWithPearl, $stageFlow, $getBatchStockAtStage) {
        if ($app->method() === 'GET') {
            $vars['title'] = $jatbi->lang("Lô sản xuất");
            $vars['pearl_filter_options'] = array_merge(
                [['value' => '', 'text' => $jatbi->lang('Tất cả')]],
                $app->select('pearl', ['id(value)', 'name(text)'], ['deleted' => 0, 'status' => 'A'])
            );
            echo $app->render($template . '/qaqc/batch.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : ($setting['site_page'] ?? 10);
            $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';

            $orderName = isset($_POST['order'][0]['type_name']) ? $_POST['order'][0]['type_name'] : 'id';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            $where = [
                "AND" => [
                    "OR" => [
                        'code[~]' => $searchValue,
                    ],
                    "deleted" => 0,
                ],
                "LIMIT" => [$start, $length],
                "ORDER" => [$orderName => strtoupper($orderDir)],
            ];

            // Lọc theo loại ngọc: 1 lô có thể chứa nhiều loại ngọc,
            // nên lọc qua bảng chi tiết trước rồi mới lấy id các lô liên quan.
            if (isset($_POST['pearl']) && $_POST['pearl'] !== '') {
                $pearlFilter = $app->xss($_POST['pearl']);
                $batchIdsForPearl = [];
                $app->select("production_batch_items", ["batch"], [
                    "pearl" => $pearlFilter,
                    "deleted" => 0,
                ], function ($row) use (&$batchIdsForPearl) {
                    $batchIdsForPearl[] = $row['batch'];
                });
                $where['AND']['id'] = !empty($batchIdsForPearl) ? array_values(array_unique($batchIdsForPearl)) : [0];
            }
            if (isset($_POST['status']) && $_POST['status'] !== '') {
                $where['AND']['status'] = $app->xss($_POST['status']);
            }

            $count = $app->count("production_batches", [
                "AND" => $where['AND'],
            ]);
            $datas = [];

            $app->select("production_batches", "*", $where, function ($data) use (&$datas, $jatbi, $app, $batchItemsWithPearl, $stageFlow, $getBatchStockAtStage) {
                $stage_info = $app->get("warehouse_stages", ["id", "code", "name"], ["id" => $data['current_stage']]);
                $items = $batchItemsWithPearl($data['id']);

                $lines = [];
                $totalKg = 0;
                $totalVien = 0;
                foreach ($items as $it) {
                    $parts = [];
                    if ($it['weight_gr_initial'] !== null && $it['weight_gr_initial'] !== ''
                        && floatval($it['weight_gr_initial']) > 0) {
                        $parts[] = number_format(floatval($it['weight_gr_initial']), 2) . ' ' . $jatbi->lang("gr");
                        $totalKg += floatval($it['weight_kg_initial'] ?? 0);
                    } elseif ($it['weight_kg_initial'] !== null && $it['weight_kg_initial'] !== '') {
                        $parts[] = number_format($it['weight_kg_initial'], 2) . ' kg';
                        $totalKg += floatval($it['weight_kg_initial']);
                    }
                    if ($it['amount_initial'] !== null && $it['amount_initial'] !== '') {
                        $parts[] = number_format($it['amount_initial']) . ' ' . $jatbi->lang("viên");
                        $totalVien += floatval($it['amount_initial']);
                    }
                    $lines[] = '<div class="small text-nowrap"><span class="fw-semibold">'
                        . htmlspecialchars($it['pearl_name'] ?? $jatbi->lang("Không xác định"))
                        . ':</span> ' . implode(' + ', $parts) . '</div>';
                }

                $buttons = [
                    [
                        'type' => 'button',
                        'name' => $jatbi->lang("Sửa"),
                        'permission' => ['batch.edit'],
                        'action' => ['data-url' => '/qaqc/batch-edit/' . $data['id'], 'data-action' => 'modal']
                    ],
                ];

                // Chỉ lô đang chạy mới thao tác được kho
                // (Không còn bước "Nhập kho Vệ sinh" tách riêng nữa — lô được
                // tạo trực tiếp tại Kho Vệ Sinh với tồn kho ngay từ đầu, xem
                // route /vs-add. Từ VS chỉ còn thao tác Chuyển kho như KX/LT.)
                if ($data['status'] === 'A' && $stage_info) {
                    if (isset($stageFlow[$stage_info['code']])) {
                        $stockHere = $getBatchStockAtStage($data['id'], $stage_info['id']);
                        if (!empty($stockHere)) {
                            if ($stage_info['code'] === 'LT') {
                                // Bước LT -> CT thay "Chuyển kho" bằng "Thẩm định ngọc"
                                $buttons[] = [
                                    'type' => 'link',
                                    'name' => $jatbi->lang("Thẩm định ngọc"),
                                    'permission' => ['stage_appraisal'],
                                    'action' => ['href' => '/qaqc/appraisal/' . $data['id'], 'class' => 'pjax-load text-primary fw-semibold']
                                ];
                            } else {
                                $buttons[] = [
                                    'type' => 'link',
                                    'name' => $jatbi->lang("Chuyển kho"),
                                    'permission' => ['stage_transfer'],
                                    'action' => ['href' => '/qaqc/stage-transfer/' . $stage_info['code'], 'class' => 'pjax-load text-primary fw-semibold']
                                ];
                            }
                        }
                    }

                    // Giai đoạn 4: Kho Chế tác (CT) -> Nghiệm thu Chế tác
                    if ($stage_info['code'] === 'CT') {
                        $stockHere = $getBatchStockAtStage($data['id'], $stage_info['id']);
                        if (!empty($stockHere)) {
                            $buttons[] = [
                                'type' => 'link',
                                'name' => $jatbi->lang("Nghiệm thu Chế tác"),
                                'permission' => ['crafting.process'],
                                'action' => ['href' => '/qaqc/crafting-process/' . $data['id'], 'class' => 'pjax-load text-primary fw-semibold']
                            ];
                        }
                    }

                    // Giai đoạn 5: Kho Thành Phẩm QAQC (TP) -> 3 nhánh xuất
                    if ($stage_info['code'] === 'TP') {
                        $stockHere = $getBatchStockAtStage($data['id'], $stage_info['id']);
                        if (!empty($stockHere)) {
                            $buttons[] = [
                                'type' => 'button',
                                'name' => $jatbi->lang("Xuất bán"),
                                'permission' => ['finish_stock.export'],
                                'action' => ['data-url' => '/qaqc/export-sell/' . $data['id'], 'data-action' => 'modal']
                            ];
                            $buttons[] = [
                                'type' => 'link',
                                'name' => $jatbi->lang("Xuất Kho TP"),
                                'permission' => ['finish_stock.export'],
                                'action' => ['href' => '/qaqc/export-products/' . $data['id'], 'class' => 'pjax-load text-primary fw-semibold']
                            ];
                            $buttons[] = [
                                'type' => 'button',
                                'name' => $jatbi->lang("Xuất Kho NL"),
                                'permission' => ['finish_stock.export'],
                                'action' => ['data-url' => '/qaqc/export-ingredient/' . $data['id'], 'data-action' => 'modal']
                            ];
                        }
                    }
                }

                $datas[] = [
                    "checkbox" => $app->component("box", ["data" => $data['id']]),
                    "code" => $data['code'],
                    "pearl_name" => !empty($lines) ? implode('', $lines) : '<span class="text-secondary">-</span>',
                    "unit_mode" => '<span class="badge bg-secondary">' . count($items) . ' ' . $jatbi->lang("dòng") . '</span>',
                    "weight_kg_initial" => $totalKg > 0 ? number_format($totalKg, 2) . ' kg' : '-',
                    "amount_initial" => $totalVien > 0 ? number_format($totalVien) . ' ' . $jatbi->lang("viên") : '-',
                    "current_stage" => $stage_info['name'] ?? $jatbi->lang("Chưa xác định"),
                    "status" => ($data['status'] == 'A'
                        ? '<span class="text-success fw-bold">' . $jatbi->lang("Đang chạy") . '</span>'
                        : '<span class="text-secondary fw-bold">' . $jatbi->lang("Đã đóng") . '</span>'),
                    "date" => date('d/m/Y H:i', strtotime($data['date'])),
                    "action" => $app->component("action", ["button" => $buttons]),
                ];
            });

            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => $count,
                "recordsFiltered" => $count,
                "data" => $datas
            ]);
        }
    })->setPermissions(['batch']);


    // ============================================================
    // 1c'. TẠO LÔ SẢN XUẤT & NHẬP KHO VỆ SINH — GỘP LÀM 1 BƯỚC
    //     Trước đây: /batch-add (khai báo initial) rồi mới /warehouse-import
    //     (nhập số thực nhận) — 2 request, 2 lần thao tác cho cùng 1 lô.
    //     Giờ: nhập thẳng 1 lần tại đây — vừa tạo production_batches +
    //     production_batch_items, vừa ghi luôn phiếu production_stage_movements
    //     (type=import, stage=VS) trong CÙNG 1 transaction. Lô luôn có tồn
    //     kho ngay tại Kho Vệ Sinh (VS) kể từ giây phút tạo ra.
    // ============================================================

    $app->router('/vs-add', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template, $getStageByCode) {
        $vars['title'] = $jatbi->lang("Tạo lô & Nhập kho Vệ Sinh");

        if ($app->method() === 'GET') {
            $vars['pearl_options'] = [];
            $app->select("pearl", ["id", "name", "unit_mode"], ["deleted" => 0, "status" => 'A'], function ($p) use (&$vars) {
                $vars['pearl_options'][] = [
                    'value' => $p['id'],
                    'text' => $p['name'],
                    'unit_mode' => $p['unit_mode'],
                ];
            });

            echo $app->render($template . '/qaqc/vs-add-post.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $error = [];
            $code = trim($app->xss($_POST['code'] ?? ''));
            $notes = $app->xss($_POST['notes'] ?? '');
            $itemsRaw = $_POST['items'] ?? [];
            $cleanItems = [];

            if ($code === '') {
                $error = ['status' => 'error', 'content' => $jatbi->lang('Vui lòng nhập mã lô')];
            } elseif ($app->has('production_batches', ['code' => $code])) {
                $error = ['status' => 'error', 'content' => $jatbi->lang('Mã lô đã tồn tại, vui lòng nhập mã khác')];
            }

            if (empty($error) && (!is_array($itemsRaw) || count($itemsRaw) === 0)) {
                $error = ['status' => 'error', 'content' => $jatbi->lang('Vui lòng thêm ít nhất 1 dòng chi tiết')];
            }

            if (empty($error)) {
                foreach ($itemsRaw as $row) {
                    $pearl_id = $app->xss($row['pearl'] ?? '');
                    $unit = strtolower($app->xss($row['unit'] ?? 'kg'));
                    if (!in_array($unit, ['kg', 'gr', 'vien'])) {
                        $unit = 'kg';
                    }
                    $value = $app->xss($row['value'] ?? '');

                    if ($pearl_id === '') {
                        $error = ['status' => 'error', 'content' => $jatbi->lang('Vui lòng chọn loại ngọc cho tất cả các dòng')];
                        break;
                    }

                    $hasValue = ($value !== '' && is_numeric($value) && floatval($value) > 0);

                    if (!$hasValue) {
                        $error = ['status' => 'error', 'content' => $jatbi->lang('Mỗi dòng cần nhập số lượng thực nhận hợp lệ')];
                        break;
                    }

                    $val = floatval($value);
                    $kg = 0;
                    $gr = 0;
                    $vien = 0;
                    if ($unit === 'gr') {
                        // Lưu nguyên giá trị gram người dùng nhập, không tự quy đổi sang kg
                        $gr = $val;
                    } elseif ($unit === 'vien') {
                        $vien = $val;
                    } else {
                        // Đơn vị 'gr' không quy đổi sang kg; đơn vị 'kg' giữ nguyên
                        $kg = $val;
                    }

                    $cleanItems[] = [
                        'pearl' => $pearl_id,
                        'weight_kg' => $kg,
                        'weight_gr' => $gr,
                        'amount' => $vien,
                    ];
                }
            }

            // Kiểm tra toàn bộ loại ngọc được dùng còn tồn tại & đang hoạt động
            if (empty($error)) {
                $pearlIds = array_values(array_unique(array_column($cleanItems, 'pearl')));
                $validPearls = [];
                $app->select("pearl", ["id"], ["id" => $pearlIds, "deleted" => 0, "status" => 'A'], function ($p) use (&$validPearls) {
                    $validPearls[] = $p['id'];
                });
                foreach ($cleanItems as $ci) {
                    if (!in_array($ci['pearl'], $validPearls)) {
                        $error = ['status' => 'error', 'content' => $jatbi->lang('Một hoặc nhiều loại ngọc không hợp lệ')];
                        break;
                    }
                }
            }

            if (!empty($error)) {
                echo json_encode($error);
                return;
            }

            $vsStage = $getStageByCode('VS');
            if (!$vsStage) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Chưa cấu hình kho Vệ sinh (warehouse_stages.code = VS)')]);
                return;
            }
            $vsStageId = $vsStage['id'];

            $now = date('Y-m-d H:i:s');
            $userId = $app->getSession("accounts")['id'] ?? 0;
            $newBatchId = null;

            $ok = false;
            try {
                $app->action(function () use ($app, $code, $notes, $cleanItems, $userId, $now, $vsStageId, &$newBatchId, &$ok) {
                    // Re-check trùng mã lô ngay trong transaction, phòng 2 người
                    // bấm lưu gần như đồng thời cùng 1 mã (race condition).
                    if ($app->has('production_batches', ['code' => $code])) {
                        return false;
                    }

                    // 1. Tạo lô sản xuất — vào thẳng Kho Vệ Sinh (VS)
                    $app->insert("production_batches", [
                        "code" => $code,
                        "current_stage" => $vsStageId,
                        "status" => 'A',
                        "notes" => $notes,
                        "date" => $now,
                        "user" => $userId,
                    ]);
                    $batchId = $app->id();
                    if (!$batchId) return false;
                    $newBatchId = $batchId;

                    // 2. Tạo dòng chi tiết lô — dùng luôn số thực nhận làm initial
                    //    (không còn khái niệm "khai báo" tách khỏi "thực nhận" nữa)
                    foreach ($cleanItems as $ci) {
                        $app->insert("production_batch_items", [
                            "batch" => $batchId,
                            "pearl" => $ci['pearl'],
                            "weight_kg_initial" => $ci['weight_kg'] > 0 ? $ci['weight_kg'] : null,
                            "weight_gr_initial" => $ci['weight_gr'] > 0 ? $ci['weight_gr'] : null,
                            "amount_initial" => $ci['amount'] > 0 ? $ci['amount'] : null,
                            "date" => $now,
                            "user" => $userId,
                            "deleted" => 0,
                        ]);
                    }

                    // 3. Ghi luôn phiếu nhập kho Vệ Sinh trong CÙNG giao dịch —
                    //    đây chính là điểm gộp: không còn bước "Nhập kho Vệ sinh"
                    //    tách riêng, lô có tồn kho ở VS ngay khi tạo xong.
                    $app->insert("production_stage_movements", [
                        "batch" => $batchId,
                        "stage" => $vsStageId,
                        "type" => "import",
                        "stage_related" => null,
                        "notes" => "Nhập ngọc thô vào kho Vệ sinh (tạo lô trực tiếp)",
                        "date" => $now,
                        "user" => $userId,
                        "deleted" => 0,
                    ]);
                    $movementId = $app->id();
                    if (!$movementId) return false;

                    foreach ($cleanItems as $ci) {
                        $app->insert("production_stage_movement_items", [
                            "movement" => $movementId,
                            "pearl" => $ci['pearl'],
                            "weight_kg" => $ci['weight_kg'],
                            "weight_gr" => $ci['weight_gr'] > 0 ? $ci['weight_gr'] : null,
                            "amount" => $ci['amount'],
                            "weight_kg_hao_hut" => 0,
                            "amount_hao_hut" => 0,
                            "deleted" => 0,
                        ]);
                    }

                    $ok = true;
                    return true;
                });
            } catch (\Exception $e) {
                $ok = false;
            }

            if ($ok) {
                $jatbi->logs('production_batches', 'add_direct_to_vs', ['code' => $code, 'batch' => $newBatchId, 'items' => $cleanItems]);
                echo json_encode([
                    'status' => 'success',
                    'content' => $jatbi->lang('Tạo lô & nhập kho Vệ sinh thành công'),
                    'url' => $jatbi->url('/qaqc/stage/VS'),
                ]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Mã lô vừa bị trùng hoặc có lỗi xảy ra, vui lòng thử lại')]);
            }
        }
    })->setPermissions(['stage_import']);


    $app->router("/batch-edit/{id}", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template, $batchItemsWithPearl) {
        $vars['title'] = $jatbi->lang("Sửa lô sản xuất");

        if ($app->method() === 'GET') {
            $data = $app->get("production_batches", "*", ["id" => $vars['id']]);
            if (!$data) {
                echo $app->render($setting['template'] . '/pages/error.html', ['content' => 'Không tìm thấy lô sản xuất.'], $jatbi->ajax());
                return;
            }

            $vars['data'] = $data;
            $vars['items'] = $batchItemsWithPearl($vars['id']);
            $vars['pearl_options'] = [];
            $vars['status_options'] = [
                ['value' => 'A', 'text' => $jatbi->lang('Đang chạy')],
                ['value' => 'D', 'text' => $jatbi->lang('Đã đóng')],
            ];

            $app->select("pearl", ["id", "name", "unit_mode"], ["deleted" => 0, "status" => 'A'], function ($p) use (&$vars) {
                $vars['pearl_options'][] = [
                    'value' => $p['id'],
                    'text' => $p['name'],
                    'unit_mode' => $p['unit_mode'],
                ];
            });

            echo $app->render($template . '/qaqc/batch-post.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $error = [];
            $status = in_array($app->xss($_POST['status'] ?? ''), ['A', 'D']) ? $app->xss($_POST['status']) : 'A';
            $itemsRaw = $_POST['items'] ?? [];
            if (!is_array($itemsRaw)) {
                $itemsRaw = [];
            }

            $existingUpdates = [];
            $newInserts = [];

            foreach ($itemsRaw as $row) {
                $rowId = $app->xss($row['id'] ?? '');
                $deletedFlag = !empty($row['deleted']) ? 1 : 0;
                $unit = strtolower($app->xss($row['unit'] ?? 'kg'));
                if (!in_array($unit, ['kg', 'gr', 'vien'])) {
                    $unit = 'kg';
                }
                $value = $app->xss($row['value'] ?? '');
                $hasValue = ($value !== '' && is_numeric($value) && floatval($value) > 0);

                $kg = 0;
                $gr = 0;
                $vien = 0;
                if ($hasValue) {
                    $val = floatval($value);
                    if ($unit === 'gr') {
                        // Lưu nguyên giá trị gram người dùng nhập, không tự quy đổi sang kg
                        $gr = $val;
                    } elseif ($unit === 'vien') {
                        $vien = $val;
                    } else {
                        $kg = $val;
                    }
                }

                if ($rowId !== '') {
                    // Dòng đã tồn tại từ trước — không đổi loại ngọc, chỉ đổi số lượng hoặc xoá mềm
                    if (!$deletedFlag && !$hasValue) {
                        $error = ['status' => 'error', 'content' => $jatbi->lang('Mỗi dòng cần nhập số lượng hợp lệ')];
                        break;
                    }
                    $existingUpdates[] = [
                        'id' => $rowId,
                        'weight_kg_initial' => (!$deletedFlag && $kg > 0) ? $kg : null,
                        'weight_gr_initial' => (!$deletedFlag && $gr > 0) ? $gr : null,
                        'amount_initial' => (!$deletedFlag && $vien > 0) ? $vien : null,
                        'deleted' => $deletedFlag,
                    ];
                } else {
                    // Dòng mới thêm vào lô đã có
                    if ($deletedFlag) {
                        continue; // dòng mới thêm rồi xoá ngay trong lúc chưa lưu — bỏ qua
                    }
                    $pearl_id = $app->xss($row['pearl'] ?? '');
                    if ($pearl_id === '') {
                        $error = ['status' => 'error', 'content' => $jatbi->lang('Vui lòng chọn loại ngọc cho dòng mới')];
                        break;
                    }
                    if (!$hasValue) {
                        $error = ['status' => 'error', 'content' => $jatbi->lang('Mỗi dòng cần nhập số lượng hợp lệ')];
                        break;
                    }
                    $newInserts[] = [
                        'pearl' => $pearl_id,
                        'weight_kg_initial' => $kg > 0 ? $kg : null,
                        'weight_gr_initial' => $gr > 0 ? $gr : null,
                        'amount_initial' => $vien > 0 ? $vien : null,
                    ];
                }
            }

            // Kiểm tra loại ngọc hợp lệ cho các dòng mới
            if (empty($error) && !empty($newInserts)) {
                $pearlIds = array_values(array_unique(array_column($newInserts, 'pearl')));
                $validPearls = [];
                $app->select("pearl", ["id"], ["id" => $pearlIds, "deleted" => 0, "status" => 'A'], function ($p) use (&$validPearls) {
                    $validPearls[] = $p['id'];
                });
                foreach ($newInserts as $ni) {
                    if (!in_array($ni['pearl'], $validPearls)) {
                        $error = ['status' => 'error', 'content' => $jatbi->lang('Một hoặc nhiều loại ngọc không hợp lệ')];
                        break;
                    }
                }
            }

            // Lô sau khi lưu phải còn ít nhất 1 dòng chi tiết đang hoạt động
            if (empty($error)) {
                $remainingActive = count($newInserts);
                foreach ($existingUpdates as $eu) {
                    if (!$eu['deleted']) {
                        $remainingActive++;
                    }
                }
                if ($remainingActive === 0) {
                    $error = ['status' => 'error', 'content' => $jatbi->lang('Lô sản xuất phải còn ít nhất 1 dòng chi tiết')];
                }
            }

            if (empty($error)) {
                $now = date('Y-m-d H:i:s');
                $userId = $app->getSession("accounts")['id'] ?? 0;

                $update = [
                    "status" => $status,
                    "notes" => $app->xss($_POST['notes'] ?? ''),
                ];
                $app->update("production_batches", $update, ["id" => $vars['id']]);

                foreach ($existingUpdates as $eu) {
                    $app->update("production_batch_items", [
                        "weight_kg_initial" => $eu['weight_kg_initial'],
                        "weight_gr_initial" => $eu['weight_gr_initial'],
                        "amount_initial" => $eu['amount_initial'],
                        "deleted" => $eu['deleted'],
                    ], ["id" => $eu['id'], "batch" => $vars['id']]);
                }

                foreach ($newInserts as $ni) {
                    $app->insert("production_batch_items", [
                        "batch" => $vars['id'],
                        "pearl" => $ni['pearl'],
                        "weight_kg_initial" => $ni['weight_kg_initial'],
                        "weight_gr_initial" => $ni['weight_gr_initial'],
                        "amount_initial" => $ni['amount_initial'],
                        "date" => $now,
                        "user" => $userId,
                        "deleted" => 0,
                    ]);
                }

                $jatbi->logs('production_batches', 'edit', ['batch' => $update, 'items_updated' => $existingUpdates, 'items_added' => $newInserts]);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Cập nhật thành công'), 'url' => $_SERVER['HTTP_REFERER']]);
            } else {
                echo json_encode($error);
            }
        }
    })->setPermissions(['batch.edit']);


    // ============================================================
    // 1d. GIAI ĐOẠN 3 — ENGINE "CHUYỂN KHO NỘI BỘ THEO LÔ"
    //     Hỗ trợ chuyển 2 chiều (VS ↔ KX) và chuyển tiến (KX → LT).
    //     Cho phép chuyển một phần lô hoặc toàn bộ lô.
    //     Tách biệt rõ ràng giữa "Số chuyển đi" và "Hao hụt".
    // ============================================================

    $app->router("/transfer/{id}", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template, $stageFlow, $getStageByCode, $getStageById, $getBatchStockAtStage) {
        $batch = $app->get("production_batches", "*", ["id" => $vars['id'], "deleted" => 0]);
        if (!$batch) {
            echo $app->render($setting['template'] . '/pages/error.html', ['content' => $jatbi->lang('Không tìm thấy lô sản xuất')], $jatbi->ajax());
            return;
        }

        $fromCode = strtoupper($app->xss($_GET['from'] ?? $_POST['from'] ?? ''));
        $fromStage = null;
        if ($fromCode !== '') {
            $fromStage = $getStageByCode($fromCode);
        }
        if (!$fromStage) {
            $fromStage = $getStageById($batch['current_stage']);
        }

        if (!$fromStage) {
            echo $app->render($setting['template'] . '/pages/error.html', ['content' => $jatbi->lang('Không xác định được kho nguồn')], $jatbi->ajax());
            return;
        }

        $stock = $getBatchStockAtStage($vars['id'], $fromStage['id']);
        if (empty($stock)) {
            echo $app->render($setting['template'] . '/pages/error.html', ['content' => $jatbi->lang('Lô này không còn tồn kho tại') . ' ' . $fromStage['name']], $jatbi->ajax());
            return;
        }

        // Danh sách các kho đích khả dụng theo quy trình
        $to_stage_options = [];
        if ($fromStage['code'] === 'VS') {
            $kx = $getStageByCode('KX');
            if ($kx) $to_stage_options[] = $kx;
        } elseif ($fromStage['code'] === 'KX') {
            $lt = $getStageByCode('LT');
            $vs = $getStageByCode('VS');
            if ($lt) $to_stage_options[] = ['id' => $lt['id'], 'code' => $lt['code'], 'name' => $lt['name'] . ' (' . $jatbi->lang('Tiến trình tiếp theo') . ')'];
            if ($vs) $to_stage_options[] = ['id' => $vs['id'], 'code' => $vs['code'], 'name' => $vs['name'] . ' (' . $jatbi->lang('Chuyển ngược lại Kho Vệ Sinh') . ')'];
        } elseif ($fromStage['code'] === 'LT') {
            $ct = $getStageByCode('CT');
            if ($ct) $to_stage_options[] = $ct;
        }

        if (empty($to_stage_options)) {
            echo $app->render($setting['template'] . '/pages/error.html', ['content' => $jatbi->lang('Không có kho đích khả dụng cho kho này')], $jatbi->ajax());
            return;
        }

        $toStage = $to_stage_options[0];

        $vars['title'] = $jatbi->lang('Chuyển kho') . ': ' . $fromStage['name'];
        $vars['batch'] = $batch;
        $vars['from_stage'] = $fromStage;
        $vars['to_stage'] = $toStage;
        $vars['to_stage_options'] = $to_stage_options;
        $vars['stock'] = array_values($stock);

        if ($app->method() === 'GET') {
            echo $app->render($template . '/qaqc/transfer-post.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $targetStageId = intval($_POST['to_stage_id'] ?? $toStage['id']);
            $targetStage = $getStageById($targetStageId);
            $validTarget = false;
            foreach ($to_stage_options as $opt) {
                if ($opt['id'] == $targetStageId) {
                    $validTarget = true;
                    $toStage = $targetStage;
                    break;
                }
            }

            if (!$validTarget || !$toStage) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Kho đích không hợp lệ')]);
                return;
            }

            $linesRaw = $_POST['lines'] ?? [];
            $cleanLines = [];
            $error = '';

            foreach ($stock as $pearlId => $s) {
                $row = $linesRaw[$pearlId] ?? [];
                $isMaxima = (($s['unit_mode'] ?? '') === 'kg_and_vien');

                $kgOut = is_numeric($row['weight_kg'] ?? '') ? floatval($row['weight_kg']) : 0;
                $kgHaoHut = is_numeric($row['weight_kg_hao_hut'] ?? '') ? floatval($row['weight_kg_hao_hut']) : 0;
                $vienOut = is_numeric($row['amount'] ?? '') ? floatval($row['amount']) : 0;
                $vienHaoHut = is_numeric($row['amount_hao_hut'] ?? '') ? floatval($row['amount_hao_hut']) : 0;

                // Chỉ tại Kho Khoan Xiên (KX) mới phát sinh hao hụt do khoan
                if ($fromStage['code'] !== 'KX') {
                    $kgHaoHut = 0;
                    $vienHaoHut = 0;
                }

                if ($kgOut < 0 || $kgHaoHut < 0 || $vienOut < 0 || $vienHaoHut < 0) {
                    $error = $jatbi->lang('Số lượng không được âm');
                    break;
                }

                if (($kgOut + $kgHaoHut) > ($s['weight_kg'] + 0.0001)) {
                    $error = $jatbi->lang('Tổng số kg chuyển đi và hao hụt vượt quá tồn kho của ') . ($s['pearl_name'] ?? '');
                    break;
                }

                if ($isMaxima && ($vienOut + $vienHaoHut) > ($s['amount'] + 0.0001)) {
                    $error = $jatbi->lang('Tổng số viên chuyển đi và hao hụt vượt quá tồn kho của ') . ($s['pearl_name'] ?? '');
                    break;
                }

                $cleanLines[] = [
                    'pearl' => $pearlId,
                    'weight_kg_out' => $kgOut,
                    'weight_kg_hao_hut' => $kgHaoHut,
                    'amount_out' => $vienOut,
                    'amount_hao_hut' => $vienHaoHut,
                ];
            }

            if ($error === '') {
                $totalOut = array_sum(array_column($cleanLines, 'weight_kg_out')) + array_sum(array_column($cleanLines, 'amount_out'));
                if ($totalOut <= 0) {
                    $error = $jatbi->lang('Vui lòng nhập số lượng chuyển đi cho ít nhất 1 dòng');
                }
            }

            if ($error !== '') {
                echo json_encode(['status' => 'error', 'content' => $error]);
                return;
            }

            $userId = $app->getSession("accounts")['id'] ?? 0;
            $now = date('Y-m-d H:i:s');
            $batchId = $vars['id'];
            $fromId = $fromStage['id'];
            $toId = $toStage['id'];

            $ok = false;
            try {
                $app->action(function () use ($app, $batchId, $fromId, $toId, $cleanLines, $userId, $now, $fromStage, $toStage, &$ok) {
                    // Phiếu xuất khỏi kho nguồn
                    $app->insert("production_stage_movements", [
                        "batch" => $batchId, "stage" => $fromId, "type" => "export",
                        "stage_related" => $toId, "notes" => "Chuyển kho nội bộ (" . $fromStage['name'] . " → " . $toStage['name'] . ")",
                        "date" => $now, "user" => $userId, "deleted" => 0,
                    ]);
                    $exportMovementId = $app->id();
                    if (!$exportMovementId) return false;

                    foreach ($cleanLines as $cl) {
                        $app->insert("production_stage_movement_items", [
                            "movement" => $exportMovementId, "pearl" => $cl['pearl'],
                            "weight_kg" => $cl['weight_kg_out'], "amount" => $cl['amount_out'],
                            "weight_kg_hao_hut" => $cl['weight_kg_hao_hut'], "amount_hao_hut" => $cl['amount_hao_hut'],
                            "deleted" => 0,
                        ]);
                    }

                    // Phiếu nhập vào kho đích — nhận đúng số đã chuyển đi
                    $app->insert("production_stage_movements", [
                        "batch" => $batchId, "stage" => $toId, "type" => "import",
                        "stage_related" => $fromId, "notes" => "Chuyển kho nội bộ (" . $fromStage['name'] . " → " . $toStage['name'] . ")",
                        "date" => $now, "user" => $userId, "deleted" => 0,
                    ]);
                    $importMovementId = $app->id();
                    if (!$importMovementId) return false;

                    foreach ($cleanLines as $cl) {
                        $app->insert("production_stage_movement_items", [
                            "movement" => $importMovementId, "pearl" => $cl['pearl'],
                            "weight_kg" => $cl['weight_kg_out'], "amount" => $cl['amount_out'],
                            "weight_kg_hao_hut" => 0, "amount_hao_hut" => 0,
                            "deleted" => 0,
                        ]);
                    }

                    // Ghi nhật ký hao hụt nếu có
                    foreach ($cleanLines as $cl) {
                        if ($cl['weight_kg_hao_hut'] > 0 || $cl['amount_hao_hut'] > 0) {
                            $app->insert("production_loss_logs", [
                                "batch_id" => $batchId,
                                "stage" => $fromStage['name'],
                                "loss_piece" => $cl['amount_hao_hut'],
                                "loss_weight_kg" => $cl['weight_kg_hao_hut'],
                                "date" => $now,
                                "user_id" => $userId,
                                "reason" => "Hao hụt khi chuyển " . $fromStage['name'] . " → " . $toStage['name'],
                            ]);
                        }
                    }

                    // Cập nhật stage hoạt động gần nhất của lô
                    $app->update("production_batches", ["current_stage" => $toId], ["id" => $batchId]);

                    $ok = true;
                    return true;
                });
            } catch (\Exception $e) {
                $ok = false;
            }

            if ($ok) {
                $jatbi->logs('production_stage_movements', 'transfer', ['batch' => $batchId, 'from' => $fromId, 'to' => $toId, 'lines' => $cleanLines]);
                echo json_encode([
                    'status' => 'success',
                    'content' => $jatbi->lang('Chuyển kho thành công') . ': ' . $fromStage['name'] . ' → ' . $toStage['name'],
                    'url' => $jatbi->url('/qaqc/stage/' . $fromStage['code'])
                ]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Có lỗi xảy ra, vui lòng thử lại')]);
            }
        }
    })->setPermissions(['stage_transfer']);


    // ============================================================
    // 1c'. GIAI ĐOẠN 3 → 4: THẨM ĐỊNH NGỌC (KHO LƯU TRỮ → KHO CHẾ TÁC)
    //     Thay thế bước "Chuyển kho" LT→CT: mỗi viên được gắn mã ngọc
    //     (nhập tay, validate trùng) + thuộc tính (size, màu). Kết quả
    //     ghi vào bảng ingredient (type=2 = ngọc) + lịch sử
    //     production_appraisal, đồng thời tạo phiếu xuất LT / nhập CT
    //     như chuyển kho thường để tồn kho theo lô không bị lệch.
    // ============================================================
    $app->router('/appraisal/{id}', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template, $getStageByCode, $getStageById, $getBatchStockAtStage) {
        $batch = $app->get("production_batches", "*", ["id" => $vars['id'], "deleted" => 0]);
        if (!$batch) {
            echo $app->render($setting['template'] . '/pages/error.html', ['content' => $jatbi->lang('Không tìm thấy lô sản xuất')], $jatbi->ajax());
            return;
        }

        $ltStage = $getStageByCode('LT');
        $ctStage = $getStageByCode('CT');
        if (!$ltStage || !$ctStage) {
            echo $app->render($setting['template'] . '/pages/error.html', ['content' => $jatbi->lang('Chưa cấu hình kho Lưu Trữ (LT) hoặc Chế Tác (CT) trong warehouse_stages')], $jatbi->ajax());
            return;
        }
        $ltId = $ltStage['id'];
        $ctId = $ctStage['id'];

        $stock = $getBatchStockAtStage($vars['id'], $ltId);
        if (empty($stock)) {
            echo $app->render($setting['template'] . '/pages/error.html', ['content' => $jatbi->lang('Lô này không còn tồn ngọc tại') . ' ' . $ltStage['name']], $jatbi->ajax());
            return;
        }

        $vars['title'] = $jatbi->lang('Thẩm định ngọc') . ': ' . $ltStage['name'] . ' → ' . $ctStage['name'];

        if ($app->method() === 'GET') {
            $vars['batch'] = $batch;
            $vars['from_stage'] = $ltStage;
            $vars['to_stage'] = $ctStage;
            $vars['stock'] = array_values($stock);
            $vars['sizes'] = $app->select("sizes", ["id", "name"], ["deleted" => 0, "status" => "A"]);
            $vars['colors'] = $app->select("colors", ["id", "name"], ["deleted" => 0, "status" => "A"]);
            $vars['craft_groups'] = [
                ['value' => 1, 'text' => $jatbi->lang('Vàng')],
                ['value' => 2, 'text' => $jatbi->lang('Bạc')],
                ['value' => 3, 'text' => $jatbi->lang('Chuỗi')],
            ];

            echo $app->render($template . '/qaqc/appraisal-post.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            if (empty($stock)) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Lô này chưa có tồn ngọc tại') . ' ' . $ltStage['name']]);
                return;
            }

            $linesRaw = $_POST['lines'] ?? [];
            $cleanLines = [];
            $error = '';
            $codesUsed = [];

            foreach ($stock as $pearlId => $s) {
                $codes = $linesRaw[$pearlId]['code'] ?? [];
                $sizeIds = $linesRaw[$pearlId]['sizes'] ?? [];
                $colorIds = $linesRaw[$pearlId]['colors'] ?? [];
                $amounts = $linesRaw[$pearlId]['amount'] ?? [];
                $groupCrafting = $linesRaw[$pearlId]['group_crafting'] ?? [];
                $prices = $linesRaw[$pearlId]['price'] ?? [];

                $rows = [];
                $totalVien = 0;
                $n = max(count($codes), count($sizeIds), count($colorIds), count($amounts));
                for ($i = 0; $i < $n; $i++) {
                    $code = trim($app->xss($codes[$i] ?? ''));
                    $size = intval($sizeIds[$i] ?? 0);
                    $color = intval($colorIds[$i] ?? 0);
                    $amount = floatval($amounts[$i] ?? 0);
                    $group = intval($groupCrafting[$i] ?? 1);
                    $rawPrice = str_replace([',', ' '], '', $app->xss($prices[$i] ?? 0));
                    $price = floatval($rawPrice);

                    if ($code === '' && $size === 0 && $color === 0 && $amount <= 0) {
                        continue;
                    }
                    if ($code === '') {
                        $error = $jatbi->lang('Vui lòng nhập mã ngọc (dòng') . ' ' . ($i + 1) . ') cho ' . ($s['pearl_name'] ?? '');
                        break 2;
                    }
                    if ($size === 0) {
                        $error = $jatbi->lang('Vui lòng chọn size (dòng') . ' ' . ($i + 1) . ') cho ' . ($s['pearl_name'] ?? '');
                        break 2;
                    }
                    if ($color === 0) {
                        $error = $jatbi->lang('Vui lòng chọn màu (dòng') . ' ' . ($i + 1) . ') cho ' . ($s['pearl_name'] ?? '');
                        break 2;
                    }
                    if ($amount <= 0) {
                        $error = $jatbi->lang('Số viên phải lớn hơn 0 (dòng') . ' ' . ($i + 1) . ') cho ' . ($s['pearl_name'] ?? '');
                        break 2;
                    }
                    if (!in_array($group, [1, 2, 3])) {
                        $error = $jatbi->lang('Vui lòng chọn kho chế tác đích (Vàng/Bạc/Chuỗi) (dòng') . ' ' . ($i + 1) . ') cho ' . ($s['pearl_name'] ?? '');
                        break 2;
                    }
                    if ($price <= 0) {
                        $error = $jatbi->lang('Vui lòng nhập giá tiền (dòng') . ' ' . ($i + 1) . ') cho ' . ($s['pearl_name'] ?? '');
                        break 2;
                    }
                    if (isset($codesUsed[$code])) {
                        $error = $jatbi->lang('Mã ngọc bị trùng:') . ' ' . $code;
                        break 2;
                    }
                    $codesUsed[$code] = 1;
                    $totalVien += $amount;
                    $rows[] = [
                        'code' => $code,
                        'sizes' => $size,
                        'colors' => $color,
                        'amount' => $amount,
                        'group_crafting' => $group,
                        'price' => $price,
                    ];
                }

                if ($error !== '') {
                    break;
                }
                if ($totalVien <= 0) {
                    $error = $jatbi->lang('Vui lòng nhập ít nhất 1 dòng thẩm định cho') . ' ' . ($s['pearl_name'] ?? '');
                    break;
                }
                if ($s['amount'] > 0 && abs($totalVien - $s['amount']) > 0.0001) {
                    $error = $jatbi->lang('Tổng số viên thẩm định phải bằng tồn kho (') . number_format($s['amount']) . ' viên) của ' . ($s['pearl_name'] ?? '');
                    break;
                }

                $cleanLines[$pearlId] = [
                    'pearl' => $pearlId,
                    'rows' => $rows,
                    'total_vien' => $totalVien,
                    'weight_kg' => floatval($s['weight_kg']),
                    'stock_amount_at_lt' => floatval($s['amount']),
                ];
            }

            if ($error === '' && empty($cleanLines)) {
                $error = $jatbi->lang('Vui lòng nhập dữ liệu thẩm định');
            }

            if ($error !== '') {
                echo json_encode(['status' => 'error', 'content' => $error]);
                return;
            }

            $userId = $app->getSession("accounts")['id'] ?? 0;
            $now = date('Y-m-d H:i:s');
            $batchId = $vars['id'];

            $ok = false;
            try {
                $app->action(function () use ($app, $jatbi, $batchId, $ltId, $ctId, $cleanLines, $userId, $now, &$ok) {
                    // 1. Ghi mã ngọc vào bảng ingredient (type=2) + lịch sử production_appraisal
                    foreach ($cleanLines as $cl) {
                        foreach ($cl['rows'] as $row) {
                            // Kho chế tác đích: 1=crafting (Vàng), 2=craftingsilver (Bạc), 3=craftingchain (Chuỗi)
                            $stockColumn = $row['group_crafting'] == 2 ? 'craftingsilver' : (($row['group_crafting'] == 3) ? 'craftingchain' : 'crafting');

                            $ing = $app->get("ingredient", ["id"], ["code" => $row['code'], "type" => 2, "deleted" => 0]);
                            if ($ing && !empty($ing['id'])) {
                                $ingId = $ing['id'];
                                $app->update("ingredient", [
                                    $stockColumn . "[+]" => $row['amount'],
                                    "price" => $row['price'],
                                    "cost" => $row['price'],
                                ], ["id" => $ingId]);
                            } else {
                                $app->insert("ingredient", [
                                    "code" => $row['code'],
                                    "sizes" => $row['sizes'],
                                    "colors" => $row['colors'],
                                    "pearl" => $cl['pearl'],
                                    "type" => 2,
                                    $stockColumn => $row['amount'],
                                    "price" => $row['price'],
                                    "cost" => $row['price'],
                                    "status" => 'A',
                                    "user" => $userId,
                                    "date" => $now,
                                    "deleted" => 0,
                                    "active" => $jatbi->active(32),
                                ]);
                                $ingId = $app->id();
                            }
                            if (!$ingId) return false;

                            $app->insert("production_appraisal", [
                                "batch" => $batchId,
                                "pearl" => $cl['pearl'],
                                "code" => $row['code'],
                                "sizes" => $row['sizes'],
                                "colors" => $row['colors'],
                                "amount" => $row['amount'],
                                "group_crafting" => $row['group_crafting'],
                                "price" => $row['price'],
                                "cost" => $row['price'],
                                "weight_kg" => $cl['weight_kg'] > 0 && $cl['total_vien'] > 0
                                    ? round($cl['weight_kg'] * $row['amount'] / $cl['total_vien'], 4)
                                    : 0,
                                "date" => $now,
                                "user" => $userId,
                                "deleted" => 0,
                            ]);
                        }
                    }

                    // 2. Phiếu xuất khỏi LT — chuyển toàn bộ tồn ngọc sang CT, hao hụt = 0
                    $app->insert("production_stage_movements", [
                        "batch" => $batchId, "stage" => $ltId, "type" => "export",
                        "stage_related" => $ctId, "notes" => "Thẩm định ngọc LT→CT",
                        "date" => $now, "user" => $userId, "deleted" => 0,
                    ]);
                    $exportMovementId = $app->id();
                    if (!$exportMovementId) return false;

                    foreach ($cleanLines as $cl) {
                        $app->insert("production_stage_movement_items", [
                            "movement" => $exportMovementId, "pearl" => $cl['pearl'],
                            "weight_kg" => $cl['weight_kg'], "amount" => $cl['stock_amount_at_lt'],
                            "weight_kg_hao_hut" => 0, "amount_hao_hut" => 0,
                            "deleted" => 0,
                        ]);
                    }

                    // 3. Phiếu nhập vào CT
                    $app->insert("production_stage_movements", [
                        "batch" => $batchId, "stage" => $ctId, "type" => "import",
                        "stage_related" => $ltId, "notes" => "Nhập Kho Chế Tác sau Thẩm định ngọc",
                        "date" => $now, "user" => $userId, "deleted" => 0,
                    ]);
                    $importMovementId = $app->id();
                    if (!$importMovementId) return false;

                    foreach ($cleanLines as $cl) {
                        $app->insert("production_stage_movement_items", [
                            "movement" => $importMovementId, "pearl" => $cl['pearl'],
                            "weight_kg" => $cl['weight_kg'], "amount" => $cl['total_vien'],
                            "weight_kg_hao_hut" => 0, "amount_hao_hut" => 0,
                            "deleted" => 0,
                        ]);
                    }

                    // 4. Lô chuyển hẳn sang Kho Chế Tác (CT)
                    $app->update("production_batches", ["current_stage" => $ctId], ["id" => $batchId]);

                    $ok = true;
                    return true;
                });
            } catch (\Exception $e) {
                $ok = false;
            }

            if ($ok) {
                $jatbi->logs('production_appraisal', 'appraisal_from_batch', ['batch' => $batchId, 'lines' => $cleanLines]);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Thẩm định ngọc thành công') . ': ' . $ltStage['name'] . ' → ' . $ctStage['name'], 'url' => $jatbi->url('/qaqc/stage/LT')]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Có lỗi xảy ra, vui lòng thử lại')]);
            }
        }
    })->setPermissions(['stage_appraisal']);


    // ============================================================
    // 1e. GIAI ĐOẠN 3 — DANH SÁCH "LÔ ĐANG TỒN TẠI TỪNG KHO"
    //     Hiển thị cho VS/KX/LT (CT sẽ do giai đoạn 4 xử lý riêng).
    //     (Trang tổng hợp/audit — vẫn giữ, dùng lọc theo dropdown.
    //     Xem thêm mục 1f bên dưới: 3 trang RIÊNG cho VS/KX/LT)
    // ============================================================

    $app->router('/stage-stock', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template) {
        if ($app->method() === 'GET') {
            $vars['title'] = $jatbi->lang('Tồn kho theo từng kho');
            $vars['stage_filter_options'] = array_merge(
                [['value' => '', 'text' => $jatbi->lang('Tất cả')]],
                $app->select('warehouse_stages', ['id(value)', 'name(text)'], ['code' => ['VS', 'KX', 'LT', 'CT', 'TP']])
            );
            $vars['pearl_filter_options'] = array_merge(
                [['value' => '', 'text' => $jatbi->lang('Tất cả')]],
                $app->select('pearl', ['id(value)', 'name(text)'], ['deleted' => 0, 'status' => 'A'])
            );
            echo $app->render($template . '/qaqc/stage-stock.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : ($setting['site_page'] ?? 10);

            $stageWhere = ["code" => ['VS', 'KX', 'LT', 'CT', 'TP']];
            if (isset($_POST['stage']) && $_POST['stage'] !== '') {
                $stageWhere = ["id" => $app->xss($_POST['stage'])];
            }
            $stages = $app->select('warehouse_stages', ['id', 'code', 'name'], $stageWhere);

            // Gộp tồn theo (batch, pearl) cho từng kho — tính bằng PHP để
            // tận dụng lại đúng công thức nhập-xuất của getBatchStockAtStage.
            $rows = [];
            foreach ($stages as $stage) {
                $agg = [];
                $app->select("production_stage_movement_items", [
                    "[><]production_stage_movements" => ["movement" => "id"],
                ], [
                    "production_stage_movements.batch",
                    "production_stage_movements.type",
                    "production_stage_movements.date",
                    "production_stage_movement_items.pearl",
                    "production_stage_movement_items.weight_kg",
                    "production_stage_movement_items.amount",
                ], [
                    "production_stage_movements.stage" => $stage['id'],
                    "production_stage_movements.deleted" => 0,
                    "production_stage_movement_items.deleted" => 0,
                ], function ($r) use (&$agg, $stage) {
                    $key = $r['batch'] . '-' . $r['pearl'];
                    if (!isset($agg[$key])) {
                        $agg[$key] = ['batch' => $r['batch'], 'pearl' => $r['pearl'], 'weight_kg' => 0, 'amount' => 0, 'last_date' => $r['date'], 'stage' => $stage];
                    }
                    $sign = ($r['type'] === 'import') ? 1 : -1;
                    $agg[$key]['weight_kg'] += $sign * floatval($r['weight_kg']);
                    $agg[$key]['amount'] += $sign * floatval($r['amount']);
                    if ($r['type'] === 'import' && $r['date'] > $agg[$key]['last_date']) {
                        $agg[$key]['last_date'] = $r['date'];
                    }
                });

                foreach ($agg as $a) {
                    if ($a['weight_kg'] > 0.0001 || $a['amount'] > 0.0001) {
                        $rows[] = $a;
                    }
                }
            }

            if (isset($_POST['pearl']) && $_POST['pearl'] !== '') {
                $pearlFilter = $app->xss($_POST['pearl']);
                $rows = array_values(array_filter($rows, function ($r) use ($pearlFilter) {
                    return $r['pearl'] == $pearlFilter;
                }));
            }

            $batchIds = array_values(array_unique(array_column($rows, 'batch')));
            $pearlIds = array_values(array_unique(array_column($rows, 'pearl')));
            $batchMap = [];
            $pearlMap = [];
            if (!empty($batchIds)) {
                $app->select('production_batches', ['id', 'code'], ['id' => $batchIds], function ($b) use (&$batchMap) {
                    $batchMap[$b['id']] = $b['code'];
                });
            }
            if (!empty($pearlIds)) {
                $app->select('pearl', ['id', 'name'], ['id' => $pearlIds], function ($p) use (&$pearlMap) {
                    $pearlMap[$p['id']] = $p['name'];
                });
            }

            usort($rows, function ($a, $b) use ($batchMap) {
                return strcmp($batchMap[$a['batch']] ?? '', $batchMap[$b['batch']] ?? '');
            });

            $count = count($rows);
            $paged = array_slice($rows, $start, $length);

            $datas = [];
            foreach ($paged as $r) {
                $datas[] = [
                    "code" => $batchMap[$r['batch']] ?? '-',
                    "pearl_name" => $pearlMap[$r['pearl']] ?? $jatbi->lang('Không xác định'),
                    "stage_name" => $r['stage']['name'],
                    "weight_kg" => $r['weight_kg'] > 0 ? number_format($r['weight_kg'], 2) . ' kg' : '-',
                    "amount" => $r['amount'] > 0 ? number_format($r['amount']) . ' ' . $jatbi->lang('viên') : '-',
                    "last_date" => date('d/m/Y H:i', strtotime($r['last_date'])),
                ];

                $stockBtns = [];
                if ($r['stage']['code'] === 'CT') {
                    $stockBtns[] = [
                        'type' => 'link',
                        'name' => $jatbi->lang("Nghiệm thu Chế tác"),
                        'permission' => ['crafting.process'],
                        'action' => ['href' => '/qaqc/crafting-process/' . $r['batch'], 'class' => 'pjax-load text-primary fw-semibold']
                    ];
                } elseif ($r['stage']['code'] === 'TP') {
                    $stockBtns[] = [
                        'type' => 'button',
                        'name' => $jatbi->lang("Xuất bán"),
                        'permission' => ['finish_stock.export'],
                        'action' => ['data-url' => '/qaqc/export-sell/' . $r['batch'], 'data-action' => 'modal']
                    ];
                    $stockBtns[] = [
                        'type' => 'link',
                        'name' => $jatbi->lang("Xuất Kho TP"),
                        'permission' => ['finish_stock.export'],
                        'action' => ['href' => '/qaqc/export-products/' . $r['batch'], 'class' => 'pjax-load text-primary fw-semibold']
                    ];
                    $stockBtns[] = [
                        'type' => 'button',
                        'name' => $jatbi->lang("Xuất Kho NL"),
                        'permission' => ['finish_stock.export'],
                        'action' => ['data-url' => '/qaqc/export-ingredient/' . $r['batch'], 'data-action' => 'modal']
                    ];
                } else {
                    if ($r['stage']['code'] === 'LT') {
                        // Bước LT -> CT thay "Chuyển kho" bằng "Thẩm định ngọc"
                        $stockBtns[] = [
                            'type' => 'link',
                            'name' => $jatbi->lang("Thẩm định ngọc"),
                            'permission' => ['stage_appraisal'],
                            'action' => ['href' => '/qaqc/appraisal/' . $r['batch'], 'class' => 'pjax-load text-primary fw-semibold']
                        ];
                    } else {
                        $stockBtns[] = [
                            'type' => 'link',
                            'name' => $jatbi->lang("Chuyển kho"),
                            'permission' => ['stage_transfer'],
                            'action' => ['href' => '/qaqc/stage-transfer/' . $r['stage']['code'], 'class' => 'pjax-load text-primary fw-semibold']
                        ];
                    }
                }

                $rowItem["action"] = $app->component("action", ["button" => $stockBtns]);
                $datas[] = $rowItem;
            }

            echo json_encode(["draw" => $draw, "recordsTotal" => $count, "recordsFiltered" => $count, "data" => $datas]);
        }
    })->setPermissions(['stage_stock']);


    // ============================================================
    // 1f. TÁCH TỪNG KHO NỘI BỘ THÀNH TRANG RIÊNG
    //     Kho Vệ Sinh (VS) / Kho Khoan Xuyên (KX) / Kho Lưu Trữ (LT)
    //     Dùng chung 1 route /stage/{code} cho dễ bảo trì, nhưng mỗi
    //     kho có menu + URL riêng (xem requests.php) nên người dùng
    //     bấm vào là thấy đúng 1 kho, không phải lọc/đoán như trước.
    //     Muốn mở rộng thêm kho khác: chỉ cần thêm 1 phần tử vào
    //     $stageWarehouseConfig bên dưới + 1 dòng menu trong requests.php.
    // ============================================================

    $stageWarehouseConfig = [
        'VS' => [
            'title'      => $jatbi->lang('Kho Vệ Sinh'),
            'permission' => 'stage_vs',
            'desc'       => $jatbi->lang('Ngọc thô từ lô sản xuất nằm ở đây sau khi "Nhập kho Vệ sinh". Dùng nút Chuyển kho để đưa sang Kho Khoan Xuyên.'),
        ],
        'KX' => [
            'title'      => $jatbi->lang('Kho Khoan Xuyên'),
            'permission' => 'stage_kx',
            'desc'       => $jatbi->lang('Ngọc đã vệ sinh, đang chờ/khoan xuyên tại đây. Dùng nút Chuyển kho để đưa sang Kho Lưu Trữ.'),
        ],
        'LT' => [
            'title'      => $jatbi->lang('Kho Lưu Trữ'),
            'permission' => 'stage_lt',
            'desc'       => $jatbi->lang('Ngọc chuyển đến được lưu nguyên định dạng (Kg/Gr/Viên) chờ Thẩm định ngọc. Dùng nút Thẩm định ngọc để gắn mã + thuộc tính rồi đưa sang Kho Chế Tác.'),
        ],
    ];

    $app->router('/stage/{code}', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template, $stageWarehouseConfig, $getStageByCode, $getBatchStockAtStage, $stageFlow) {
        $code = strtoupper($app->xss($vars['code'] ?? ''));
        if (!isset($stageWarehouseConfig[$code])) {
            echo $app->render($setting['template'] . '/pages/error.html', ['content' => $jatbi->lang('Kho không hợp lệ')], $jatbi->ajax());
            return;
        }
        $cfg = $stageWarehouseConfig[$code];

        if ($jatbi->permission([$cfg['permission']]) != 'true') {
            echo $app->render($setting['template'] . '/pages/error.html', ['content' => $jatbi->lang('Bạn không có quyền xem kho này')], $jatbi->ajax());
            return;
        }

        $stageInfo = $getStageByCode($code);
        if (!$stageInfo) {
            echo $app->render($setting['template'] . '/pages/error.html', ['content' => $jatbi->lang('Chưa cấu hình kho này trong warehouse_stages')], $jatbi->ajax());
            return;
        }

        if ($app->method() === 'GET') {
            $vars['title'] = $cfg['title'];
            $vars['stage_code'] = $code;
            $vars['stage_desc'] = $cfg['desc'];
            $vars['pearl_filter_options'] = array_merge(
                [['value' => '', 'text' => $jatbi->lang('Tất cả')]],
                $app->select('pearl', ['id(value)', 'name(text)'], ['deleted' => 0, 'status' => 'A'])
            );
            $vars['pending_import_count'] = $app->count("production_stage_movements", [
                "deleted" => 0,
                "type" => "export",
                "stage_related" => $stageInfo['id'],
                "receive_status" => 1,
            ]) ?? 0;
            echo $app->render($template . '/qaqc/stage-warehouse.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : ($setting['site_page'] ?? 10);
            $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';

            // 1. Lấy tất cả các dòng ngọc nhập vào kho này (theo từng phiếu nhập riêng biệt)
            $imports = [];
            $app->select("production_stage_movement_items", [
                "[><]production_stage_movements" => ["movement" => "id"],
            ], [
                "production_stage_movements.id(movement_id)",
                "production_stage_movements.batch",
                "production_stage_movements.date",
                "production_stage_movements.notes",
                "production_stage_movement_items.id(item_id)",
                "production_stage_movement_items.pearl",
                "production_stage_movement_items.weight_kg",
                "production_stage_movement_items.weight_gr",
                "production_stage_movement_items.amount",
            ], [
                "production_stage_movements.stage" => $stageInfo['id'],
                "production_stage_movements.type" => "import",
                "production_stage_movements.deleted" => 0,
                "production_stage_movement_items.deleted" => 0,
                "ORDER" => ["production_stage_movements.date" => "ASC", "production_stage_movements.id" => "ASC"],
            ], function ($r) use (&$imports) {
                $imports[] = [
                    "movement_id" => $r["movement_id"],
                    "batch" => $r["batch"],
                    "pearl" => $r["pearl"],
                    "weight_kg" => floatval($r["weight_kg"]),
                    "weight_gr" => floatval($r["weight_gr"] ?? 0),
                    "amount" => floatval($r["amount"]),
                    "date" => $r["date"],
                    "notes" => $r["notes"],
                ];
            });

            // 2. Lấy tổng lượng xuất khỏi kho này theo từng (batch, pearl) để trừ dần (FIFO)
            $totalExportedWeight = [];
            $totalExportedGr = [];
            $totalExportedAmount = [];
            $app->select("production_stage_movement_items", [
                "[><]production_stage_movements" => ["movement" => "id"],
            ], [
                "production_stage_movements.batch",
                "production_stage_movement_items.pearl",
                "production_stage_movement_items.weight_kg",
                "production_stage_movement_items.weight_gr",
                "production_stage_movement_items.amount",
                "production_stage_movement_items.weight_kg_hao_hut",
                "production_stage_movement_items.weight_gr_hao_hut",
                "production_stage_movement_items.amount_hao_hut",
            ], [
                "production_stage_movements.stage" => $stageInfo['id'],
                "production_stage_movements.type" => "export",
                "production_stage_movements.deleted" => 0,
                "production_stage_movement_items.deleted" => 0,
                "ORDER" => ["production_stage_movements.date" => "ASC", "production_stage_movements.id" => "ASC"],
            ], function ($r) use (&$totalExportedWeight, &$totalExportedGr, &$totalExportedAmount) {
                $k = $r["batch"] . "_" . $r["pearl"];
                $totalExportedWeight[$k] = ($totalExportedWeight[$k] ?? 0) + floatval($r["weight_kg"]) + floatval($r["weight_kg_hao_hut"] ?? 0);
                $totalExportedGr[$k] = ($totalExportedGr[$k] ?? 0) + floatval($r["weight_gr"] ?? 0) + floatval($r["weight_gr_hao_hut"] ?? 0);
                $totalExportedAmount[$k] = ($totalExportedAmount[$k] ?? 0) + floatval($r["amount"]) + floatval($r["amount_hao_hut"] ?? 0);
            });

            // 3. Khấu trừ lượng xuất khỏi các phiếu nhập cũ theo nguyên tắc FIFO
            $activeRows = [];
            foreach ($imports as $imp) {
                $k = $imp["batch"] . "_" . $imp["pearl"];
                $expW = $totalExportedWeight[$k] ?? 0;
                $expG = $totalExportedGr[$k] ?? 0;
                $expA = $totalExportedAmount[$k] ?? 0;

                // FIFO theo từng đơn vị riêng (kg / gram / viên) — không trộn lẫn
                $deductW = min($imp["weight_kg"], $expW);
                $deductG = min($imp["weight_gr"], $expG);
                $deductA = min($imp["amount"], $expA);

                $remainingW = $imp["weight_kg"] - $deductW;
                $remainingG = $imp["weight_gr"] - $deductG;
                $remainingA = $imp["amount"] - $deductA;

                $totalExportedWeight[$k] = $expW - $deductW;
                $totalExportedGr[$k] = $expG - $deductG;
                $totalExportedAmount[$k] = $expA - $deductA;

                if ($remainingW > 0.0001 || $remainingG > 0.0001 || $remainingA > 0.0001) {
                    $activeRows[] = [
                        "movement_id" => $imp["movement_id"],
                        "batch" => $imp["batch"],
                        "pearl" => $imp["pearl"],
                        "weight_kg" => $remainingW,
                        "weight_gr" => $remainingG,
                        "amount" => $remainingA,
                        "date" => $imp["date"],
                        "notes" => $imp["notes"],
                    ];
                }
            }

            $batchIds = array_values(array_unique(array_column($activeRows, 'batch')));
            $pearlIds = array_values(array_unique(array_column($activeRows, 'pearl')));
            $batchMap = [];
            $pearlMap = [];
            if (!empty($batchIds)) {
                $app->select('production_batches', ['id', 'code', 'status'], ['id' => $batchIds], function ($b) use (&$batchMap) {
                    $batchMap[$b['id']] = $b;
                });
            }
            if (!empty($pearlIds)) {
                $app->select('pearl', ['id', 'name', 'unit_mode'], ['id' => $pearlIds], function ($p) use (&$pearlMap) {
                    $pearlMap[$p['id']] = $p;
                });
            }

            if (isset($_POST['pearl']) && $_POST['pearl'] !== '') {
                $pearlFilter = intval($_POST['pearl']);
                $activeRows = array_values(array_filter($activeRows, function ($r) use ($pearlFilter) {
                    return $r['pearl'] == $pearlFilter;
                }));
            }

            if ($searchValue !== '') {
                $activeRows = array_values(array_filter($activeRows, function ($r) use ($searchValue, $batchMap, $pearlMap) {
                    $bCode = $batchMap[$r['batch']]['code'] ?? '';
                    $pName = $pearlMap[$r['pearl']]['name'] ?? '';
                    $mId = strval($r['movement_id']);
                    return (stripos($bCode, $searchValue) !== false || stripos($pName, $searchValue) !== false || stripos($mId, $searchValue) !== false || stripos('#NK-QAQC-' . $mId, $searchValue) !== false);
                }));
            }

            // Sắp xếp theo ngày nhập mới nhất
            usort($activeRows, function ($a, $b) {
                if ($a['date'] == $b['date']) {
                    return ($b['movement_id'] <=> $a['movement_id']);
                }
                return ($b['date'] <=> $a['date']);
            });

            $count = count($activeRows);
            $paged = array_slice($activeRows, $start, $length);
            $datas = [];

            foreach ($paged as $r) {
                $pInfo = $pearlMap[$r['pearl']] ?? null;
                $pName = $pInfo['name'] ?? $jatbi->lang('Không xác định');
                $isMaxima = (($pInfo['unit_mode'] ?? '') === 'kg_and_vien');
                $badgeUnit = $isMaxima
                    ? '<span class="badge bg-info ms-1">' . $jatbi->lang("Kg+viên") . '</span>'
                    : (($r['weight_gr'] ?? 0) > 0.0001
                        ? '<span class="badge bg-success ms-1">' . $jatbi->lang("Gram") . '</span>'
                        : '<span class="badge bg-warning text-dark ms-1">' . $jatbi->lang("Kg → viên") . '</span>');

                $bCode = $batchMap[$r['batch']]['code'] ?? '-';
                $mId = $r['movement_id'];

                $datas[] = [
                    "movement_code" => '<a href="#!" data-action="modal" data-url="/qaqc/stage-movement-views/' . $mId . '" class="fw-bold text-primary">#NK-QAQC-' . $mId . '</a>',
                    "pearl_name" => '<span class="fw-semibold text-body">' . htmlspecialchars($pName) . '</span>' . $badgeUnit,
                    "code" => '<span class="fw-bold text-body">#' . htmlspecialchars($bCode) . '</span>',
                    "weight_kg" => ($r['weight_gr'] ?? 0) > 0.0001 ? (number_format($r['weight_gr'], 2) . ' gr') : ($r['weight_kg'] > 0 ? number_format($r['weight_kg'], 2) . ' kg' : '-'),
                    "amount" => $r['amount'] > 0 ? number_format($r['amount']) . ' ' . $jatbi->lang("viên") : '<span class="text-secondary">-</span>',
                    "date" => date('d/m/Y H:i', strtotime($r['date'])),
                ];
            }

            echo json_encode(["draw" => $draw, "recordsTotal" => $count, "recordsFiltered" => $count, "data" => $datas]);
        }
    })->setPermissions(['stage_vs', 'stage_kx', 'stage_lt']);


    $getStageStockAgg = function ($stageId) use ($app, $jatbi) {
        $agg = [];
        $app->select("production_stage_movement_items", [
            "[><]production_stage_movements" => ["movement" => "id"],
        ], [
            "production_stage_movements.batch",
            "production_stage_movements.type",
            "production_stage_movements.date",
            "production_stage_movement_items.pearl",
            "production_stage_movement_items.weight_kg",
            "production_stage_movement_items.weight_gr",
            "production_stage_movement_items.amount",
            "production_stage_movement_items.weight_kg_hao_hut",
            "production_stage_movement_items.weight_gr_hao_hut",
            "production_stage_movement_items.amount_hao_hut",
        ], [
            "production_stage_movements.stage" => $stageId,
            "production_stage_movements.deleted" => 0,
            "production_stage_movement_items.deleted" => 0,
        ], function ($r) use (&$agg) {
            $key = $r['batch'] . '_' . $r['pearl'];
            if (!isset($agg[$key])) {
                $agg[$key] = [
                    'batch' => $r['batch'],
                    'pearl' => $r['pearl'],
                    'weight_kg' => 0,
                    'weight_gr' => 0,
                    'amount' => 0,
                    'last_date' => $r['date'],
                ];
            }
            if ($r['type'] === 'import') {
                $agg[$key]['weight_kg'] += floatval($r['weight_kg']);
                $agg[$key]['weight_gr'] += floatval($r['weight_gr'] ?? 0);
                $agg[$key]['amount'] += floatval($r['amount']);
            } else {
                $agg[$key]['weight_kg'] -= (floatval($r['weight_kg']) + floatval($r['weight_kg_hao_hut'] ?? 0));
                $agg[$key]['weight_gr'] -= (floatval($r['weight_gr'] ?? 0) + floatval($r['weight_gr_hao_hut'] ?? 0));
                $agg[$key]['amount'] -= (floatval($r['amount']) + floatval($r['amount_hao_hut'] ?? 0));
            }
        });

        $stockItems = [];
        $batchIds = [];
        $pearlIds = [];
        foreach ($agg as $a) {
            if ($a['weight_kg'] > 0.0001 || $a['amount'] > 0.0001 || $a['weight_gr'] > 0.0001) {
                $stockItems[] = $a;
                $batchIds[] = $a['batch'];
                $pearlIds[] = $a['pearl'];
            }
        }

        $batchMap = [];
        $pearlMap = [];
        if (!empty($batchIds)) {
            $app->select('production_batches', ['id', 'code'], ['id' => array_unique($batchIds)], function ($b) use (&$batchMap) {
                $batchMap[$b['id']] = $b['code'];
            });
        }
        if (!empty($pearlIds)) {
            $app->select('pearl', ['id', 'name', 'unit_mode'], ['id' => array_unique($pearlIds)], function ($p) use (&$pearlMap) {
                $pearlMap[$p['id']] = $p;
            });
        }

        foreach ($stockItems as &$it) {
            $it['batch_code'] = $batchMap[$it['batch']] ?? '-';
            $p = $pearlMap[$it['pearl']] ?? null;
            $it['pearl_name'] = $p['name'] ?? $jatbi->lang('Không xác định');
            $it['unit_mode'] = $p['unit_mode'] ?? 'kg';
        }
        unset($it);

        return $stockItems;
    };

    // Danh sách danh mục sản phẩm ngọc (pearl_categories) đang hoạt động,
    // dùng cho dropdown gắn danh mục khi chuyển kho.
    $getCategoryOptions = function () use ($app, $jatbi) {
        $options = [];
        $app->select("pearl_categories", ["id(value)", "name(text)"], [
            "deleted" => 0,
            "status" => 'A',
            "ORDER" => ["id" => "ASC"],
        ], function ($r) use (&$options) {
            $options[] = $r;
        });
        return $options;
    };

    // Map id -> name của danh mục để hiển thị sau khi tra cứu
    $getCategoryName = function ($id) use ($app) {
        if (empty($id)) return null;
        $row = $app->get("pearl_categories", ["name"], ["id" => $id, "deleted" => 0]);
        return $row['name'] ?? null;
    };

    // Bảng các hướng chuyển hợp lệ cho engine "giỏ hàng theo kho".
    // VS có 2 đích: KX (bước tiếp theo) hoặc LT (chuyển thẳng vào Lưu Trữ).
    // KX có 2 đích: LT hoặc VS (trả ngược về Vệ Sinh, vd phát hiện ngọc chưa sạch/lỗi).
    // Đích đầu tiên trong mảng là hướng mặc định khi không truyền ?to=.
    $transferAllowedTo = [
        'VS' => ['KX', 'LT'],
        'KX' => ['LT', 'VS'],
    ];

    // Chọn ra mã kho đích hợp lệ: ưu tiên $requested nếu nằm trong danh sách cho phép của $code,
    // nếu không thì trả về đích mặc định (phần tử đầu tiên). Trả về null nếu $code không hỗ trợ chuyển.
    $resolveTransferTo = function ($code, $requested) use ($transferAllowedTo) {
        $allowed = $transferAllowedTo[$code] ?? [];
        if (empty($allowed)) return null;
        $requested = strtoupper(trim((string)$requested));
        if ($requested !== '' && in_array($requested, $allowed, true)) {
            return $requested;
        }
        return $allowed[0];
    };

    // Thứ tự tuyến tính suy từ $stageFlow để phân biệt "đi tiếp" vs "trả về"
    $stageOrder = [];
    $_pos = 0;
    foreach ($stageFlow as $_f => $_t) {
        if (!isset($stageOrder[$_f])) $stageOrder[$_f] = $_pos++;
        if (!isset($stageOrder[$_t])) $stageOrder[$_t] = $_pos++;
    }

    $app->router('/stage-transfer/{code}', ['GET'], function ($vars) use ($app, $jatbi, $setting, $template, $stageWarehouseConfig, $getStageByCode, $getStageStockAgg, $transferAllowedTo, $resolveTransferTo, $stageOrder, $getCategoryOptions) {
        $code = strtoupper($app->xss($vars['code'] ?? ''));
        if (!isset($transferAllowedTo[$code])) {
            echo $app->render($setting['template'] . '/pages/error.html', ['content' => $jatbi->lang('Kho không hỗ trợ chức năng chuyển này')], $jatbi->ajax());
            return;
        }

        $fromStage = $getStageByCode($code);
        $toStageCode = $resolveTransferTo($code, $app->xss($_GET['to'] ?? ''));
        $toStage = $toStageCode ? $getStageByCode($toStageCode) : null;

        if (!$fromStage || !$toStage) {
            echo $app->render($setting['template'] . '/pages/error.html', ['content' => $jatbi->lang('Chưa cấu hình kho nguồn hoặc kho đích trong warehouse_stages')], $jatbi->ajax());
            return;
        }

        // Khoá lưu giỏ hàng theo cặp (nguồn_đích) để 2 hướng KX→LT và KX→VS
        // không bị lẫn dữ liệu vào nhau khi cùng thao tác trên kho KX.
        $sessionKey = $code . '_' . $toStageCode;

        $transfer_session = json_decode($app->getCookie('qaqc_transfer') ?? '{}', true) ?? [];
        if (json_last_error() !== JSON_ERROR_NONE) {
            $transfer_session = [];
        }

        $data = $transfer_session[$sessionKey] ?? [];
        $session_items = $data['items'] ?? [];

        // Làm mới tồn kho thực tế cho từng dòng đã chọn
        $stockItems = $getStageStockAgg($fromStage['id']);
        $stockMap = [];
        foreach ($stockItems as $stk) {
            $stockMap[$stk['batch'] . '_' . $stk['pearl']] = $stk;
        }

        foreach ($session_items as $k => &$item) {
            if (isset($stockMap[$k])) {
                $item['stock_kg'] = floatval($stockMap[$k]['weight_kg']);
                $stockGr = floatval($stockMap[$k]['weight_gr'] ?? 0);
                $item['stock_gr'] = $stockGr;
                $item['stock_vien'] = floatval($stockMap[$k]['amount']);
                $item['weight_gr'] = min(floatval($item['weight_gr'] ?? 0), $stockGr);
                $item['batch_code'] = $stockMap[$k]['batch_code'];
                $item['pearl_name'] = $stockMap[$k]['pearl_name'];
                $item['unit_mode'] = $stockMap[$k]['unit_mode'];
            }
        }
        unset($item);

        // Danh sách hướng đích khả dụng để hiển thị tab chọn hướng trên giao diện
        // (VS chỉ có 1 lựa chọn nên sẽ không hiện tab; KX có 2 lựa chọn: LT / VS).
        $directionOptions = [];
        foreach ($transferAllowedTo[$code] as $optCode) {
            $optStage = $getStageByCode($optCode);
            if (!$optStage) continue;
            $directionOptions[] = [
                'code' => $optCode,
                'name' => $optStage['name'],
                'active' => ($optCode === $toStageCode),
                // "Trả về" chỉ khi đích đứng TRƯỚC kho nguồn theo thứ tự luồng
                // (vd KX→VS, LT→KX). VS→LT là đi tiếp chứ không phải trả về.
                'is_return' => (
                    isset($stageOrder[$optCode]) && isset($stageOrder[$code]) &&
                    $stageOrder[$optCode] < $stageOrder[$code]
                ),
            ];
        }

        $vars['from_stage'] = $fromStage;
        $vars['to_stage'] = $toStage;
        $vars['direction_options'] = $directionOptions;
        $vars['allow_loss'] = ($code === 'KX');
        // Bỏ quy đổi kg → viên (khoan xiên) ở mọi bước: Kho Lưu Trữ nhận nguyên
        // định dạng (kg/gr/viên) như kho gửi, việc quy đổi thành viên nếu có sẽ
        // do người dùng tự nhập số liệu thực tế, không ép buộc đơn vị.
        $vars['allow_convert'] = false;
        $vars['is_convert'] = false;
        $vars['category_options'] = $getCategoryOptions();
        $vars['data'] = $data;
        $vars['SelectProducts'] = $session_items;
        $vars['stock_items'] = $stockItems;
        $vars['title'] = $jatbi->lang('Chuyển kho') . ': ' . $fromStage['name'] . ' → ' . $toStage['name'];

        echo $app->render($template . '/qaqc/stage-transfer-page.html', $vars);
    })->setPermissions(['stage_transfer']);

    // --- CÁC ROUTE CẬP NHẬT DỮ LIỆU CHUYỂN KHO QUA COOKIE ---

    // 1. Thêm ngọc vào danh sách
    $app->router('/stage-transfer-update/{code}/{to}/add/{batch}/{pearl}', 'POST', function ($vars) use ($app, $jatbi, $getStageByCode, $getStageStockAgg, $resolveTransferTo) {
        $app->header(['Content-Type' => 'application/json; charset=utf-8']);
        $code = strtoupper($app->xss($vars['code'] ?? ''));
        $toCode = $resolveTransferTo($code, $app->xss($vars['to'] ?? ''));
        $batchId = intval($vars['batch'] ?? 0);
        $pearlId = intval($vars['pearl'] ?? 0);

        $fromStage = $getStageByCode($code);
        if (!$fromStage || !$toCode) {
            echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Kho không hợp lệ')]);
            return;
        }
        $sessionKey = $code . '_' . $toCode;

        $stockItems = $getStageStockAgg($fromStage['id']);
        $item = null;
        foreach ($stockItems as $stk) {
            if ($stk['batch'] == $batchId && $stk['pearl'] == $pearlId) {
                $item = $stk;
                break;
            }
        }

        if (!$item) {
            echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Mặt hàng không còn tồn trong kho')]);
            return;
        }

        $transfer_session = json_decode($app->getCookie('qaqc_transfer') ?? '{}', true) ?? [];
        if (!isset($transfer_session[$sessionKey])) {
            $transfer_session[$sessionKey] = ['items' => [], 'notes' => ''];
        }

        $rowKey = $batchId . '_' . $pearlId;
        if (isset($transfer_session[$sessionKey]['items'][$rowKey])) {
            echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Mã lô này đã có trong danh sách')]);
            return;
        }

        $transfer_session[$sessionKey]['items'][$rowKey] = [
            'batch' => $batchId,
            'pearl' => $pearlId,
            'batch_code' => $item['batch_code'],
            'pearl_name' => $item['pearl_name'],
            'unit_mode' => $item['unit_mode'],
            'stock_kg' => floatval($item['weight_kg']),
            'stock_gr' => floatval($item['weight_gr'] ?? 0),
            'stock_vien' => floatval($item['amount']),
            // Chuyển NGUYÊN định dạng (kg/gr/viên) như kho gửi — không quy đổi
            // kg → viên ở bất kỳ bước nào (Kho Lưu Trữ nhận hết).
            'weight_kg' => floatval($item['weight_kg']),
            'weight_gr' => floatval($item['weight_gr'] ?? 0),
            'amount' => floatval($item['amount']),
            'weight_kg_hao_hut' => 0,
            'weight_gr_hao_hut' => 0,
            'amount_hao_hut' => 0,
            'category' => 0,
        ];

        $app->setCookie('qaqc_transfer', json_encode($transfer_session), time() + 86400, '/');
        echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Đã thêm vào danh sách chuyển')]);
    });

    // 2. Xóa khỏi danh sách
    $app->router('/stage-transfer-update/{code}/{to}/deleted/{key}', 'POST', function ($vars) use ($app, $jatbi, $resolveTransferTo) {
        $app->header(['Content-Type' => 'application/json; charset=utf-8']);
        $code = strtoupper($app->xss($vars['code'] ?? ''));
        $toCode = $resolveTransferTo($code, $app->xss($vars['to'] ?? ''));
        $sessionKey = $code . '_' . $toCode;
        $key = $app->xss($vars['key'] ?? '');

        $transfer_session = json_decode($app->getCookie('qaqc_transfer') ?? '{}', true) ?? [];
        if (isset($transfer_session[$sessionKey]['items'][$key])) {
            unset($transfer_session[$sessionKey]['items'][$key]);
            $app->setCookie('qaqc_transfer', json_encode($transfer_session), time() + 86400, '/');
        }
        echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Đã xóa khỏi danh sách')]);
    });

    // 3. Cập nhật kg chuyển
    $app->router('/stage-transfer-update/{code}/{to}/weight_kg/{key}', 'POST', function ($vars) use ($app, $jatbi, $resolveTransferTo) {
        $app->header(['Content-Type' => 'application/json; charset=utf-8']);
        $code = strtoupper($app->xss($vars['code'] ?? ''));
        $toCode = $resolveTransferTo($code, $app->xss($vars['to'] ?? ''));
        $sessionKey = $code . '_' . $toCode;
        $key = $app->xss($vars['key'] ?? '');
        $val = floatval($app->xss(str_replace(',', '', $_POST['value'] ?? 0)));

        $transfer_session = json_decode($app->getCookie('qaqc_transfer') ?? '{}', true) ?? [];
        if (isset($transfer_session[$sessionKey]['items'][$key])) {
            $stockKg = floatval($transfer_session[$sessionKey]['items'][$key]['stock_kg'] ?? 0);
            if ($val < 0) $val = 0;
            if ($val > $stockKg) $val = $stockKg;
            $transfer_session[$sessionKey]['items'][$key]['weight_kg'] = $val;

            $lossKg = floatval($transfer_session[$sessionKey]['items'][$key]['weight_kg_hao_hut'] ?? 0);
            if ($val + $lossKg > $stockKg) {
                $transfer_session[$sessionKey]['items'][$key]['weight_kg_hao_hut'] = max(0, round($stockKg - $val, 2));
            }

            $app->setCookie('qaqc_transfer', json_encode($transfer_session), time() + 86400, '/');
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Cập nhật thành công')]);
        } else {
            echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Không tìm thấy dòng ngọc')]);
        }
    });

    // 4. Cập nhật viên chuyển
    $app->router('/stage-transfer-update/{code}/{to}/amount/{key}', 'POST', function ($vars) use ($app, $jatbi, $resolveTransferTo) {
        $app->header(['Content-Type' => 'application/json; charset=utf-8']);
        $code = strtoupper($app->xss($vars['code'] ?? ''));
        $toCode = $resolveTransferTo($code, $app->xss($vars['to'] ?? ''));
        $sessionKey = $code . '_' . $toCode;
        $key = $app->xss($vars['key'] ?? '');
        $val = intval($app->xss(str_replace(',', '', $_POST['value'] ?? 0)));

        $transfer_session = json_decode($app->getCookie('qaqc_transfer') ?? '{}', true) ?? [];
        if (isset($transfer_session[$sessionKey]['items'][$key])) {
            $stockVien = intval($transfer_session[$sessionKey]['items'][$key]['stock_vien'] ?? 0);
            if ($val < 0) $val = 0;
            if ($stockVien > 0 && $val > $stockVien) $val = $stockVien;
            $transfer_session[$sessionKey]['items'][$key]['amount'] = $val;

            $lossVien = intval($transfer_session[$sessionKey]['items'][$key]['amount_hao_hut'] ?? 0);
            if ($stockVien > 0 && $val + $lossVien > $stockVien) {
                $transfer_session[$sessionKey]['items'][$key]['amount_hao_hut'] = max(0, $stockVien - $val);
            }

            $app->setCookie('qaqc_transfer', json_encode($transfer_session), time() + 86400, '/');
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Cập nhật thành công')]);
        } else {
            echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Không tìm thấy dòng ngọc')]);
        }
    });

    // 4a2. Cập nhật danh mục sản phẩm ngọc cho dòng chuyển
    $app->router('/stage-transfer-update/{code}/{to}/category/{key}', 'POST', function ($vars) use ($app, $jatbi, $resolveTransferTo) {
        $app->header(['Content-Type' => 'application/json; charset=utf-8']);
        $code = strtoupper($app->xss($vars['code'] ?? ''));
        $toCode = $resolveTransferTo($code, $app->xss($vars['to'] ?? ''));
        $sessionKey = $code . '_' . $toCode;
        $key = $app->xss($vars['key'] ?? '');
        $val = intval($app->xss(str_replace(',', '', $_POST['value'] ?? 0)));

        $transfer_session = json_decode($app->getCookie('qaqc_transfer') ?? '{}', true) ?? [];
        if (isset($transfer_session[$sessionKey]['items'][$key])) {
            $transfer_session[$sessionKey]['items'][$key]['category'] = $val;
            $app->setCookie('qaqc_transfer', json_encode($transfer_session), time() + 86400, '/');
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Cập nhật thành công')]);
        } else {
            echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Không tìm thấy dòng ngọc')]);
        }
    });

    // 4b. Cập nhật gram chuyển
    $app->router('/stage-transfer-update/{code}/{to}/weight_gr/{key}', 'POST', function ($vars) use ($app, $jatbi, $resolveTransferTo) {
        $app->header(['Content-Type' => 'application/json; charset=utf-8']);
        $code = strtoupper($app->xss($vars['code'] ?? ''));
        $toCode = $resolveTransferTo($code, $app->xss($vars['to'] ?? ''));
        $sessionKey = $code . '_' . $toCode;
        $key = $app->xss($vars['key'] ?? '');
        $val = floatval($app->xss(str_replace(',', '', $_POST['value'] ?? 0)));

        $transfer_session = json_decode($app->getCookie('qaqc_transfer') ?? '{}', true) ?? [];
        if (isset($transfer_session[$sessionKey]['items'][$key])) {
            $stockGr = floatval($transfer_session[$sessionKey]['items'][$key]['stock_gr'] ?? 0);
            if ($val < 0) $val = 0;
            if ($val > $stockGr) $val = $stockGr;
            $transfer_session[$sessionKey]['items'][$key]['weight_gr'] = $val;

            $lossGr = floatval($transfer_session[$sessionKey]['items'][$key]['weight_gr_hao_hut'] ?? 0);
            if ($val + $lossGr > $stockGr) {
                $transfer_session[$sessionKey]['items'][$key]['weight_gr_hao_hut'] = max(0, round($stockGr - $val, 3));
            }

            $app->setCookie('qaqc_transfer', json_encode($transfer_session), time() + 86400, '/');
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Cập nhật thành công')]);
        } else {
            echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Không tìm thấy dòng ngọc')]);
        }
    });

    // 4c. Cập nhật gram hao hụt
    $app->router('/stage-transfer-update/{code}/{to}/loss_gr/{key}', 'POST', function ($vars) use ($app, $jatbi, $resolveTransferTo) {
        $app->header(['Content-Type' => 'application/json; charset=utf-8']);
        $code = strtoupper($app->xss($vars['code'] ?? ''));
        $toCode = $resolveTransferTo($code, $app->xss($vars['to'] ?? ''));
        $sessionKey = $code . '_' . $toCode;
        $key = $app->xss($vars['key'] ?? '');
        $val = floatval($app->xss(str_replace(',', '', $_POST['value'] ?? 0)));

        $transfer_session = json_decode($app->getCookie('qaqc_transfer') ?? '{}', true) ?? [];
        if (isset($transfer_session[$sessionKey]['items'][$key])) {
            $stockGr = floatval($transfer_session[$sessionKey]['items'][$key]['stock_gr'] ?? 0);
            if ($val < 0) $val = 0;
            if ($val > $stockGr) $val = $stockGr;
            $transfer_session[$sessionKey]['items'][$key]['weight_gr_hao_hut'] = $val;
            $transfer_session[$sessionKey]['items'][$key]['weight_gr'] = max(0, round($stockGr - $val, 3));

            $app->setCookie('qaqc_transfer', json_encode($transfer_session), time() + 86400, '/');
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Cập nhật thành công')]);
        } else {
            echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Không tìm thấy dòng ngọc')]);
        }
    });

    // 5. Cập nhật kg hao hụt (Kho KX)
    $app->router('/stage-transfer-update/{code}/{to}/loss_kg/{key}', 'POST', function ($vars) use ($app, $jatbi, $resolveTransferTo) {
        $app->header(['Content-Type' => 'application/json; charset=utf-8']);
        $code = strtoupper($app->xss($vars['code'] ?? ''));
        $toCode = $resolveTransferTo($code, $app->xss($vars['to'] ?? ''));
        $sessionKey = $code . '_' . $toCode;
        $key = $app->xss($vars['key'] ?? '');
        $val = floatval($app->xss(str_replace(',', '', $_POST['value'] ?? 0)));

        $transfer_session = json_decode($app->getCookie('qaqc_transfer') ?? '{}', true) ?? [];
        if (isset($transfer_session[$sessionKey]['items'][$key])) {
            $stockKg = floatval($transfer_session[$sessionKey]['items'][$key]['stock_kg'] ?? 0);
            if ($val < 0) $val = 0;
            if ($val > $stockKg) $val = $stockKg;
            $transfer_session[$sessionKey]['items'][$key]['weight_kg_hao_hut'] = $val;
            // Chuyển nguyên định dạng: phần còn lại sau hao hụt vẫn là kg.
            $transfer_session[$sessionKey]['items'][$key]['weight_kg'] = max(0, round($stockKg - $val, 2));

            $app->setCookie('qaqc_transfer', json_encode($transfer_session), time() + 86400, '/');
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Cập nhật thành công')]);
        } else {
            echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Không tìm thấy dòng ngọc')]);
        }
    });

    // 6. Cập nhật viên hao hụt (Kho KX)
    $app->router('/stage-transfer-update/{code}/{to}/loss_vien/{key}', 'POST', function ($vars) use ($app, $jatbi, $resolveTransferTo) {
        $app->header(['Content-Type' => 'application/json; charset=utf-8']);
        $code = strtoupper($app->xss($vars['code'] ?? ''));
        $toCode = $resolveTransferTo($code, $app->xss($vars['to'] ?? ''));
        $sessionKey = $code . '_' . $toCode;
        $key = $app->xss($vars['key'] ?? '');
        $val = intval($app->xss(str_replace(',', '', $_POST['value'] ?? 0)));

        $transfer_session = json_decode($app->getCookie('qaqc_transfer') ?? '{}', true) ?? [];
        if (isset($transfer_session[$sessionKey]['items'][$key])) {
            $stockVien = intval($transfer_session[$sessionKey]['items'][$key]['stock_vien'] ?? 0);
            if ($val < 0) $val = 0;
            if ($stockVien > 0 && $val > $stockVien) $val = $stockVien;
            $transfer_session[$sessionKey]['items'][$key]['amount_hao_hut'] = $val;
            $transfer_session[$sessionKey]['items'][$key]['amount'] = max(0, $stockVien - $val);

            $app->setCookie('qaqc_transfer', json_encode($transfer_session), time() + 86400, '/');
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Cập nhật thành công')]);
        } else {
            echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Không tìm thấy dòng ngọc')]);
        }
    });

    // 7. Cập nhật ghi chú
    $app->router('/stage-transfer-update/{code}/{to}/notes', 'POST', function ($vars) use ($app, $jatbi, $resolveTransferTo) {
        $app->header(['Content-Type' => 'application/json; charset=utf-8']);
        $code = strtoupper($app->xss($vars['code'] ?? ''));
        $toCode = $resolveTransferTo($code, $app->xss($vars['to'] ?? ''));
        $sessionKey = $code . '_' . $toCode;
        $val = trim($app->xss($_POST['value'] ?? ''));

        $transfer_session = json_decode($app->getCookie('qaqc_transfer') ?? '{}', true) ?? [];
        if (!isset($transfer_session[$sessionKey])) {
            $transfer_session[$sessionKey] = ['items' => [], 'notes' => ''];
        }
        $transfer_session[$sessionKey]['notes'] = $val;
        $app->setCookie('qaqc_transfer', json_encode($transfer_session), time() + 86400, '/');
        echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Cập nhật thành công')]);
    });

    // 8. Chọn tất cả ngọc tồn
    $app->router('/stage-transfer-update/{code}/{to}/select-all', 'POST', function ($vars) use ($app, $jatbi, $getStageByCode, $getStageStockAgg, $resolveTransferTo) {
        $app->header(['Content-Type' => 'application/json; charset=utf-8']);
        $code = strtoupper($app->xss($vars['code'] ?? ''));
        $toCode = $resolveTransferTo($code, $app->xss($vars['to'] ?? ''));
        $fromStage = $getStageByCode($code);
        if (!$fromStage || !$toCode) {
            echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Kho không hợp lệ')]);
            return;
        }
        $sessionKey = $code . '_' . $toCode;

        $stockItems = $getStageStockAgg($fromStage['id']);
        $transfer_session = json_decode($app->getCookie('qaqc_transfer') ?? '{}', true) ?? [];
        if (!isset($transfer_session[$sessionKey])) {
            $transfer_session[$sessionKey] = ['items' => [], 'notes' => ''];
        }

        foreach ($stockItems as $it) {
            $rowKey = $it['batch'] . '_' . $it['pearl'];
            if (!isset($transfer_session[$sessionKey]['items'][$rowKey])) {
                $transfer_session[$sessionKey]['items'][$rowKey] = [
                    'batch' => $it['batch'],
                    'pearl' => $it['pearl'],
                    'batch_code' => $it['batch_code'],
                    'pearl_name' => $it['pearl_name'],
                    'unit_mode' => $it['unit_mode'],
                    'stock_kg' => floatval($it['weight_kg']),
                    'stock_gr' => floatval($it['weight_gr'] ?? 0),
                    'stock_vien' => floatval($it['amount']),
                    // Chuyển nguyên định dạng (không quy đổi kg → viên).
                    'weight_kg' => floatval($it['weight_kg']),
                    'weight_gr' => floatval($it['weight_gr'] ?? 0),
                    'amount' => floatval($it['amount']),
                    'weight_kg_hao_hut' => 0,
                    'weight_gr_hao_hut' => 0,
                    'amount_hao_hut' => 0,
                    'category' => 0,
                ];
            }
        }

        $app->setCookie('qaqc_transfer', json_encode($transfer_session), time() + 86400, '/');
        echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Đã chọn tất cả')]);
    });

    // 9. Hủy danh sách
    $app->router('/stage-transfer-update/{code}/{to}/cancel', 'POST', function ($vars) use ($app, $jatbi, $resolveTransferTo) {
        $app->header(['Content-Type' => 'application/json; charset=utf-8']);
        $code = strtoupper($app->xss($vars['code'] ?? ''));
        $toCode = $resolveTransferTo($code, $app->xss($vars['to'] ?? ''));
        $sessionKey = $code . '_' . $toCode;
        $transfer_session = json_decode($app->getCookie('qaqc_transfer') ?? '{}', true) ?? [];
        if (isset($transfer_session[$sessionKey])) {
            unset($transfer_session[$sessionKey]);
            $app->setCookie('qaqc_transfer', json_encode($transfer_session), time() + 86400, '/');
        }
        echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Đã hủy danh sách')]);
    });

    // 10. Hoàn tất chuyển kho
    $app->router('/stage-transfer-update/{code}/{to}/completed', 'POST', function ($vars) use ($app, $jatbi, $setting, $getStageByCode, $resolveTransferTo) {
        $app->header(['Content-Type' => 'application/json; charset=utf-8']);
        $code = strtoupper($app->xss($vars['code'] ?? ''));
        $toStageCode = $resolveTransferTo($code, $app->xss($vars['to'] ?? ''));
        $fromStage = $getStageByCode($code);
        $toStage = $toStageCode ? $getStageByCode($toStageCode) : null;

        if (!$fromStage || !$toStage) {
            echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Chưa cấu hình kho')]);
            return;
        }
        $sessionKey = $code . '_' . $toStageCode;

        $transfer_session = json_decode($app->getCookie('qaqc_transfer') ?? '{}', true) ?? [];
        $data = $transfer_session[$sessionKey] ?? [];
        $items = $data['items'] ?? [];

        if (empty($items)) {
            echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Vui lòng tìm kiếm và chọn ít nhất 1 dòng ngọc để chuyển')]);
            return;
        }

        $notes = trim($data['notes'] ?? '');
        $notes = ($notes !== '') ? $notes : ("Chuyển kho nội bộ (" . $fromStage['name'] . " → " . $toStage['name'] . ")");
        $userId = $app->getSession("accounts")['id'] ?? 0;
        $now = date('Y-m-d H:i:s');
        $fromId = $fromStage['id'];
        $toId = $toStage['id'];

        // Gộp theo từng batch để tạo phiếu xuất / nhập
        $byBatch = [];
        foreach ($items as $k => $it) {
            $bId = $it['batch'];
            if (!isset($byBatch[$bId])) {
                $byBatch[$bId] = [];
            }
            $byBatch[$bId][] = $it;
        }

        $ok = false;
        try {
            $app->action(function () use ($app, $byBatch, $fromId, $toId, $userId, $now, $fromStage, $toStage, $notes, $code, $toStageCode, &$ok) {
                foreach ($byBatch as $bId => $lines) {
                    // Phiếu xuất khỏi kho nguồn (Chờ kho đích bấm 'Nhập hàng' để nhận)
                    $app->insert("production_stage_movements", [
                        "batch" => $bId, "stage" => $fromId, "type" => "export",
                        "stage_related" => $toId, "receive_status" => 1, "notes" => $notes,
                        "date" => $now, "user" => $userId, "deleted" => 0,
                    ]);
                    $exportMovementId = $app->id();
                    if (!$exportMovementId) return false;

                    foreach ($lines as $cl) {
                        // Chuyển nguyên định dạng (kg/gr/viên) như kho gửi; không
                        // quy đổi kg → viên nữa (Kho Lưu Trữ nhận hết các đơn vị).
                        $app->insert("production_stage_movement_items", [
                            "movement" => $exportMovementId, "pearl" => $cl['pearl'],
                            "weight_kg" => $cl['weight_kg'] ?? 0, "weight_gr" => $cl['weight_gr'] ?? 0, "amount" => $cl['amount'],
                            "weight_kg_hao_hut" => $cl['weight_kg_hao_hut'] ?? 0, "weight_gr_hao_hut" => $cl['weight_gr_hao_hut'] ?? 0, "amount_hao_hut" => $cl['amount_hao_hut'] ?? 0,
                            "category" => intval($cl['category'] ?? 0),
                            "deleted" => 0,
                        ]);
                    }

                    // Ghi nhật ký hao hụt nếu có
                    foreach ($lines as $cl) {
                        $lossKg = floatval($cl['weight_kg_hao_hut'] ?? 0);
                        $lossGr = floatval($cl['weight_gr_hao_hut'] ?? 0);
                        $lossAmt = floatval($cl['amount_hao_hut'] ?? 0);
                        if ($lossKg > 0 || $lossGr > 0 || $lossAmt > 0) {
                            $app->insert("production_loss_logs", [
                                "batch_id" => $bId,
                                "stage" => $fromStage['name'],
                                "loss_piece" => $lossAmt,
                                "loss_weight_kg" => $lossKg,
                                "loss_weight_gr" => $lossGr,
                                "date" => $now,
                                "user_id" => $userId,
                                "reason" => "Hao hụt khi chuyển " . $fromStage['name'] . " → " . $toStage['name'],
                            ]);
                        }
                    }
                }

                $ok = true;
                return true;
            });
        } catch (\Exception $e) {
            $ok = false;
        }

        if ($ok) {
            unset($transfer_session[$sessionKey]);
            $app->setCookie('qaqc_transfer', json_encode($transfer_session), time() + 86400, '/');

            $jatbi->logs('production_stage_movements', 'transfer_batch_multi', ['from' => $fromId, 'to' => $toId, 'by_batch' => $byBatch]);
            echo json_encode([
                'status' => 'success',
                'content' => $jatbi->lang('Đã xuất chuyển kho thành công, đang chờ') . ' ' . $toStage['name'] . ' ' . $jatbi->lang('nhập hàng'),
                'url' => $jatbi->url('/qaqc/stage-history/' . $fromStage['code'])
            ]);
        } else {
            echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Có lỗi xảy ra, vui lòng thử lại')]);
        }
    })->setPermissions(['stage_transfer']);

    // ============================================================
    // 1h. DANH SÁCH CHỜ NHẬP HÀNG CHUYỂN KHO QAQC (/stage-import-move/{code})
    // ============================================================
    $app->router('/stage-import-move/{code}', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template, $stageWarehouseConfig, $getStageByCode) {
        $code = strtoupper($app->xss($vars['code'] ?? 'KX'));
        $toStage = $getStageByCode($code);
        if (!$toStage) {
            $code = 'KX';
            $toStage = $getStageByCode('KX');
        }

        if ($app->method() === 'GET') {
            $stageNames = [
                'KX' => $jatbi->lang('Danh sách nhập hàng kho khoan xiên'),
                'LT' => $jatbi->lang('Danh sách nhập hàng kho lưu trữ'),
                'VS' => $jatbi->lang('Danh sách nhập hàng kho vệ sinh'),
            ];
            $vars['title'] = $stageNames[$code] ?? ($jatbi->lang('Danh sách nhập hàng') . ' ' . ($toStage['name'] ?? ''));
            $vars['stage_code'] = $code;
            $vars['to_stage'] = $toStage;
            $vars['date_from'] = date('01/m/Y');
            $vars['date_to'] = date('d/m/Y');

            $empty_option = [['value' => '', 'text' => $jatbi->lang('Tất cả')]];
            $accounts_db = $app->select("accounts", ["id(value)", "name(text)"], ["deleted" => 0, "status" => 'A']) ?? [];
            $vars['accounts'] = array_merge($empty_option, $accounts_db);

            echo $app->render($template . '/qaqc/stage-import-move.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);

            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $searchValue = isset($_POST['search']['value']) ? trim($_POST['search']['value']) : '';
            $filter_user = isset($_POST['user']) ? intval($_POST['user']) : 0;
            $filter_date = isset($_POST['date']) ? trim($app->xss($_POST['date'])) : '';

            $where = [
                "AND" => [
                    "production_stage_movements.deleted" => 0,
                    "production_stage_movements.type" => "export",
                    "production_stage_movements.stage_related" => $toStage['id'],
                    "production_stage_movements.receive_status" => 1,
                ]
            ];

            if (!empty($filter_user)) {
                $where["AND"]["production_stage_movements.user"] = $filter_user;
            }

            if (!empty($filter_date)) {
                $dates = explode(' - ', $filter_date);
                if (count($dates) === 2) {
                    $dFrom = date('Y-m-d 00:00:00', strtotime(str_replace('/', '-', trim($dates[0]))));
                    $dTo = date('Y-m-d 23:59:59', strtotime(str_replace('/', '-', trim($dates[1]))));
                    $where["AND"]["production_stage_movements.date[<>]"] = [$dFrom, $dTo];
                }
            }

            $joins = [
                "[><]production_batches" => ["batch" => "id"],
                "[><]warehouse_stages" => ["stage" => "id"],
                "[>]accounts" => ["user" => "id"],
            ];

            if (!empty($searchValue)) {
                $where["AND"]["OR"] = [
                    "production_batches.code[~]" => $searchValue,
                    "production_stage_movements.notes[~]" => $searchValue,
                    "production_stage_movements.id[~]" => $searchValue,
                ];
            }

            $count = $app->count("production_stage_movements", $joins, "production_stage_movements.id", $where);

            $where["ORDER"] = ["production_stage_movements.date" => "DESC", "production_stage_movements.id" => "DESC"];
            $where["LIMIT"] = [$start, $length];

            $columns = [
                "production_stage_movements.id",
                "production_stage_movements.batch",
                "production_stage_movements.stage",
                "production_stage_movements.stage_related",
                "production_stage_movements.notes",
                "production_stage_movements.date",
                "production_batches.code(batch_code)",
                "warehouse_stages.name(from_stage_name)",
                "accounts.name(user_name)",
            ];

            $datas = $app->select("production_stage_movements", $joins, $columns, $where) ?? [];

            $movementIds = array_column($datas, 'id');
            $itemsMap = [];
            if (!empty($movementIds)) {
                $app->select("production_stage_movement_items", [
                    "[><]pearl" => ["pearl" => "id"],
                ], [
                    "production_stage_movement_items.movement",
                    "production_stage_movement_items.weight_kg",
                    "production_stage_movement_items.weight_gr",
                    "production_stage_movement_items.amount",
                    "pearl.name(pearl_name)",
                    "pearl.unit_mode",
                ], [
                    "production_stage_movement_items.movement" => $movementIds,
                    "production_stage_movement_items.deleted" => 0,
                ], function ($it) use (&$itemsMap) {
                    $itemsMap[$it['movement']][] = $it;
                });
            }

            $resultData = [];
            foreach ($datas as $r) {
                $mId = $r['id'];
                $mItems = $itemsMap[$mId] ?? [];

                $summaryParts = [];
                foreach ($mItems as $mi) {
                    $isMax = (($mi['unit_mode'] ?? '') === 'kg_and_vien');
                    $kg = floatval($mi['weight_kg']);
                    $gr = floatval($mi['weight_gr'] ?? 0);
                    $vien = floatval($mi['amount']);
                    $sub = [];
                    if ($gr > 0.0001) $sub[] = number_format($gr, 2) . ' gr';
                    if ($kg > 0) $sub[] = number_format($kg, 2) . ' kg';
                    if ($vien > 0) $sub[] = number_format($vien) . ' v';
                    $summaryParts[] = $mi['pearl_name'] . ' (' . implode(' · ', $sub) . ')';
                }

                $contentStr = $r['notes'] ?: ('Chuyển từ ' . ($r['from_stage_name'] ?? ''));
                $contentStr .= ' - Lô #' . $r['batch_code'];
                if (!empty($summaryParts)) {
                    $contentStr .= ' (' . implode(', ', $summaryParts) . ')';
                }

                $codeLink = '<a href="#!" data-action="modal" data-url="/qaqc/stage-movement-views/' . $mId . '" class="fw-bold text-primary">#XK-QAQC-' . $mId . '</a>';
                
                $actionBtn = '<a class="btn btn-sm btn-primary pjax-load px-3 rounded-pill fw-semibold" data-action="modal" data-url="/qaqc/stage-import-receive/' . $mId . '">' . $jatbi->lang("Nhập hàng") . '</a>'
                           . '<a class="btn btn-sm btn-light ms-2 rounded-circle p-2" data-action="modal" data-url="/qaqc/stage-movement-views/' . $mId . '" title="' . $jatbi->lang("Xem chi tiết") . '"><i class="ti ti-eye"></i></a>';

                $resultData[] = [
                    "code" => $codeLink,
                    "content" => htmlspecialchars($contentStr),
                    "date" => date('d/m/Y H:i:s', strtotime($r['date'])),
                    "user" => htmlspecialchars($r['user_name'] ?? '-'),
                    "action" => $actionBtn,
                ];
            }

            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => $count,
                "recordsFiltered" => $count,
                "data" => $resultData
            ]);
        }
    })->setPermissions(['stage_transfer', 'stage_history', 'stage_import_move', 'stage_vs', 'stage_kx', 'stage_lt']);

    // ============================================================
    // 1i. THỰC HIỆN NHẬP HÀNG CHUYỂN KHO QAQC (/stage-import-receive/{id})
    // ============================================================
    $app->router('/stage-import-receive/{id}', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $id = intval($vars['id'] ?? 0);
        $exportMovement = $app->get("production_stage_movements", "*", [
            "id" => $id,
            "type" => "export",
            "receive_status" => 1,
            "deleted" => 0
        ]);

        if (!$exportMovement) {
            if ($app->method() === 'POST') {
                $app->header(['Content-Type' => 'application/json; charset=utf-8']);
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Phiếu chuyển kho không tồn tại hoặc đã được nhập')]);
                return;
            } else {
                echo '<div class="modal fade modal-load"><div class="modal-dialog"><div class="modal-content p-4 text-center text-danger">' . $jatbi->lang("Phiếu không tồn tại hoặc đã được nhập") . '</div></div></div>';
                return;
            }
        }

        $batch = $app->get("production_batches", ["id", "code", "notes"], ["id" => $exportMovement['batch']]);
        $fromStage = $app->get("warehouse_stages", ["id", "code", "name"], ["id" => $exportMovement['stage']]);
        $toStage = $app->get("warehouse_stages", ["id", "code", "name"], ["id" => $exportMovement['stage_related']]);
        $user = $app->get("accounts", ["id", "name"], ["id" => $exportMovement['user']]);

        $items = $app->select("production_stage_movement_items", [
            "[><]pearl" => ["pearl" => "id"],
        ], [
            "production_stage_movement_items.id",
            "production_stage_movement_items.pearl",
            "production_stage_movement_items.weight_kg",
            "production_stage_movement_items.weight_gr",
            "production_stage_movement_items.amount",
            "production_stage_movement_items.weight_kg_hao_hut",
            "production_stage_movement_items.amount_hao_hut",
            "production_stage_movement_items.category",
            "pearl.name(pearl_name)",
            "pearl.unit_mode",
        ], [
            "production_stage_movement_items.movement" => $id,
            "production_stage_movement_items.deleted" => 0,
        ]) ?? [];

        if ($app->method() === 'GET') {
            $categoryIds = array_values(array_unique(array_filter(array_column($items, 'category'))));
            $categoryMap = [];
            if (!empty($categoryIds)) {
                $app->select("pearl_categories", ["id", "name"], ["id" => $categoryIds, "deleted" => 0], function ($c) use (&$categoryMap) {
                    $categoryMap[$c['id']] = $c['name'];
                });
            }
            $vars['movement'] = $exportMovement;
            $vars['batch'] = $batch;
            $vars['from_stage'] = $fromStage;
            $vars['to_stage'] = $toStage;
            $vars['user'] = $user;
            $vars['items'] = $items;
            $vars['category_map'] = $categoryMap;
            echo $app->render($template . '/qaqc/stage-import-receive-modal.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);
            $userId = $app->getSession("accounts")['id'] ?? 0;
            $now = date('Y-m-d H:i:s');
            $toId = $exportMovement['stage_related'];
            $fromId = $exportMovement['stage'];
            $bId = $exportMovement['batch'];

            $ok = false;
            try {
                $app->action(function () use ($app, $exportMovement, $items, $toId, $fromId, $bId, $userId, $now, &$ok) {
                    // Tạo phiếu nhập kho vào kho đích
                    $app->insert("production_stage_movements", [
                        "batch" => $bId,
                        "stage" => $toId,
                        "type" => "import",
                        "stage_related" => $fromId,
                        "receive_status" => 2, // 2 = Đã hoàn tất nhập
                        "notes" => "Nhập hàng từ phiếu xuất #XK-QAQC-" . $exportMovement['id'] . ($exportMovement['notes'] ? " (" . $exportMovement['notes'] . ")" : ""),
                        "date" => $now,
                        "user" => $userId,
                        "deleted" => 0,
                    ]);
                    $importMovementId = $app->id();
                    if (!$importMovementId) return false;

                    foreach ($items as $it) {
                        $app->insert("production_stage_movement_items", [
                            "movement" => $importMovementId,
                            "pearl" => $it['pearl'],
                            "weight_kg" => $it['weight_kg'],
                            "weight_gr" => $it['weight_gr'] ?? 0,
                            "amount" => $it['amount'],
                            "weight_kg_hao_hut" => 0,
                            "weight_gr_hao_hut" => 0,
                            "amount_hao_hut" => 0,
                            "category" => intval($it['category'] ?? 0),
                            "deleted" => 0,
                        ]);
                    }

                    // Đánh dấu phiếu xuất đã được nhập
                    $app->update("production_stage_movements", [
                        "receive_status" => 2
                    ], ["id" => $exportMovement['id']]);

                    // Cập nhật current_stage cho lô sản xuất
                    $app->update("production_batches", [
                        "current_stage" => $toId
                    ], ["id" => $bId]);

                    $ok = true;
                    return true;
                });
            } catch (\Exception $e) {
                $ok = false;
            }

            if ($ok) {
                $jatbi->logs('production_stage_movements', 'receive_transfer', [
                    'export_id' => $exportMovement['id'],
                    'batch' => $bId,
                    'to_stage' => $toId
                ]);
                echo json_encode([
                    'status' => 'success',
                    'content' => $jatbi->lang('Nhập hàng thành công vào') . ' ' . ($toStage['name'] ?? ''),
                    'url' => $jatbi->url('/qaqc/stage/' . ($toStage['code'] ?? 'KX'))
                ]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Có lỗi xảy ra, vui lòng thử lại')]);
            }
        }
    })->setPermissions(['stage_transfer', 'stage_history', 'stage_import_move', 'stage_vs', 'stage_kx', 'stage_lt']);

    // ============================================================
    // 1h. LỊCH SỬ CHUYỂN KHO QAQC (/stage-history/{code})
    // ============================================================
    $app->router('/stage-history/{code}', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template, $stageWarehouseConfig, $getStageByCode) {
        $code = strtoupper($app->xss($vars['code'] ?? 'VS'));
        if (!in_array($code, ['VS', 'KX', 'LT', 'ALL'])) {
            $code = 'VS';
        }

        $title_map = [
            'VS' => $jatbi->lang('Lịch sử chuyển kho: Kho Vệ Sinh → Kho Khoan Xiên'),
            'KX' => $jatbi->lang('Lịch sử chuyển kho: Kho Khoan Xiên → Kho Lưu Trữ'),
            'LT' => $jatbi->lang('Lịch sử chuyển kho: Kho Lưu Trữ → Kho Chế Tác'),
            'ALL' => $jatbi->lang('Tất cả lịch sử chuyển kho QAQC'),
        ];

        if ($app->method() === 'GET') {
            $vars['title'] = $title_map[$code] ?? $jatbi->lang('Lịch sử chuyển kho');
            $vars['stage_code'] = $code;
            $vars['date_from'] = date('01/m/Y');
            $vars['date_to'] = date('d/m/Y');

            $empty_option = [['value' => '', 'text' => $jatbi->lang('Tất cả')]];
            $accounts_db = $app->select("accounts", ["id(value)", "name(text)"], ["deleted" => 0, "status" => 'A']) ?? [];
            $vars['accounts'] = array_merge($empty_option, $accounts_db);

            echo $app->render($template . '/qaqc/stage-history.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);

            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $searchValue = isset($_POST['search']['value']) ? trim($_POST['search']['value']) : '';
            $filter_user = isset($_POST['user']) ? intval($_POST['user']) : 0;
            $filter_date = isset($_POST['date']) ? trim($app->xss($_POST['date'])) : '';

            $where = [
                "AND" => [
                    "production_stage_movements.deleted" => 0,
                    "production_stage_movements.type" => "export",
                ]
            ];

            if ($code !== 'ALL') {
                $stg = $getStageByCode($code);
                if ($stg) {
                    $where["AND"]["production_stage_movements.stage"] = $stg['id'];
                }
            }

            if (!empty($filter_user)) {
                $where["AND"]["production_stage_movements.user"] = $filter_user;
            }

            if (!empty($filter_date)) {
                $dates = explode(' - ', $filter_date);
                if (count($dates) === 2) {
                    $dFrom = date('Y-m-d 00:00:00', strtotime(str_replace('/', '-', trim($dates[0]))));
                    $dTo = date('Y-m-d 23:59:59', strtotime(str_replace('/', '-', trim($dates[1]))));
                    $where["AND"]["production_stage_movements.date[<>]"] = [$dFrom, $dTo];
                }
            }

            $joins = [
                "[><]production_batches" => ["batch" => "id"],
                "[><]warehouse_stages" => ["stage" => "id"],
                "[>]warehouse_stages(related_stage)" => ["stage_related" => "id"],
                "[>]accounts" => ["user" => "id"],
            ];

            if (!empty($searchValue)) {
                $where["AND"]["OR"] = [
                    "production_batches.code[~]" => $searchValue,
                    "production_stage_movements.notes[~]" => $searchValue,
                    "production_stage_movements.id[~]" => $searchValue,
                ];
            }

            $count = $app->count("production_stage_movements", $joins, "production_stage_movements.id", $where);

            $where["ORDER"] = ["production_stage_movements.date" => "DESC", "production_stage_movements.id" => "DESC"];
            $where["LIMIT"] = [$start, $length];

            $columns = [
                "production_stage_movements.id",
                "production_stage_movements.batch",
                "production_stage_movements.stage",
                "production_stage_movements.stage_related",
                "production_stage_movements.notes",
                "production_stage_movements.date",
                "production_batches.code(batch_code)",
                "warehouse_stages.name(from_stage_name)",
                "related_stage.name(to_stage_name)",
                "accounts.name(user_name)",
            ];

            $datas = $app->select("production_stage_movements", $joins, $columns, $where) ?? [];

            $movementIds = array_column($datas, 'id');
            $itemsMap = [];
            if (!empty($movementIds)) {
                $app->select("production_stage_movement_items", [
                    "[><]pearl" => ["pearl" => "id"],
                ], [
                    "production_stage_movement_items.movement",
                    "production_stage_movement_items.weight_kg",
                    "production_stage_movement_items.weight_gr",
                    "production_stage_movement_items.amount",
                    "production_stage_movement_items.weight_kg_hao_hut",
                    "production_stage_movement_items.weight_gr_hao_hut",
                    "production_stage_movement_items.amount_hao_hut",
                    "pearl.name(pearl_name)",
                    "pearl.unit_mode",
                ], [
                    "production_stage_movement_items.movement" => $movementIds,
                    "production_stage_movement_items.deleted" => 0,
                ], function ($it) use (&$itemsMap) {
                    $itemsMap[$it['movement']][] = $it;
                });
            }

            $resultData = [];
            foreach ($datas as $r) {
                $mId = $r['id'];
                $mItems = $itemsMap[$mId] ?? [];

                $summaryParts = [];
                $lossKg = 0;
                $lossGr = 0;
                $lossVien = 0;
                foreach ($mItems as $mi) {
                    $isMax = (($mi['unit_mode'] ?? '') === 'kg_and_vien');
                    $pText = $mi['pearl_name'] . ' (';
                    $sub = [];
                    if (floatval($mi['weight_gr'] ?? 0) > 0) $sub[] = number_format($mi['weight_gr'], 2) . ' gr';
                    if (floatval($mi['weight_kg']) > 0) $sub[] = number_format($mi['weight_kg'], 2) . ' kg';
                    if (floatval($mi['amount']) > 0) $sub[] = number_format($mi['amount']) . ' v';
                    $pText .= implode(' · ', $sub) . ')';
                    $summaryParts[] = $pText;

                    $lossKg += floatval($mi['weight_kg_hao_hut'] ?? 0);
                    $lossGr += floatval($mi['weight_gr_hao_hut'] ?? 0);
                    $lossVien += floatval($mi['amount_hao_hut'] ?? 0);
                }
                $pearlSummary = !empty($summaryParts) ? implode(', ', $summaryParts) : '-';

                $lossParts = [];
                if ($lossKg > 0) $lossParts[] = number_format($lossKg, 2) . ' kg';
                if ($lossGr > 0) $lossParts[] = number_format($lossGr, 2) . ' gr';
                if ($lossVien > 0) $lossParts[] = number_format($lossVien) . ' v';
                $lossSummary = !empty($lossParts) ? ('<span class="text-danger fw-bold">' . implode(' · ', $lossParts) . '</span>') : '<span class="text-secondary small">-</span>';

                $codeLink = '<a href="#!" data-action="modal" data-url="/qaqc/stage-movement-views/' . $mId . '" class="fw-bold text-primary">#XK-QAQC-' . $mId . '</a>';
                $actionBtn = '<a href="#!" data-action="modal" class="btn btn-eclo-light btn-sm border-0 py-1 px-2 rounded-3" data-url="/qaqc/stage-movement-views/' . $mId . '" title="' . $jatbi->lang("Xem chi tiết phiếu") . '"><i class="ti ti-eye"></i></a>';

                $resultData[] = [
                    "code" => $codeLink,
                    "batch_code" => '<span class="badge bg-light text-body border fw-bold px-2 py-1">#' . htmlspecialchars($r['batch_code']) . '</span>',
                    "from_stage" => '<span class="badge bg-secondary bg-opacity-10 text-secondary border fw-semibold">' . htmlspecialchars($r['from_stage_name'] ?? '-') . '</span>',
                    "to_stage" => '<span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 fw-semibold">' . htmlspecialchars($r['to_stage_name'] ?? '-') . '</span>',
                    "pearl_summary" => $pearlSummary,
                    "loss_summary" => $lossSummary,
                    "date" => date('d/m/Y H:i', strtotime($r['date'])),
                    "user" => htmlspecialchars($r['user_name'] ?? '-'),
                    "action" => $actionBtn,
                ];
            }

            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => $count,
                "recordsFiltered" => $count,
                "data" => $resultData
            ]);
        }
    })->setPermissions(['stage_transfer', 'stage_history', 'stage_vs', 'stage_kx', 'stage_lt']);

    // ============================================================
    // 1i. CHI TIẾT PHIẾU CHUYỂN KHO QAQC MODAL (/stage-movement-views/{id})
    // ============================================================
    $app->router('/stage-movement-views/{id}', 'GET', function ($vars) use ($app, $jatbi, $template) {
        $id = intval($vars['id'] ?? 0);
        $movement = $app->get("production_stage_movements", "*", ["id" => $id, "deleted" => 0]);
        if (!$movement) {
            echo '<div class="modal fade modal-load"><div class="modal-dialog"><div class="modal-content p-4 text-center text-danger">' . $jatbi->lang("Không tìm thấy phiếu chuyển kho") . '</div></div></div>';
            return;
        }

        $batch = $app->get("production_batches", ["id", "code", "notes"], ["id" => $movement['batch']]);
        $fromStage = $app->get("warehouse_stages", ["id", "code", "name"], ["id" => $movement['stage']]);
        $toStage = $app->get("warehouse_stages", ["id", "code", "name"], ["id" => $movement['stage_related']]);
        $user = $app->get("accounts", ["id", "name"], ["id" => $movement['user']]);

        $items = $app->select("production_stage_movement_items", [
            "[><]pearl" => ["pearl" => "id"],
        ], [
            "production_stage_movement_items.id",
            "production_stage_movement_items.weight_kg",
            "production_stage_movement_items.weight_gr",
            "production_stage_movement_items.amount",
            "production_stage_movement_items.weight_kg_hao_hut",
            "production_stage_movement_items.weight_gr_hao_hut",
            "production_stage_movement_items.amount_hao_hut",
            "production_stage_movement_items.category",
            "pearl.name(pearl_name)",
            "pearl.unit_mode",
        ], [
            "production_stage_movement_items.movement" => $id,
            "production_stage_movement_items.deleted" => 0,
        ]) ?? [];

        $hasLoss = false;
        foreach ($items as $it) {
            if (floatval($it['weight_kg_hao_hut'] ?? 0) > 0 || floatval($it['weight_gr_hao_hut'] ?? 0) > 0 || floatval($it['amount_hao_hut'] ?? 0) > 0) {
                $hasLoss = true;
                break;
            }
        }

        $categoryIds = array_values(array_unique(array_filter(array_column($items, 'category'))));
        $categoryMap = [];
        if (!empty($categoryIds)) {
            $app->select("pearl_categories", ["id", "name"], ["id" => $categoryIds, "deleted" => 0], function ($c) use (&$categoryMap) {
                $categoryMap[$c['id']] = $c['name'];
            });
        }

        $vars['movement'] = $movement;
        $vars['batch'] = $batch;
        $vars['from_stage'] = $fromStage;
        $vars['to_stage'] = $toStage;
        $vars['user'] = $user;
        $vars['items'] = $items;
        $vars['has_loss'] = $hasLoss;
        $vars['category_map'] = $categoryMap;

        echo $app->render($template . '/qaqc/stage-movement-views.html', $vars, $jatbi->ajax());
    })->setPermissions(['stage_transfer', 'stage_history', 'stage_vs', 'stage_kx', 'stage_lt']);


    // ============================================================
    // 1h. CHỌN LÔ THẨM ĐỊNH NGỌC TẠI KHO LƯU TRỮ (/stage-appraisal-select)
    // ============================================================

    $app->router('/stage-appraisal-select', ['GET'], function ($vars) use ($app, $jatbi, $setting, $template, $getStageByCode, $getBatchStockAtStage) {
        $ltStage = $getStageByCode('LT');
        if (!$ltStage) {
            echo $app->render($setting['template'] . '/pages/error.html', ['content' => $jatbi->lang('Chưa cấu hình kho Lưu Trữ trong warehouse_stages')], $jatbi->ajax());
            return;
        }

        // Lấy danh sách các lô có phát sinh tại LT
        $movementBatchIds = $app->select("production_stage_movements", "batch", [
            "stage" => $ltStage['id'],
            "deleted" => 0,
            "GROUP" => "batch"
        ]);

        $batches = [];
        if (!empty($movementBatchIds)) {
            $bList = $app->select("production_batches", "*", [
                "id" => $movementBatchIds,
                "status" => 'A',
                "deleted" => 0,
                "ORDER" => ["id" => "DESC"]
            ]);

            foreach ($bList as $b) {
                $stock = $getBatchStockAtStage($b['id'], $ltStage['id']);
                if (!empty($stock)) {
                    $lines = [];
                    $totalKg = 0;
                    $totalVien = 0;
                    foreach ($stock as $s) {
                        $parts = [];
                        if ($s['weight_kg'] > 0) {
                            $parts[] = number_format($s['weight_kg'], 2) . ' kg';
                            $totalKg += floatval($s['weight_kg']);
                        }
                        if ($s['amount'] > 0) {
                            $parts[] = number_format($s['amount']) . ' ' . $jatbi->lang("viên");
                            $totalVien += floatval($s['amount']);
                        }
                        $badge = (($s['unit_mode'] ?? '') === 'kg_and_vien')
                            ? '<span class="badge bg-info ms-1">' . $jatbi->lang("Kg+viên") . '</span>'
                            : '<span class="badge bg-warning text-dark ms-1">' . $jatbi->lang("Kg") . '</span>';
                        $lines[] = '<div><span class="fw-semibold">' . htmlspecialchars($s['pearl_name']) . ':</span> ' . implode(' · ', $parts) . $badge . '</div>';
                    }
                    $b['pearl_summary'] = implode('', $lines);
                    $b['total_kg'] = $totalKg;
                    $b['total_vien'] = $totalVien;
                    $batches[] = $b;
                }
            }
        }

        $vars['batches'] = $batches;
        echo $app->render($template . '/qaqc/stage-appraisal-modal.html', $vars, $jatbi->ajax());
    })->setPermissions(['stage_appraisal']);


    // ============================================================
    // 2. CÁCH TÍNH ĐƠN VỊ THEO LOẠI NGỌC (pearl.unit_mode)
    //    Màn hình riêng, tự chứa trong qaqc — KHÔNG đụng tới
    //    /warehouses/pearl-add, /warehouses/pearl-edit hiện có.
    //    Chỉ đọc bảng pearl và chỉ update đúng cột unit_mode.
    // ============================================================

    $app->router('/pearl-unit', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template) {
        if ($app->method() === 'GET') {
            $vars['title'] = $jatbi->lang("Cách tính đơn vị theo loại ngọc");
            echo $app->render($template . '/qaqc/pearl-unit.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : ($setting['site_page'] ?? 10);
            $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
            $orderName = isset($_POST['order'][0]['name']) ? $_POST['order'][0]['name'] : 'id';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            $where = [
                "AND" => [
                    "deleted" => 0,
                    "status" => 'A',
                ],
                "LIMIT" => [$start, $length],
                "ORDER" => [$orderName => strtoupper($orderDir)],
            ];

            if ($searchValue != '') {
                $where['AND']['OR'] = [
                    'name[~]' => $searchValue,
                    'code[~]' => $searchValue,
                ];
            }
            if (isset($_POST['unit_mode']) && $_POST['unit_mode'] !== '') {
                $where['AND']['unit_mode'] = $app->xss($_POST['unit_mode']);
            }

            $count = $app->count("pearl", ["AND" => $where['AND']]);
            $datas = [];

            $app->select("pearl", ["id", "code", "name", "unit_mode"], $where, function ($data) use (&$datas, $jatbi, $app) {
                $datas[] = [
                    "checkbox" => $app->component("box", ["data" => $data['id'] ?? '']),
                    "code" => $data['code'] ?? '',
                    "name" => $data['name'] ?? '',
                    "unit_mode" => (($data['unit_mode'] ?? 'kg_to_vien') === 'kg_and_vien')
                        ? '<span class="badge bg-info">' . $jatbi->lang("Kg + viên (Maxima)") . '</span>'
                        : '<span class="badge bg-warning">' . $jatbi->lang("Kg → viên (Akoya)") . '</span>',
                    "action" => $app->component("action", [
                        "button" => [
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Sửa"),
                                'permission' => ['pearl_unit.edit'],
                                'action' => ['data-url' => '/qaqc/pearl-unit-edit/' . $data['id'], 'data-action' => 'modal']
                            ],
                        ]
                    ]),
                ];
            });

            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => $count,
                "recordsFiltered" => $count,
                "data" => $datas
            ]);
        }
    })->setPermissions(['pearl_unit']);

    $app->router("/pearl-unit-edit/{id}", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template) {
        $vars['title'] = $jatbi->lang("Sửa cách tính đơn vị");

        if ($app->method() === 'GET') {
            $data = $app->select("pearl", ["id", "code", "name", "unit_mode"], [
                "AND" => ["id" => $vars['id'], "deleted" => 0],
                "LIMIT" => 1
            ]);

            if (empty($data)) {
                echo $app->render($setting['template'] . '/pages/error.html', $vars, $jatbi->ajax());
                return;
            }

            $vars['data'] = $data[0];
            $vars['unit_mode_options'] = [
                ['value' => 'kg_to_vien', 'text' => $jatbi->lang('Kg → viên (ngọc nhỏ, ví dụ Akoya)')],
                ['value' => 'kg_and_vien', 'text' => $jatbi->lang('Kg + viên song song (ngọc lớn, ví dụ Maxima)')],
            ];
            echo $app->render($template . '/qaqc/pearl-unit-post.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $data = $app->select("pearl", ["id"], [
                "AND" => ["id" => $vars['id'], "deleted" => 0],
                "LIMIT" => 1
            ]);

            if (empty($data)) {
                echo json_encode(["status" => "error", "content" => $jatbi->lang("Không tìm thấy dữ liệu")]);
                return;
            }

            $unit_mode = $app->xss($_POST['unit_mode'] ?? '');
            if (!in_array($unit_mode, ['kg_to_vien', 'kg_and_vien'])) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Vui lòng chọn cách tính đơn vị hợp lệ')]);
                return;
            }

            $update = ["unit_mode" => $unit_mode];
            $app->update("pearl", $update, ["id" => $vars['id']]);
            $jatbi->logs('pearl', 'edit_unit_mode', $update);
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Cập nhật thành công'), 'url' => $_SERVER['HTTP_REFERER']]);
        }
    })->setPermissions(['pearl_unit.edit']);

    // ============================================================
    // 3. DANH MỤC SẢN PHẨM NGỌC (pearl_categories)
    //    Cấu hình các danh mục gắn cho từng dòng ngọc khi chuyển kho
    //    (VD: Vòng tay, Chuỗi, 1 lỗ, Bông nhẫn mặt).
    //    Trang: /qaqc/pearl-category
    // ============================================================
    $app->router('/pearl-category', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template) {
        if ($app->method() === 'GET') {
            $vars['title'] = $jatbi->lang("Danh mục sản phẩm ngọc");
            echo $app->render($template . '/qaqc/pearl-category.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : ($setting['site_page'] ?? 10);
            $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
            $orderName = isset($_POST['order'][0]['name']) ? $_POST['order'][0]['name'] : 'id';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            $where = [
                "AND" => [
                    "deleted" => 0,
                ],
                "LIMIT" => [$start, $length],
                "ORDER" => [$orderName => strtoupper($orderDir)],
            ];

            if ($searchValue != '') {
                $where['AND']['OR'] = [
                    'name[~]' => $searchValue,
                    'code[~]' => $searchValue,
                ];
            }

            $count = $app->count("pearl_categories", ["AND" => $where['AND']]);
            $datas = [];

            $app->select("pearl_categories", ["id", "code", "name", "notes", "status"], $where, function ($data) use (&$datas, $jatbi, $app) {
                $datas[] = [
                    "checkbox" => $app->component("box", ["data" => $data['id'] ?? '']),
                    "code" => $data['code'] ?? '',
                    "name" => $data['name'] ?? '',
                    "notes" => $data['notes'] ?? '',
                    "status" => ($data['status'] ?? 'A') === 'A'
                        ? '<span class="badge bg-success bg-opacity-10 text-success">' . $jatbi->lang("Hoạt động") . '</span>'
                        : '<span class="badge bg-danger bg-opacity-10 text-danger">' . $jatbi->lang("Ngưng") . '</span>',
                    "action" => $app->component("action", [
                        "button" => [
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Sửa"),
                                'permission' => ['pearl_category.edit'],
                                'action' => ['data-url' => '/qaqc/pearl-category-edit/' . $data['id'], 'data-action' => 'modal']
                            ],
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Xóa"),
                                'permission' => ['pearl_category.edit'],
                                'action' => ['data-url' => '/qaqc/pearl-category-deleted/' . $data['id'], 'data-action' => 'click', 'data-alert' => 'true']
                            ],
                        ]
                    ]),
                ];
            });

            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => $count,
                "recordsFiltered" => $count,
                "data" => $datas
            ]);
        }
    })->setPermissions(['pearl_category']);

    // Thêm danh mục
    $app->router('/pearl-category-post', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template) {
        $vars['title'] = $jatbi->lang("Thêm danh mục sản phẩm ngọc");

        if ($app->method() === 'GET') {
            echo $app->render($template . '/qaqc/pearl-category-post.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $name = trim($app->xss($_POST['name'] ?? ''));
            $code = trim($app->xss($_POST['code'] ?? ''));
            $notes = trim($app->xss($_POST['notes'] ?? ''));
            $status = ($_POST['status'] ?? '') === 'D' ? 'D' : 'A';

            if ($code === '') {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Vui lòng nhập mã danh mục')]);
                return;
            }
            if ($name === '') {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Vui lòng nhập tên danh mục')]);
                return;
            }

            $check = $app->count("pearl_categories", ["AND" => ["code" => $code, "deleted" => 0]]);
            if ($check > 0) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Mã danh mục đã tồn tại')]);
                return;
            }

            $userId = $app->getSession("accounts")['id'] ?? 0;
            $id = $app->insert("pearl_categories", [
                "code" => $code,
                "name" => $name,
                "notes" => $notes,
                "status" => $status,
                "deleted" => 0,
            ]);
            $jatbi->logs('pearl_categories', 'create_category', ['id' => $id, 'name' => $name]);
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Thêm danh mục thành công'), 'url' => $_SERVER['HTTP_REFERER']]);
        }
    })->setPermissions(['pearl_category.edit']);

    // Sửa danh mục
    $app->router('/pearl-category-edit/{id}', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template) {
        $vars['title'] = $jatbi->lang("Sửa danh mục sản phẩm ngọc");

        if ($app->method() === 'GET') {
            $data = $app->select("pearl_categories", ["id", "code", "name", "notes", "status"], [
                "AND" => ["id" => $vars['id'], "deleted" => 0],
                "LIMIT" => 1
            ]);

            if (empty($data)) {
                echo $app->render($setting['template'] . '/pages/error.html', $vars, $jatbi->ajax());
                return;
            }

            $vars['data'] = $data[0];
            echo $app->render($template . '/qaqc/pearl-category-post.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $data = $app->select("pearl_categories", ["id"], [
                "AND" => ["id" => $vars['id'], "deleted" => 0],
                "LIMIT" => 1
            ]);

            if (empty($data)) {
                echo json_encode(["status" => "error", "content" => $jatbi->lang("Không tìm thấy dữ liệu")]);
                return;
            }

            $name = trim($app->xss($_POST['name'] ?? ''));
            $code = trim($app->xss($_POST['code'] ?? ''));
            $notes = trim($app->xss($_POST['notes'] ?? ''));
            $status = ($_POST['status'] ?? '') === 'D' ? 'D' : 'A';

            if ($code === '') {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Vui lòng nhập mã danh mục')]);
                return;
            }
            if ($name === '') {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Vui lòng nhập tên danh mục')]);
                return;
            }

            $duplicate = $app->count("pearl_categories", [
                "AND" => ["code" => $code, "deleted" => 0, "id[!]" => $vars['id']]
            ]);
            if ($duplicate > 0) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Mã danh mục đã tồn tại')]);
                return;
            }

            $update = ["code" => $code, "name" => $name, "notes" => $notes, "status" => $status];
            $app->update("pearl_categories", $update, ["id" => $vars['id']]);
            $jatbi->logs('pearl_categories', 'edit_category', $update);
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Cập nhật thành công'), 'url' => $_SERVER['HTTP_REFERER']]);
        }
    })->setPermissions(['pearl_category.edit']);

    // Xóa danh mục (soft delete)
    $app->router('/pearl-category-deleted/{id}', ['POST'], function ($vars) use ($app, $jatbi) {
        $app->header(['Content-Type' => 'application/json']);
        $data = $app->select("pearl_categories", ["id"], [
            "AND" => ["id" => $vars['id'], "deleted" => 0],
            "LIMIT" => 1
        ]);
        if (empty($data)) {
            echo json_encode(["status" => "error", "content" => $jatbi->lang("Không tìm thấy dữ liệu")]);
            return;
        }
        $app->update("pearl_categories", ["deleted" => 1], ["id" => $vars['id']]);
        $jatbi->logs('pearl_categories', 'delete_category', ['id' => $vars['id']]);
        echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Đã xóa danh mục'), 'url' => $_SERVER['HTTP_REFERER']]);
    })->setPermissions(['pearl_category.edit']);



// ============================================================
    // API LẤY DANH SÁCH ĐAI TỪ KHO CHẾ TÁC (ingredient type = 1)
    // ============================================================
    $app->router('/api/belts', ['GET', 'POST'], function ($vars) use ($app, $jatbi) {
        $app->header(['Content-Type' => 'application/json']);
        $groupCrafting = intval($_GET['group_crafting'] ?? $_POST['group_crafting'] ?? 0);
        $search = trim($app->xss($_GET['q'] ?? $_POST['q'] ?? ''));

        $where = [
            "AND" => [
                "ingredient.deleted" => 0,
                "ingredient.type" => 1, // Đai
            ],
            "LIMIT" => 50,
            "ORDER" => ["ingredient.id" => "DESC"]
        ];

        if ($search !== '') {
            $where['AND']['OR'] = [
                "ingredient.code[~]" => $search,
                "ingredient.name_ingredient[~]" => $search,
            ];
        }

        if ($groupCrafting == 1) {
            $where['AND']['ingredient.crafting[>]'] = 0;
        } elseif ($groupCrafting == 2) {
            $where['AND']['ingredient.craftingsilver[>]'] = 0;
        } elseif ($groupCrafting == 3) {
            $where['AND']['ingredient.craftingchain[>]'] = 0;
        }

        $items = $app->select("ingredient", [
            "id", "code", "name_ingredient", "crafting", "craftingsilver", "craftingchain", "price", "cost", "group_crafting", "units"
        ], $where);

        $results = [];
        foreach ($items as $it) {
            $stock = 0;
            if ($groupCrafting == 1) $stock = floatval($it['crafting']);
            elseif ($groupCrafting == 2) $stock = floatval($it['craftingsilver']);
            elseif ($groupCrafting == 3) $stock = floatval($it['craftingchain']);
            else $stock = floatval($it['crafting'] ?: ($it['craftingsilver'] ?: $it['craftingchain']));

            $nameDisplay = $it['code'] . ($it['name_ingredient'] ? ' - ' . $it['name_ingredient'] : '') . ' (Tồn: ' . $stock . ')';
            $results[] = [
                'id' => $it['id'],
                'code' => $it['code'],
                'name' => $it['name_ingredient'] ?: $it['code'],
                'text' => $nameDisplay,
                'stock' => $stock,
                'price' => floatval($it['price']),
                'cost' => floatval($it['cost']),
                'units' => $it['units'],
            ];
        }

        echo json_encode(['status' => 'success', 'data' => $results]);
    });


    // ============================================================
    // 3. GIAI ĐOẠN 4 — KHO CHẾ TÁC (CT / SX) - KHO CHUNG
    //    Chế tác phối đai, Akoya chuyển dứt điểm từ KG sang VIÊN.
    //    Tạo bản ghi chuẩn vào bảng crafting & crafting_details.
    // ============================================================

    $app->router('/crafting', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template, $getStageByCode, $getBatchStockAtStage, $batchItemsWithPearl) {
        $ltStage = $getStageByCode('LT');
        $ctStage = $getStageByCode('CT');
        $ltStageId = $ltStage['id'] ?? 3;
        $ctStageId = $ctStage['id'] ?? 4;

        if ($app->method() === 'GET') {
            $vars['title'] = $jatbi->lang("Kho Chế tác (SX) - Kho chung xưởng");
            $vars['pearl_filter_options'] = array_merge(
                [['value' => '', 'text' => $jatbi->lang('Tất cả')]],
                $app->select('pearl', ['id(value)', 'name(text)'], ['deleted' => 0, 'status' => 'A'])
            );
            echo $app->render($template . '/qaqc/crafting.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : ($setting['site_page'] ?? 10);
            $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';

            $where = [
                "AND" => [
                    "current_stage" => [$ltStageId, $ctStageId],
                    "status" => 'A',
                    "deleted" => 0,
                ],
                "LIMIT" => [$start, $length],
                "ORDER" => ["id" => "DESC"],
            ];

            if ($searchValue !== '') {
                $where['AND']['code[~]'] = $searchValue;
            }

            if (isset($_POST['pearl']) && $_POST['pearl'] !== '') {
                $pearlFilter = $app->xss($_POST['pearl']);
                $batchIdsForPearl = [];
                $app->select("production_batch_items", ["batch"], [
                    "pearl" => $pearlFilter,
                    "deleted" => 0,
                ], function ($row) use (&$batchIdsForPearl) {
                    $batchIdsForPearl[] = $row['batch'];
                });
                $where['AND']['id'] = !empty($batchIdsForPearl) ? array_values(array_unique($batchIdsForPearl)) : [0];
            }

            $count = $app->count("production_batches", ["AND" => $where['AND']]);
            $datas = [];

            $app->select("production_batches", "*", $where, function ($b) use (&$datas, $app, $jatbi, $ltStageId, $ctStageId, $getBatchStockAtStage) {
                $stageId = $b['current_stage'];
                $stock = $getBatchStockAtStage($b['id'], $stageId);
                $lines = [];
                $totalKg = 0;
                $totalAmount = 0;

                foreach ($stock as $s) {
                    $parts = [];
                    if ($s['weight_kg'] > 0) {
                        $parts[] = number_format($s['weight_kg'], 2) . ' kg';
                        $totalKg += floatval($s['weight_kg']);
                    }
                    if ($s['amount'] > 0) {
                        $parts[] = number_format($s['amount']) . ' ' . $jatbi->lang("viên");
                        $totalAmount += intval($s['amount']);
                    }
                    $badgeUnit = ($s['unit_mode'] === 'kg_and_vien')
                        ? '<span class="badge bg-info ms-1">' . $jatbi->lang("Kg+viên") . '</span>'
                        : '<span class="badge bg-warning ms-1">' . $jatbi->lang("Kg→viên") . '</span>';
                    $lines[] = '<div class="small text-nowrap"><span class="fw-semibold">'
                        . htmlspecialchars($s['pearl_name']) . ':</span> ' . implode(' + ', $parts) . $badgeUnit . '</div>';
                }

                $stageName = ($stageId == $ltStageId) ? '<span class="badge bg-secondary">' . $jatbi->lang("Tại Kho Lưu Trữ (sẵn sàng)") . '</span>' : '<span class="badge bg-primary">' . $jatbi->lang("Đang Chế tác") . '</span>';

                $craftAction = ($stageId == $ltStageId)
                    ? '<a href="' . $jatbi->url('/qaqc/appraisal/' . $b['id']) . '" class="btn btn-primary btn-sm rounded-pill px-3 pjax-load fw-semibold"><i class="ti ti-diamond me-1"></i> ' . $jatbi->lang("Thẩm định ngọc") . '</a>'
                    : '<a href="' . $jatbi->url('/qaqc/crafting-process/' . $b['id']) . '" class="btn btn-primary btn-sm rounded-pill px-3 pjax-load fw-semibold"><i class="ti ti-tools me-1"></i> ' . $jatbi->lang("Vào Chế tác") . '</a>';

                $datas[] = [
                    "code" => '<span class="fw-bold">' . htmlspecialchars($b['code']) . '</span><br>' . $stageName,
                    "pearl_name" => !empty($lines) ? implode('', $lines) : '<span class="text-secondary">-</span>',
                    "weight_kg" => $totalKg > 0 ? number_format($totalKg, 2) . ' kg' : '-',
                    "amount" => $totalAmount > 0 ? number_format($totalAmount) . ' ' . $jatbi->lang("viên") : '<span class="text-secondary">-</span>',
                    "date" => date('d/m/Y H:i', strtotime($b['date'])),
                    "action" => $craftAction,
                ];
            });

            echo json_encode(["draw" => $draw, "recordsTotal" => $count, "recordsFiltered" => $count, "data" => $datas]);
        }
    })->setPermissions(['crafting']);

    $app->router('/crafting-process/{id}', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template, $getStageByCode, $getBatchStockAtStage) {
        $vars['title'] = $jatbi->lang("Nghiệm thu Chế tác & Lên Thành Phẩm QAQC");

        $batch = $app->get("production_batches", "*", ["id" => $vars['id'], "deleted" => 0]);
        if (!$batch) {
            echo $app->render($setting['template'] . '/pages/error.html', ['content' => $jatbi->lang('Không tìm thấy lô sản xuất')], $jatbi->ajax());
            return;
        }

        $ltStage = $getStageByCode('LT');
        $ctStage = $getStageByCode('CT');
        $tpStage = $getStageByCode('TP');
        $ltStageId = $ltStage['id'] ?? 3;
        $ctStageId = $ctStage['id'] ?? 4;
        $tpStageId = $tpStage['id'] ?? 5;

        $currentStageId = $batch['current_stage'];
        if ($currentStageId != $ctStageId) {
            echo $app->render($setting['template'] . '/pages/error.html', ['content' => $jatbi->lang('Lô này hiện không ở Kho Chế tác (CT). Vui lòng Thẩm định ngọc tại Kho Lưu Trữ (LT) trước khi vào chế tác.')], $jatbi->ajax());
            return;
        }

        $stock = $getBatchStockAtStage($vars['id'], $currentStageId);
        if (empty($stock)) {
            echo $app->render($setting['template'] . '/pages/error.html', ['content' => $jatbi->lang('Lô này không còn tồn ngọc để vào chế tác')], $jatbi->ajax());
            return;
        }

        if ($app->method() === 'GET') {
            $vars['batch'] = $batch;
            $vars['stock'] = array_values($stock);
            $vars['categorys'] = $app->select("categorys", ["id", "name"], ["deleted" => 0, "status" => "A"]);
            $vars['products_group'] = $app->select("products_group", ["id", "name"], ["deleted" => 0, "status" => "A"]);
            $vars['default_codes'] = $app->select("default_code", ["id", "code", "name"], ["deleted" => 0, "status" => "A"]);
            $vars['units'] = $app->select("units", ["id", "name"], ["deleted" => 0, "status" => "A"]);
            $vars['personnels'] = $app->select("personnels", ["id", "name"], ["deleted" => 0, "status" => "A"]);

            echo $app->render($template . '/qaqc/crafting-process.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $linesRaw = $_POST['lines'] ?? [];
            if (!is_array($linesRaw) || empty($linesRaw)) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Thiếu dữ liệu chi tiết ngọc thành phẩm')]);
                return;
            }

            // Dữ liệu sản phẩm chế tác
            $productCode = trim($app->xss($_POST['product_code'] ?? ''));
            $productName = trim($app->xss($_POST['product_name'] ?? ''));
            $categoryId = intval($_POST['category_id'] ?? 0);
            $groupId = intval($_POST['group_id'] ?? 0);
            $defaultCodeId = intval($_POST['default_code_id'] ?? 0);
            $unitId = intval($_POST['unit_id'] ?? 0);
            $personnelId = intval($_POST['personnel_id'] ?? 0);
            $price = floatval($_POST['price'] ?? 0);
            $cost = floatval($_POST['cost'] ?? 0);
            $notes = $app->xss($_POST['crafting_notes'] ?? '');

            // Thông tin đai
            $groupCrafting = intval($_POST['group_crafting'] ?? 0); // 0: Không dùng, 1: Vàng, 2: Bạc, 3: Chuỗi
            $daiId = intval($_POST['dai_id'] ?? 0);
            $daiAmountPerItem = floatval($_POST['dai_amount'] ?? 1);
            if ($daiAmountPerItem <= 0) $daiAmountPerItem = 1;

            if ($productCode === '' || $productName === '') {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Vui lòng nhập mã và tên sản phẩm thành phẩm')]);
                return;
            }

            // Kiểm tra mã sản phẩm đã có trong bảng crafting chưa
            $existCrafting = $app->count("crafting", "id", [
                "code" => $productCode,
                "deleted" => 0,
            ]);
            if ($existCrafting > 0) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Mã sản phẩm này đã tồn tại trong Kho Chế tác! Vui lòng chọn mã khác.')]);
                return;
            }

            $cleanLines = [];
            $error = '';
            $totalFinishAmount = 0;
            $totalFinishKg = 0;

            foreach ($stock as $pearlId => $s) {
                $row = $linesRaw[$pearlId] ?? [];
                $amountOut = intval($row['amount'] ?? 0);
                $weightKgOut = floatval($row['weight_kg'] ?? 0);

                if ($amountOut <= 0) {
                    $error = $jatbi->lang('Vui lòng nhập số viên thành phẩm đạt QAQC hợp lệ cho ') . ($s['pearl_name'] ?? '');
                    break;
                }

                $cleanLines[] = [
                    'pearl' => $pearlId,
                    'amount_finish' => $amountOut,
                    'weight_kg_finish' => $weightKgOut > 0 ? $weightKgOut : $s['weight_kg'],
                    'weight_kg_in' => $s['weight_kg'],
                    'amount_in' => $s['amount'],
                ];
                $totalFinishAmount += $amountOut;
                $totalFinishKg += ($weightKgOut > 0 ? $weightKgOut : $s['weight_kg']);
            }

            if ($error !== '') {
                echo json_encode(['status' => 'error', 'content' => $error]);
                return;
            }

            // Kiểm tra tồn kho đai nếu có dùng
            $daiInfo = null;
            if ($groupCrafting > 0 && $daiId > 0) {
                $daiInfo = $app->get("ingredient", "*", ["id" => $daiId, "deleted" => 0, "type" => 1]);
                if (!$daiInfo) {
                    echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Không tìm thấy thông tin đai đã chọn')]);
                    return;
                }
                $stockDai = 0;
                if ($groupCrafting == 1) $stockDai = floatval($daiInfo['crafting']);
                elseif ($groupCrafting == 2) $stockDai = floatval($daiInfo['craftingsilver']);
                elseif ($groupCrafting == 3) $stockDai = floatval($daiInfo['craftingchain']);

                $totalDaiNeeded = $daiAmountPerItem * $totalFinishAmount;
                if ($stockDai < $totalDaiNeeded) {
                    echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Số lượng đai tồn kho không đủ (Cần: ') . $totalDaiNeeded . ', Tồn: ' . $stockDai . ')']);
                    return;
                }
            }

            $userId = $app->getSession("accounts")['id'] ?? 0;
            $now = date('Y-m-d H:i:s');
            $batchId = $vars['id'];

            $ok = false;
            try {
                $app->action(function () use ($app, $batchId, $batch, $currentStageId, $ctStageId, $tpStageId, $cleanLines, $userId, $now, $productCode, $productName, $categoryId, $groupId, $defaultCodeId, $unitId, $personnelId, $price, $cost, $notes, $groupCrafting, $daiId, $daiAmountPerItem, $daiInfo, $totalFinishAmount, &$ok) {
                    // 1. Tạo bản ghi trong bảng crafting (Kho Chế Tác chung)
                    $craftingInsert = [
                        "code" => $productCode,
                        "name" => $productName,
                        "content" => "Chế tác từ Lô SX: " . $batch['code'],
                        "notes" => "Lô SX: " . $batch['code'] . ($notes ? " - $notes" : ""),
                        "amount" => $totalFinishAmount,
                        "amount_total" => $totalFinishAmount,
                        "amount_export" => 0,
                        "price" => $price,
                        "cost" => $cost,
                        "total" => (float) $totalFinishAmount * (float) $price,
                        "categorys" => $categoryId,
                        "group" => $groupId,
                        "units" => $unitId,
                        "personnels" => $personnelId,
                        "group_crafting" => $groupCrafting,
                        "default_code" => $defaultCodeId,
                        "status" => 1,
                        "type" => 1,
                        "user" => $userId,
                        "date" => $now,
                        "deleted" => 0,
                    ];
                    $app->insert("crafting", $craftingInsert);
                    $craftingId = $app->id();
                    if (!$craftingId) return false;

                    // 2. Ghi history_crafting
                    $historyCrafting = [
                        "code" => $productCode,
                        "name" => $productName,
                        "notes" => "Lô SX: " . $batch['code'],
                        "amount" => $totalFinishAmount,
                        "price" => $price,
                        "total" => (float) $totalFinishAmount * (float) $price,
                        "user" => $userId,
                        "date" => $now,
                        "personnels" => $personnelId,
                        "group_crafting" => $groupCrafting,
                        "crafting" => $craftingId,
                        "unit" => $unitId,
                        "type" => 1,
                    ];
                    $app->insert("history_crafting", $historyCrafting);
                    $historyId = $app->id();

                    // 3. Tạo chi tiết nguyên liệu trong crafting_details
                    // 3a. Dòng đai (type = 1) nếu có dùng đai
                    if ($groupCrafting > 0 && $daiId > 0 && $daiInfo) {
                        $totalDaiUsed = $daiAmountPerItem * $totalFinishAmount;
                        $app->insert("crafting_details", [
                            "crafting" => $craftingId,
                            "ingredient" => $daiId,
                            "type" => 1,
                            "code" => $daiInfo['code'] ?? '',
                            "group" => $groupId,
                            "categorys" => $categoryId,
                            "units" => $daiInfo['units'] ?? 0,
                            "amount" => $totalDaiUsed,
                            "price" => $daiInfo['price'] ?? 0,
                            "cost" => $daiInfo['cost'] ?? 0,
                            "total" => (float) $totalDaiUsed * (float) ($daiInfo['price'] ?? 0),
                            "user" => $userId,
                            "date" => $now,
                            "deleted" => 0,
                        ]);

                        // Trừ tồn kho đai trong bảng ingredient
                        if ($groupCrafting == 1) {
                            $app->update("ingredient", ["crafting[-]" => $totalDaiUsed], ["id" => $daiId]);
                        } elseif ($groupCrafting == 2) {
                            $app->update("ingredient", ["craftingsilver[-]" => $totalDaiUsed], ["id" => $daiId]);
                        } elseif ($groupCrafting == 3) {
                            $app->update("ingredient", ["craftingchain[-]" => $totalDaiUsed], ["id" => $daiId]);
                        }

                        // Ghi history_crafting_ingredient
                        if ($historyId) {
                            $app->insert("history_crafting_ingredient", [
                                "history_crafting" => $historyId,
                                "crafting" => $craftingId,
                                "ingredient" => $daiId,
                                "code" => $daiInfo['code'] ?? '',
                                "type" => 1,
                                "amount" => $totalDaiUsed,
                                "price" => $daiInfo['price'] ?? 0,
                                "total" => (float) $totalDaiUsed * (float) ($daiInfo['price'] ?? 0),
                                "user" => $userId,
                                "date" => $now,
                            ]);
                        }
                    }

                    // 3b. Dòng ngọc (type = 2) từ lô
                    foreach ($cleanLines as $cl) {
                        $app->insert("crafting_details", [
                            "crafting" => $craftingId,
                            "ingredient" => 0,
                            "type" => 2,
                            "code" => $batch['code'],
                            "group" => $groupId,
                            "categorys" => $categoryId,
                            "pearl" => $cl['pearl'],
                            "amount" => $cl['amount_finish'],
                            "price" => 0,
                            "cost" => 0,
                            "total" => 0,
                            "user" => $userId,
                            "date" => $now,
                            "deleted" => 0,
                        ]);

                        if ($historyId) {
                            $app->insert("history_crafting_ingredient", [
                                "history_crafting" => $historyId,
                                "crafting" => $craftingId,
                                "ingredient" => 0,
                                "code" => $batch['code'],
                                "type" => 2,
                                "amount" => $cl['amount_finish'],
                                "price" => 0,
                                "total" => 0,
                                "user" => $userId,
                                "date" => $now,
                            ]);
                        }
                    }

                    // 4. Ghi movement xuất khỏi Kho hiện tại (LT hoặc CT)
                    $app->insert("production_stage_movements", [
                        "batch" => $batchId, "stage" => $currentStageId, "type" => "export",
                        "stage_related" => $tpStageId, "notes" => "Xuất vào Chế tác SKU: " . $productCode,
                        "date" => $now, "user" => $userId, "deleted" => 0,
                        "crafting" => $craftingId,
                    ]);
                    $exportMovementId = $app->id();
                    if (!$exportMovementId) return false;

                    foreach ($cleanLines as $cl) {
                        $app->insert("production_stage_movement_items", [
                            "movement" => $exportMovementId, "pearl" => $cl['pearl'],
                            "weight_kg" => $cl['weight_kg_in'], "amount" => $cl['amount_in'],
                            "weight_kg_hao_hut" => max(0, $cl['weight_kg_in'] - $cl['weight_kg_finish']),
                            "amount_hao_hut" => max(0, $cl['amount_in'] - $cl['amount_finish']),
                            "deleted" => 0,
                        ]);
                    }

                    // 5. Ghi movement nhập vào Kho Thành Phẩm QAQC (TP) với số viên thành phẩm đạt QAQC
                    $app->insert("production_stage_movements", [
                        "batch" => $batchId, "stage" => $tpStageId, "type" => "import",
                        "stage_related" => $ctStageId, "notes" => "Nhập kho Thành Phẩm đạt QAQC - SKU: " . $productCode,
                        "date" => $now, "user" => $userId, "deleted" => 0,
                        "crafting" => $craftingId,
                    ]);
                    $importMovementId = $app->id();
                    if (!$importMovementId) return false;

                    foreach ($cleanLines as $cl) {
                        $app->insert("production_stage_movement_items", [
                            "movement" => $importMovementId, "pearl" => $cl['pearl'],
                            "weight_kg" => $cl['weight_kg_finish'], "amount" => $cl['amount_finish'],
                            "weight_kg_hao_hut" => 0, "amount_hao_hut" => 0,
                            "deleted" => 0,
                        ]);
                    }

                    // 6. Cập nhật lô: chuyển stage sang TP (Kho Thành phẩm QAQC) và gắn crafting ID
                    $app->update("production_batches", [
                        "current_stage" => $tpStageId,
                        "crafting" => $craftingId,
                    ], ["id" => $batchId]);

                    $ok = true;
                    return true;
                });
            } catch (\Exception $e) {
                $ok = false;
            }

            if ($ok) {
                $jatbi->logs('crafting', 'create_from_batch', ['batch' => $batchId, 'code' => $productCode]);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Chế tác thành công! Thành phẩm đã được ghi nhận vào Kho Chế Tác chung và Kho Thành Phẩm QAQC.'), 'url' => $jatbi->url('/qaqc/finish-stock')]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Có lỗi xảy ra trong quá trình lưu dữ liệu chế tác')]);
            }
        }
    })->setPermissions(['crafting.process']);


    // ============================================================
    // 4. GIAI ĐOẠN 5 — KHO THÀNH PHẨM QAQC (TP) & 3 NHÁNH XUẤT:
    //    5.1: Phân bổ xuống Cửa hàng & Quầy Kho TP bán lẻ (warehouses data=pairing)
    //    5.2: Xuất bán sỉ/trực tiếp
    //    5.3: Xuất trả ngọc nguyên liệu vào Kho NL (ingredient)
    // ============================================================

    $app->router('/finish-stock', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template, $getStageByCode, $getBatchStockAtStage) {
        $tpStage = $getStageByCode('TP');
        $tpStageId = $tpStage['id'] ?? 5;

        if ($app->method() === 'GET') {
            $vars['title'] = $jatbi->lang("Kho Thành phẩm QAQC");
            $vars['pearl_filter_options'] = array_merge(
                [['value' => '', 'text' => $jatbi->lang('Tất cả')]],
                $app->select('pearl', ['id(value)', 'name(text)'], ['deleted' => 0, 'status' => 'A'])
            );
            echo $app->render($template . '/qaqc/finish-stock.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : ($setting['site_page'] ?? 10);
            $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';

            $where = [
                "AND" => [
                    "production_batches.current_stage" => $tpStageId,
                    "production_batches.status" => 'A',
                    "production_batches.deleted" => 0,
                ],
                "LIMIT" => [$start, $length],
                "ORDER" => ["production_batches.id" => "DESC"],
            ];

            if ($searchValue !== '') {
                $where['AND']['production_batches.code[~]'] = $searchValue;
            }

            if (isset($_POST['pearl']) && $_POST['pearl'] !== '') {
                $pearlFilter = $app->xss($_POST['pearl']);
                $batchIdsForPearl = [];
                $app->select("production_batch_items", ["batch"], [
                    "pearl" => $pearlFilter,
                    "deleted" => 0,
                ], function ($row) use (&$batchIdsForPearl) {
                    $batchIdsForPearl[] = $row['batch'];
                });
                $where['AND']['production_batches.id'] = !empty($batchIdsForPearl) ? array_values(array_unique($batchIdsForPearl)) : [0];
            }

            $joins = [
                "[<]crafting" => ["crafting" => "id"]
            ];

            $count = $app->count("production_batches", ["AND" => $where['AND']]);
            $datas = [];

            $app->select("production_batches", $joins, [
                "production_batches.id",
                "production_batches.code",
                "production_batches.date",
                "production_batches.crafting",
                "crafting.name(crafting_name)",
                "crafting.code(crafting_code)",
                "crafting.price(crafting_price)",
                "crafting.amount_total(crafting_stock)",
            ], $where, function ($b) use (&$datas, $app, $jatbi, $tpStageId, $getBatchStockAtStage) {
                $stock = $getBatchStockAtStage($b['id'], $tpStageId);
                $lines = [];
                $totalKg = 0;
                $totalAmount = 0;

                foreach ($stock as $s) {
                    $parts = [];
                    if ($s['amount'] > 0) {
                        $parts[] = '<span class="fw-bold text-success">' . number_format($s['amount']) . ' ' . $jatbi->lang("viên") . '</span>';
                        $totalAmount += intval($s['amount']);
                    }
                    if ($s['weight_kg'] > 0) {
                        $parts[] = number_format($s['weight_kg'], 2) . ' kg';
                        $totalKg += floatval($s['weight_kg']);
                    }
                    $lines[] = '<div class="small text-nowrap"><span class="fw-semibold">'
                        . htmlspecialchars($s['pearl_name']) . ':</span> ' . implode(' · ', $parts) . '</div>';
                }

                $craftingDisplay = '';
                if (!empty($b['crafting_code'])) {
                    $craftingDisplay = '<div class="small"><span class="fw-bold text-primary">[' . htmlspecialchars($b['crafting_code']) . ']</span> ' . htmlspecialchars($b['crafting_name']) . '<br><span class="text-secondary">' . $jatbi->lang("Giá") . ': ' . number_format($b['crafting_price']) . ' đ | ' . $jatbi->lang("Tồn kho chế tác") . ': ' . number_format($b['crafting_stock']) . '</span></div>';
                } else {
                    $craftingDisplay = '<span class="text-secondary">' . $jatbi->lang("Chưa gắn thành phẩm") . '</span>';
                }

                $datas[] = [
                    "code" => '<span class="fw-bold">#' . htmlspecialchars($b['code']) . '</span>',
                    "pearl_name" => $craftingDisplay . (!empty($lines) ? '<div class="mt-1">' . implode('', $lines) . '</div>' : ''),
                    "amount" => $totalAmount > 0 ? '<span class="badge bg-success fs-6">' . number_format($totalAmount) . ' ' . $jatbi->lang("viên") . '</span>' : '<span class="text-secondary">-</span>',
                    "weight_kg" => $totalKg > 0 ? number_format($totalKg, 2) . ' kg' : '-',
                    "date" => date('d/m/Y H:i', strtotime($b['date'])),
                    "action" => '<div class="d-flex justify-content-end gap-1 flex-wrap">'
                        . '<a href="' . $jatbi->url('/qaqc/export-products/' . $b['id']) . '" class="btn btn-primary btn-sm rounded-pill px-3 pjax-load fw-semibold"><i class="ti ti-truck-delivery me-1"></i> ' . $jatbi->lang("Phân bổ xuống Quầy") . '</a>'
                        . '<button data-action="modal" data-url="/qaqc/export-sell/' . $b['id'] . '" class="btn btn-outline-success btn-sm rounded-pill px-2"><i class="ti ti-cash me-1"></i> ' . $jatbi->lang("Bán sỉ") . '</button>'
                        . '<button data-action="modal" data-url="/qaqc/export-ingredient/' . $b['id'] . '" class="btn btn-outline-secondary btn-sm rounded-pill px-2"><i class="ti ti-arrow-back-up me-1"></i> ' . $jatbi->lang("Trả kho NL") . '</button>'
                        . '</div>',
                ];
            });

            echo json_encode(["draw" => $draw, "recordsTotal" => $count, "recordsFiltered" => $count, "data" => $datas]);
        }
    })->setPermissions(['finish_stock']);

    // ============================================================
    // Nhánh 5.1: Phân bổ xuống Cửa hàng & Quầy Kho TP bán lẻ
    // (Sinh phiếu warehouses data=pairing, type=export, export_status=1)
    // ============================================================
    $app->router('/export-products/{id}', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template, $getStageByCode, $getBatchStockAtStage) {
        $vars['title'] = $jatbi->lang("Phân bổ xuống Cửa hàng & Quầy Kho Thành Phẩm");

        $batch = $app->get("production_batches", "*", ["id" => $vars['id'], "deleted" => 0]);
        if (!$batch) {
            echo $app->render($setting['template'] . '/pages/error.html', ['content' => $jatbi->lang('Không tìm thấy lô sản xuất')], $jatbi->ajax());
            return;
        }

        $tpStage = $getStageByCode('TP');
        $tpStageId = $tpStage['id'] ?? 5;
        $stock = $getBatchStockAtStage($vars['id'], $tpStageId);

        // Lấy thông tin crafting
        $craftingId = $batch['crafting'] ?? 0;
        $crafting = null;
        if ($craftingId > 0) {
            $crafting = $app->get("crafting", "*", ["id" => $craftingId, "deleted" => 0]);
        }

        if ($app->method() === 'GET') {
            if (empty($stock)) {
                echo $app->render($setting['template'] . '/pages/error.html', ['content' => $jatbi->lang('Lô này không còn tồn thành phẩm tại Kho TP QAQC')], $jatbi->ajax());
                return;
            }
            $vars['batch'] = $batch;
            $vars['crafting'] = $crafting;
            $vars['stock'] = array_values($stock);
            $vars['total_amount'] = array_sum(array_column($stock, 'amount'));
            $vars['total_weight_kg'] = array_sum(array_column($stock, 'weight_kg'));
            $vars['stores'] = $app->select("stores", ["id", "name"], ["deleted" => 0, "status" => "A"]);
            $vars['branches'] = $app->select("branch", ["id", "name", "stores"], ["deleted" => 0, "status" => "A"]);

            echo $app->render($template . '/qaqc/export-products.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $amount = intval($_POST['amount'] ?? 0);
            $storeId = intval($_POST['store_id'] ?? 0);
            $branchId = intval($_POST['branch_id'] ?? 0);
            $notes = $app->xss($_POST['notes'] ?? '');

            $totalStockAmount = array_sum(array_column($stock, 'amount'));
            if ($amount <= 0 || $amount > $totalStockAmount) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Số lượng phân bổ không hợp lệ (Tối đa: ') . $totalStockAmount . ')']);
                return;
            }
            if ($storeId <= 0) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Vui lòng chọn Cửa hàng tiếp nhận')]);
                return;
            }

            $userId = $app->getSession("accounts")['id'] ?? 0;
            $now = date('Y-m-d H:i:s');
            $batchId = $vars['id'];

            $storeInfo = $app->get("stores", ["id", "name"], ["id" => $storeId]);
            $branchInfo = $branchId > 0 ? $app->get("branch", ["id", "name"], ["id" => $branchId]) : null;
            $allocDesc = "Phân bổ từ Kho Chế tác QAQC xuống " . ($storeInfo['name'] ?? '') . ($branchInfo ? ' - ' . $branchInfo['name'] : '');

            $ok = false;
            try {
                $app->action(function () use ($app, $batchId, $batch, $tpStageId, $stock, $craftingId, $crafting, $amount, $storeId, $branchId, $notes, $allocDesc, $userId, $now, $jatbi, &$ok) {
                    // 1. Tạo phiếu xuất điều chuyển trong warehouses chuẩn quy trình pairing-export
                    $whInsert = [
                        "code" => 'PX',
                        "type" => 'export',
                        "data" => 'pairing',
                        "content" => $allocDesc . ($notes ? " ($notes)" : ""),
                        "stores" => $storeId,
                        "branch" => $branchId,
                        "export_status" => 1,
                        "user" => $userId,
                        "date" => date("Y-m-d"),
                        "active" => $jatbi->active(30),
                        "date_poster" => $now,
                        "deleted" => 0,
                    ];
                    $app->insert("warehouses", $whInsert);
                    $whOrderId = $app->id();
                    if (!$whOrderId) return false;

                    // 2. Tạo chi tiết trong warehouses_details
                    $whDetail = [
                        "warehouses" => $whOrderId,
                        "data" => 'pairing',
                        "type" => 'export',
                        "crafting" => $craftingId,
                        "amount" => $amount,
                        "amount_total" => $amount,
                        "price" => $crafting['price'] ?? 0,
                        "cost" => $crafting['cost'] ?? 0,
                        "notes" => $notes ?: 'Phân bổ từ QAQC',
                        "stores" => $storeId,
                        "branch" => $branchId,
                        "user" => $userId,
                        "date" => $now,
                        "deleted" => 0,
                    ];
                    $app->insert("warehouses_details", $whDetail);

                    // 3. Trừ tồn kho trong bảng crafting
                    if ($craftingId > 0) {
                        $app->update("crafting", [
                            "amount_total[-]" => $amount,
                            "amount_export[+]" => $amount,
                        ], ["id" => $craftingId]);
                    }

                    // 4. Ghi phiếu xuất khỏi Kho TP QAQC (production_stage_movements)
                    $app->insert("production_stage_movements", [
                        "batch" => $batchId, "stage" => $tpStageId, "type" => "export",
                        "stage_related" => null, "notes" => $allocDesc . ($notes ? " - $notes" : ""),
                        "date" => $now, "user" => $userId, "deleted" => 0,
                        "crafting" => $craftingId,
                    ]);
                    $exportId = $app->id();
                    if (!$exportId) return false;

                    // Trừ số lượng tồn tương ứng trong items
                    $remainToDeduct = $amount;
                    foreach ($stock as $s) {
                        if ($remainToDeduct <= 0) break;
                        $deduct = min($remainToDeduct, $s['amount']);
                        $app->insert("production_stage_movement_items", [
                            "movement" => $exportId, "pearl" => $s['pearl'],
                            "weight_kg" => 0, "amount" => $deduct,
                            "weight_kg_hao_hut" => 0, "amount_hao_hut" => 0,
                            "deleted" => 0,
                        ]);
                        $remainToDeduct -= $deduct;
                    }

                    $ok = true;
                    return true;
                });
            } catch (\Exception $e) {
                $ok = false;
            }

            if ($ok) {
                // Kiểm tra nếu đã hết tồn kho ở Kho TP thì hoàn thành lô
                $remainStock = $getBatchStockAtStage($batchId, $tpStageId);
                $totalRemain = array_sum(array_column($remainStock, 'amount'));
                if ($totalRemain <= 0) {
                    $app->update("production_batches", ["status" => 'D'], ["id" => $batchId]);
                }

                $jatbi->logs('warehouses', 'export_qaqc_to_store', ['batch' => $batchId, 'stores' => $storeId, 'branch' => $branchId, 'amount' => $amount]);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Đã phân bổ thành phẩm xuống Cửa hàng & Quầy thành công! Phiếu xuất đã sẵn sàng để quầy tiếp nhận.'), 'url' => $jatbi->url('/qaqc/finish-stock')]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Có lỗi xảy ra trong quá trình phân bổ kho')]);
            }
        }
    })->setPermissions(['finish_stock.export']);

    // ============================================================
    // Nhánh 5.2: Xuất bán trực tiếp / sỉ từ Kho TP QAQC
    // ============================================================
    $app->router('/export-sell/{id}', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template, $getStageByCode, $getBatchStockAtStage) {
        $vars['title'] = $jatbi->lang("Xuất bán sỉ / trực tiếp từ Kho QAQC");

        $batch = $app->get("production_batches", "*", ["id" => $vars['id'], "deleted" => 0]);
        if (!$batch) {
            echo $app->render($setting['template'] . '/pages/error.html', ['content' => $jatbi->lang('Không tìm thấy lô sản xuất')], $jatbi->ajax());
            return;
        }

        $tpStage = $getStageByCode('TP');
        $tpStageId = $tpStage['id'] ?? 5;
        $stock = $getBatchStockAtStage($vars['id'], $tpStageId);

        $craftingId = $batch['crafting'] ?? 0;
        $crafting = null;
        if ($craftingId > 0) {
            $crafting = $app->get("crafting", "*", ["id" => $craftingId, "deleted" => 0]);
        }

        if ($app->method() === 'GET') {
            if (empty($stock)) {
                echo $app->render($setting['template'] . '/pages/error.html', ['content' => $jatbi->lang('Lô này không còn tồn thành phẩm tại Kho TP QAQC')], $jatbi->ajax());
                return;
            }
            $vars['batch'] = $batch;
            $vars['crafting'] = $crafting;
            $vars['stock'] = array_values($stock);
            $vars['total_amount'] = array_sum(array_column($stock, 'amount'));
            $vars['total_weight_kg'] = array_sum(array_column($stock, 'weight_kg'));
            echo $app->render($template . '/qaqc/export-sell.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $amount = intval($_POST['amount'] ?? 0);
            $customerName = trim($app->xss($_POST['customer_name'] ?? ''));
            $priceSell = floatval($_POST['price_sell'] ?? 0);
            $notes = $app->xss($_POST['notes'] ?? '');

            $totalStockAmount = array_sum(array_column($stock, 'amount'));
            if ($amount <= 0 || $amount > $totalStockAmount) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Số lượng xuất bán không hợp lệ (Tối đa: ') . $totalStockAmount . ')']);
                return;
            }

            $userId = $app->getSession("accounts")['id'] ?? 0;
            $now = date('Y-m-d H:i:s');
            $batchId = $vars['id'];

            $ok = false;
            try {
                $app->action(function () use ($app, $batchId, $tpStageId, $stock, $craftingId, $amount, $customerName, $priceSell, $notes, $userId, $now, &$ok) {
                    // 1. Ghi phiếu xuất bán trong production_stage_movements
                    $app->insert("production_stage_movements", [
                        "batch" => $batchId, "stage" => $tpStageId, "type" => "export",
                        "stage_related" => null,
                        "notes" => "Xuất bán sỉ/trực tiếp: " . ($customerName ? "Khách: $customerName - " : "") . "SL: $amount - Đơn giá: " . number_format($priceSell) . " đ" . ($notes ? " ($notes)" : ""),
                        "date" => $now, "user" => $userId, "deleted" => 0,
                        "crafting" => $craftingId,
                    ]);
                    $exportId = $app->id();
                    if (!$exportId) return false;

                    $remainToDeduct = $amount;
                    foreach ($stock as $s) {
                        if ($remainToDeduct <= 0) break;
                        $deduct = min($remainToDeduct, $s['amount']);
                        $app->insert("production_stage_movement_items", [
                            "movement" => $exportId, "pearl" => $s['pearl'],
                            "weight_kg" => 0, "amount" => $deduct,
                            "weight_kg_hao_hut" => 0, "amount_hao_hut" => 0,
                            "deleted" => 0,
                        ]);
                        $remainToDeduct -= $deduct;
                    }

                    // 2. Trừ tồn kho trong crafting nếu có
                    if ($craftingId > 0) {
                        $app->update("crafting", [
                            "amount_total[-]" => $amount,
                            "amount_export[+]" => $amount,
                        ], ["id" => $craftingId]);
                    }

                    $ok = true;
                    return true;
                });
            } catch (\Exception $e) {
                $ok = false;
            }

            if ($ok) {
                $remainStock = $getBatchStockAtStage($batchId, $tpStageId);
                $totalRemain = array_sum(array_column($remainStock, 'amount'));
                if ($totalRemain <= 0) {
                    $app->update("production_batches", ["status" => 'D'], ["id" => $batchId]);
                }

                $jatbi->logs('production_batches', 'export_sell', ['batch' => $batchId, 'amount' => $amount, 'customer' => $customerName]);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Xuất bán thành công!'), 'url' => $_SERVER['HTTP_REFERER']]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Có lỗi xảy ra trong quá trình xuất bán')]);
            }
        }
    })->setPermissions(['finish_stock.export']);

    // ============================================================
    // Nhánh 5.3: Xuất trả ngọc nguyên liệu vào Kho NL (ingredient)
    // ============================================================
    $app->router('/export-ingredient/{id}', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template, $getStageByCode, $getBatchStockAtStage) {
        $vars['title'] = $jatbi->lang("Xuất trả vào Kho Nguyên Liệu");

        $batch = $app->get("production_batches", "*", ["id" => $vars['id'], "deleted" => 0]);
        if (!$batch) {
            echo $app->render($setting['template'] . '/pages/error.html', ['content' => $jatbi->lang('Không tìm thấy lô sản xuất')], $jatbi->ajax());
            return;
        }

        $tpStage = $getStageByCode('TP');
        $tpStageId = $tpStage['id'] ?? 5;
        $stock = $getBatchStockAtStage($vars['id'], $tpStageId);

        if ($app->method() === 'GET') {
            if (empty($stock)) {
                echo $app->render($setting['template'] . '/pages/error.html', ['content' => $jatbi->lang('Lô này không còn tồn thành phẩm tại Kho TP QAQC')], $jatbi->ajax());
                return;
            }
            $vars['batch'] = $batch;
            $vars['stock'] = array_values($stock);
            echo $app->render($template . '/qaqc/export-ingredient.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $linesRaw = $_POST['lines'] ?? [];
            $notes = $app->xss($_POST['notes'] ?? '');
            $cleanLines = [];
            $error = '';

            foreach ($stock as $pearlId => $s) {
                $row = $linesRaw[$pearlId] ?? [];
                $code = trim($app->xss($row['code'] ?? ''));
                $name = trim($app->xss($row['name_ingredient'] ?? ''));
                $amount = intval($row['amount'] ?? 0);
                $weightKg = floatval($row['weight_kg'] ?? 0);

                if ($code === '' || $name === '') {
                    $error = $jatbi->lang('Vui lòng nhập mã và tên nguyên liệu cho ') . ($s['pearl_name'] ?? '');
                    break;
                }
                if ($amount <= 0 && $weightKg <= 0) {
                    $error = $jatbi->lang('Vui lòng nhập số lượng hoặc khối lượng nguyên liệu');
                    break;
                }

                $cleanLines[] = [
                    'pearl' => $pearlId,
                    'code' => $code,
                    'name_ingredient' => $name,
                    'amount' => $amount,
                    'weight_kg' => $weightKg,
                ];
            }

            if ($error !== '') {
                echo json_encode(['status' => 'error', 'content' => $error]);
                return;
            }

            $userId = $app->getSession("accounts")['id'] ?? 0;
            $now = date('Y-m-d H:i:s');
            $batchId = $vars['id'];

            $ok = false;
            try {
                $app->action(function () use ($app, $batchId, $tpStageId, $cleanLines, $notes, $userId, $now, &$ok) {
                    // 1. Tạo bản ghi mới trong bảng ingredient cho từng loại ngọc (type = 2: ngọc)
                    foreach ($cleanLines as $cl) {
                        $app->insert("ingredient", [
                            "code" => $cl['code'],
                            "name_ingredient" => $cl['name_ingredient'],
                            "pearl" => $cl['pearl'],
                            "type" => 2, // 2: ngọc
                            "amount" => $cl['amount'],
                            "weight_kg" => $cl['weight_kg'],
                            "notes" => "Nhận trả từ Lô QAQC #" . $batchId . ($notes ? " - $notes" : ""),
                            "status" => 'A',
                            "date" => $now,
                            "user" => $userId,
                            "deleted" => 0,
                        ]);
                    }

                    // 2. Ghi phiếu xuất khỏi Kho TP QAQC
                    $app->insert("production_stage_movements", [
                        "batch" => $batchId, "stage" => $tpStageId, "type" => "export",
                        "stage_related" => null, "notes" => "Xuất trả vào Kho Nguyên Liệu: $notes",
                        "date" => $now, "user" => $userId, "deleted" => 0,
                    ]);
                    $exportId = $app->id();
                    if (!$exportId) return false;

                    foreach ($cleanLines as $cl) {
                        $app->insert("production_stage_movement_items", [
                            "movement" => $exportId, "pearl" => $cl['pearl'],
                            "weight_kg" => $cl['weight_kg'], "amount" => $cl['amount'],
                            "weight_kg_hao_hut" => 0, "amount_hao_hut" => 0,
                            "deleted" => 0,
                        ]);
                    }

                    $ok = true;
                    return true;
                });
            } catch (\Exception $e) {
                $ok = false;
            }

            if ($ok) {
                $remainStock = $getBatchStockAtStage($batchId, $tpStageId);
                $totalRemain = array_sum(array_column($remainStock, 'amount'));
                if ($totalRemain <= 0) {
                    $app->update("production_batches", ["status" => 'D'], ["id" => $batchId]);
                }

                $jatbi->logs('ingredient', 'export_from_qaqc', ['batch' => $batchId, 'lines' => $cleanLines]);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Đã xuất ngọc vào Kho Nguyên Liệu thành công!'), 'url' => $_SERVER['HTTP_REFERER']]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Có lỗi xảy ra, vui lòng thử lại')]);
            }
        }
    })->setPermissions(['finish_stock.export']);
    
    $app->router('/api/stage-stock-search/{code}', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $getStageByCode, $resolveTransferTo) {
        $app->header(['Content-Type' => 'application/json']);
        $code = strtoupper($app->xss($vars['code'] ?? ''));
        $fromStage = $getStageByCode($code);
        if (!$fromStage) {
            echo json_encode([]);
            return;
        }
        $toCode = $resolveTransferTo($code, $app->xss($_POST['to'] ?? $_GET['to'] ?? ''));

        $searchValue = trim(isset($_POST['search']) ? $app->xss($_POST['search']) : (isset($_GET['search']) ? $app->xss($_GET['search']) : ''));

        // Tính tồn kho hiện tại ở stage này
        $agg = [];
        $app->select("production_stage_movement_items", [
            "[><]production_stage_movements" => ["movement" => "id"],
        ], [
            "production_stage_movements.batch",
            "production_stage_movements.type",
            "production_stage_movement_items.pearl",
            "production_stage_movement_items.weight_kg",
            "production_stage_movement_items.weight_gr",
            "production_stage_movement_items.amount",
            "production_stage_movement_items.weight_kg_hao_hut",
            "production_stage_movement_items.weight_gr_hao_hut",
            "production_stage_movement_items.amount_hao_hut",
        ], [
            "production_stage_movements.stage" => $fromStage['id'],
            "production_stage_movements.deleted" => 0,
            "production_stage_movement_items.deleted" => 0,
        ], function ($r) use (&$agg) {
            $key = $r['batch'] . '_' . $r['pearl'];
            if (!isset($agg[$key])) {
                $agg[$key] = [
                    'batch' => $r['batch'],
                    'pearl' => $r['pearl'],
                    'weight_kg' => 0,
                    'weight_gr' => 0,
                    'amount' => 0,
                ];
            }
            if ($r['type'] === 'import') {
                $agg[$key]['weight_kg'] += floatval($r['weight_kg']);
                $agg[$key]['weight_gr'] += floatval($r['weight_gr'] ?? 0);
                $agg[$key]['amount'] += floatval($r['amount']);
            } else {
                $agg[$key]['weight_kg'] -= (floatval($r['weight_kg']) + floatval($r['weight_kg_hao_hut'] ?? 0));
                $agg[$key]['weight_gr'] -= (floatval($r['weight_gr'] ?? 0) + floatval($r['weight_gr_hao_hut'] ?? 0));
                $agg[$key]['amount'] -= (floatval($r['amount']) + floatval($r['amount_hao_hut'] ?? 0));
            }
        });

        $stockItems = [];
        $batchIds = [];
        $pearlIds = [];
        foreach ($agg as $a) {
            if ($a['weight_kg'] > 0.0001 || $a['weight_gr'] > 0.0001 || $a['amount'] > 0.0001) {
                $stockItems[] = $a;
                $batchIds[] = $a['batch'];
                $pearlIds[] = $a['pearl'];
            }
        }

        $batchMap = [];
        $pearlMap = [];
        if (!empty($batchIds)) {
            $app->select('production_batches', ['id', 'code'], ['id' => array_unique($batchIds)], function ($b) use (&$batchMap) {
                $batchMap[$b['id']] = $b['code'];
            });
        }
        if (!empty($pearlIds)) {
            $app->select('pearl', ['id', 'name', 'unit_mode'], ['id' => array_unique($pearlIds)], function ($p) use (&$pearlMap) {
                $pearlMap[$p['id']] = $p;
            });
        }

        $datas = [];
        foreach ($stockItems as $it) {
            $batchCode = $batchMap[$it['batch']] ?? '-';
            $p = $pearlMap[$it['pearl']] ?? null;
            $pearlName = $p['name'] ?? $jatbi->lang('Không xác định');
            $unitMode = $p['unit_mode'] ?? 'kg';

            if ($searchValue !== '' && stripos($batchCode . ' ' . $pearlName, $searchValue) === false) {
                continue;
            }

            $infoParts = [];
            if (($it['weight_gr'] ?? 0) > 0.0001) {
                $infoParts[] = number_format($it['weight_gr'], 2) . ' gr';
            }
            if ($it['weight_kg'] > 0) {
                $infoParts[] = number_format($it['weight_kg'], 2) . ' kg';
            }
            if ($it['amount'] > 0) {
                $infoParts[] = number_format($it['amount']) . ' ' . $jatbi->lang('viên');
            }
            if (empty($infoParts)) {
                $infoParts[] = '0 kg';
            }
            $infoText = $jatbi->lang('Tồn kho') . ': ' . implode(' · ', $infoParts);

            $datas[] = [
                'value' => $it['batch'] . '_' . $it['pearl'],
                'row_id' => $it['batch'] . '_' . $it['pearl'],
                'text' => '#' . $batchCode . ' - ' . $pearlName,
                'info' => $infoText,
                'batch' => $it['batch'],
                'batch_code' => $batchCode,
                'pearl' => $it['pearl'],
                'pearl_name' => $pearlName,
                'weight_kg' => floatval($it['weight_kg']),
                'weight_gr' => floatval($it['weight_gr'] ?? 0),
                'amount' => floatval($it['amount']),
                'unit_mode' => $unitMode,
                'url' => '/qaqc/stage-transfer-update/' . $code . '/' . $toCode . '/add/' . $it['batch'] . '/' . $it['pearl'],
            ];
        }

        echo json_encode($datas);
    });


})->middleware('login');