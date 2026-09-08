<?php

if (!defined('ECLO'))
    die("Hacking attempt");

use ECLO\App;

$template = __DIR__ . '/../templates';
$jatbi = $app->getValueData('jatbi');
$common = $jatbi->getPluginCommon('io.eclo.proposal');
$setting = $app->getValueData('setting');

$app->group($setting['manager'] . "/qaqc", function ($app) use ($jatbi, $setting, $template) {

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
            "production_stage_movement_items.amount",
        ], [
            "production_stage_movements.batch" => $batchId,
            "production_stage_movements.stage" => $stageId,
            "production_stage_movements.deleted" => 0,
            "production_stage_movement_items.deleted" => 0,
        ], function ($r) use (&$stock) {
            $pid = $r['pearl'];
            if (!isset($stock[$pid])) {
                $stock[$pid] = ['pearl' => $pid, 'weight_kg' => 0, 'amount' => 0, 'last_date' => $r['date']];
            }
            $sign = ($r['type'] === 'import') ? 1 : -1;
            $stock[$pid]['weight_kg'] += $sign * floatval($r['weight_kg']);
            $stock[$pid]['amount'] += $sign * floatval($r['amount']);
            if ($r['type'] === 'import' && $r['date'] > $stock[$pid]['last_date']) {
                $stock[$pid]['last_date'] = $r['date'];
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
            if ($s['weight_kg'] <= 0.0001 && $s['amount'] <= 0.0001) {
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
                    if ($it['weight_kg_initial'] !== null && $it['weight_kg_initial'] !== '') {
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
                if ($data['status'] === 'A' && $stage_info) {
                    if ($stage_info['code'] === 'VS') {
                        $importedVS = $app->has("production_stage_movements", [
                            "batch" => $data['id'],
                            "stage" => $stage_info['id'],
                            "type" => "import",
                            "deleted" => 0,
                        ]);
                        if (!$importedVS) {
                            $buttons[] = [
                                'type' => 'button',
                                'name' => $jatbi->lang("Nhập kho Vệ sinh"),
                                'permission' => ['stage_import'],
                                'action' => ['data-url' => '/qaqc/warehouse-import/' . $data['id'], 'data-action' => 'modal']
                            ];
                        }
                    }

                    if (isset($stageFlow[$stage_info['code']])) {
                        $stockHere = $getBatchStockAtStage($data['id'], $stage_info['id']);
                        if (!empty($stockHere)) {
                            $buttons[] = [
                                'type' => 'button',
                                'name' => $jatbi->lang("Chuyển kho"),
                                'permission' => ['stage_transfer'],
                                'action' => ['data-url' => '/qaqc/transfer/' . $data['id'], 'data-action' => 'modal']
                            ];
                        }
                    }

                    // Giai đoạn 4: Kho Chế tác (CT) -> Nghiệm thu Chế tác
                    if ($stage_info['code'] === 'CT') {
                        $stockHere = $getBatchStockAtStage($data['id'], $stage_info['id']);
                        if (!empty($stockHere)) {
                            $buttons[] = [
                                'type' => 'button',
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
                                'type' => 'button',
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

    $app->router('/batch-add', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template) {
        $vars['title'] = $jatbi->lang("Tạo lô sản xuất");

        if ($app->method() === 'GET') {
            $vars['data'] = [];
            $vars['items'] = [];
            $vars['pearl_options'] = [];

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
            $code = trim($app->xss($_POST['code'] ?? ''));
            $itemsRaw = $_POST['items'] ?? [];
            $cleanItems = [];

            if ($code === '') {
                $error = ['status' => 'error', 'content' => $jatbi->lang('Vui lòng nhập mã lô'), 'sound' => $setting['site_sound']];
            } elseif ($app->has('production_batches', ['code' => $code])) {
                $error = ['status' => 'error', 'content' => $jatbi->lang('Mã lô đã tồn tại, vui lòng nhập mã khác'), 'sound' => $setting['site_sound']];
            }

            if (empty($error) && (!is_array($itemsRaw) || count($itemsRaw) === 0)) {
                $error = ['status' => 'error', 'content' => $jatbi->lang('Vui lòng thêm ít nhất 1 dòng chi tiết'), 'sound' => $setting['site_sound']];
            }

            if (empty($error)) {
                foreach ($itemsRaw as $row) {
                    $pearl_id = $app->xss($row['pearl'] ?? '');
                    $kg = $app->xss($row['weight_kg_initial'] ?? '');
                    $vien = $app->xss($row['amount_initial'] ?? '');

                    if ($pearl_id === '') {
                        $error = ['status' => 'error', 'content' => $jatbi->lang('Vui lòng chọn loại ngọc cho tất cả các dòng'), 'sound' => $setting['site_sound']];
                        break;
                    }

                    $hasKg = ($kg !== '' && is_numeric($kg) && floatval($kg) > 0);
                    $hasVien = ($vien !== '' && is_numeric($vien) && floatval($vien) > 0);

                    if (!$hasKg && !$hasVien) {
                        $error = ['status' => 'error', 'content' => $jatbi->lang('Mỗi dòng cần nhập kg hoặc số viên hợp lệ'), 'sound' => $setting['site_sound']];
                        break;
                    }

                    $cleanItems[] = [
                        'pearl' => $pearl_id,
                        'weight_kg_initial' => $hasKg ? floatval($kg) : null,
                        'amount_initial' => $hasVien ? floatval($vien) : null,
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
                        $error = ['status' => 'error', 'content' => $jatbi->lang('Một hoặc nhiều loại ngọc không hợp lệ'), 'sound' => $setting['site_sound']];
                        break;
                    }
                }
            }

            if (empty($error)) {
                // Giai đoạn khởi đầu mặc định = Kho vệ sinh (VS)
                $vs_stage = $app->get("warehouse_stages", ["id"], ["code" => "VS"]);
                $now = date('Y-m-d H:i:s');
                $userId = $app->getSession("accounts")['id'] ?? 0;

                $insert = [
                    "code" => $code,
                    "current_stage" => $vs_stage['id'] ?? null,
                    "status" => 'A',
                    "notes" => $app->xss($_POST['notes'] ?? ''),
                    "date" => $now,
                    "user" => $userId,
                ];

                $app->insert("production_batches", $insert);
                $batchId = $app->id();

                $insertedItems = [];
                foreach ($cleanItems as $ci) {
                    $itemInsert = [
                        "batch" => $batchId,
                        "pearl" => $ci['pearl'],
                        "weight_kg_initial" => $ci['weight_kg_initial'],
                        "amount_initial" => $ci['amount_initial'],
                        "date" => $now,
                        "user" => $userId,
                        "deleted" => 0,
                    ];
                    $app->insert("production_batch_items", $itemInsert);
                    $insertedItems[] = $itemInsert;
                }

                $jatbi->logs('production_batches', 'add', ['batch' => $insert, 'items' => $insertedItems]);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Tạo lô sản xuất thành công'), 'url' => $_SERVER['HTTP_REFERER']]);
            } else {
                echo json_encode($error);
            }
        }
    })->setPermissions(['batch.add']);

    $app->router("/batch-edit/{id}", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template, $batchItemsWithPearl) {
        $vars['title'] = $jatbi->lang("Sửa lô sản xuất");

        if ($app->method() === 'GET') {
            $data = $app->get("production_batches", "*", ["id" => $vars['id']]);
            if (!$data) {
                echo $app->render($template . '/error.html', ['content' => 'Không tìm thấy lô sản xuất.'], $jatbi->ajax());
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
                $kg = $app->xss($row['weight_kg_initial'] ?? '');
                $vien = $app->xss($row['amount_initial'] ?? '');
                $hasKg = ($kg !== '' && is_numeric($kg) && floatval($kg) > 0);
                $hasVien = ($vien !== '' && is_numeric($vien) && floatval($vien) > 0);

                if ($rowId !== '') {
                    // Dòng đã tồn tại từ trước — không đổi loại ngọc, chỉ đổi kg/viên hoặc xoá mềm
                    if (!$deletedFlag && !$hasKg && !$hasVien) {
                        $error = ['status' => 'error', 'content' => $jatbi->lang('Mỗi dòng cần nhập kg hoặc số viên hợp lệ'), 'sound' => $setting['site_sound']];
                        break;
                    }
                    $existingUpdates[] = [
                        'id' => $rowId,
                        'weight_kg_initial' => (!$deletedFlag && $hasKg) ? floatval($kg) : null,
                        'amount_initial' => (!$deletedFlag && $hasVien) ? floatval($vien) : null,
                        'deleted' => $deletedFlag,
                    ];
                } else {
                    // Dòng mới thêm vào lô đã có
                    if ($deletedFlag) {
                        continue; // dòng mới thêm rồi xoá ngay trong lúc chưa lưu — bỏ qua
                    }
                    $pearl_id = $app->xss($row['pearl'] ?? '');
                    if ($pearl_id === '') {
                        $error = ['status' => 'error', 'content' => $jatbi->lang('Vui lòng chọn loại ngọc cho dòng mới'), 'sound' => $setting['site_sound']];
                        break;
                    }
                    if (!$hasKg && !$hasVien) {
                        $error = ['status' => 'error', 'content' => $jatbi->lang('Mỗi dòng cần nhập kg hoặc số viên hợp lệ'), 'sound' => $setting['site_sound']];
                        break;
                    }
                    $newInserts[] = [
                        'pearl' => $pearl_id,
                        'weight_kg_initial' => $hasKg ? floatval($kg) : null,
                        'amount_initial' => $hasVien ? floatval($vien) : null,
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
                        $error = ['status' => 'error', 'content' => $jatbi->lang('Một hoặc nhiều loại ngọc không hợp lệ'), 'sound' => $setting['site_sound']];
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
                    $error = ['status' => 'error', 'content' => $jatbi->lang('Lô sản xuất phải còn ít nhất 1 dòng chi tiết'), 'sound' => $setting['site_sound']];
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
                        "amount_initial" => $eu['amount_initial'],
                        "deleted" => $eu['deleted'],
                    ], ["id" => $eu['id'], "batch" => $vars['id']]);
                }

                foreach ($newInserts as $ni) {
                    $app->insert("production_batch_items", [
                        "batch" => $vars['id'],
                        "pearl" => $ni['pearl'],
                        "weight_kg_initial" => $ni['weight_kg_initial'],
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
    // 1c. GIAI ĐOẠN 2 — NHẬP NGỌC THÔ VÀO KHO VỆ SINH
    //     Chỉ áp dụng cho lô đang ở kho VS và CHƯA từng nhập kho VS.
    //     Ghi 1 phiếu production_stage_movements (type=import, stage=VS)
    //     + các dòng production_stage_movement_items (kg/viên thực nhận).
    // ============================================================

    $app->router("/warehouse-import/{id}", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template, $batchItemsWithPearl, $getStageByCode) {
        $vars['title'] = $jatbi->lang("Nhập kho Vệ sinh");

        $batch = $app->get("production_batches", "*", ["id" => $vars['id'], "deleted" => 0]);
        if (!$batch) {
            echo $app->render($template . '/error.html', ['content' => $jatbi->lang('Không tìm thấy lô sản xuất')], $jatbi->ajax());
            return;
        }

        $vsStage = $getStageByCode('VS');
        if (!$vsStage) {
            echo $app->render($template . '/error.html', ['content' => $jatbi->lang('Chưa cấu hình kho Vệ sinh (warehouse_stages.code = VS)')], $jatbi->ajax());
            return;
        }

        if ($batch['current_stage'] != $vsStage['id']) {
            echo $app->render($template . '/error.html', ['content' => $jatbi->lang('Lô này không còn ở kho Vệ sinh')], $jatbi->ajax());
            return;
        }

        $already = $app->has("production_stage_movements", [
            "batch" => $vars['id'], "stage" => $vsStage['id'], "type" => "import", "deleted" => 0,
        ]);

        if ($app->method() === 'GET') {
            if ($already) {
                echo $app->render($template . '/error.html', ['content' => $jatbi->lang('Lô này đã được nhập kho Vệ sinh rồi')], $jatbi->ajax());
                return;
            }
            $vars['batch'] = $batch;
            $vars['items'] = $batchItemsWithPearl($vars['id']);
            echo $app->render($template . '/qaqc/warehouse-import-post.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            if ($already) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Lô này đã được nhập kho Vệ sinh rồi')]);
                return;
            }

            $linesRaw = $_POST['lines'] ?? [];
            if (!is_array($linesRaw) || count($linesRaw) === 0) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Thiếu dữ liệu chi tiết lô')]);
                return;
            }

            $items = $batchItemsWithPearl($vars['id']);
            $itemsById = [];
            foreach ($items as $it) { $itemsById[$it['id']] = $it; }

            $cleanLines = [];
            $error = '';
            foreach ($linesRaw as $itemId => $row) {
                if (!isset($itemsById[$itemId])) continue;

                $kg = $app->xss($row['weight_kg'] ?? '');
                $vien = $app->xss($row['amount'] ?? '');
                $kgVal = (is_numeric($kg) && floatval($kg) > 0) ? floatval($kg) : 0;
                $vienVal = (is_numeric($vien) && floatval($vien) > 0) ? floatval($vien) : 0;

                if ($kgVal <= 0 && $vienVal <= 0) {
                    $error = $jatbi->lang('Vui lòng nhập kg hoặc số viên thực nhận cho tất cả các dòng');
                    break;
                }

                $cleanLines[] = [
                    'pearl' => $itemsById[$itemId]['pearl'],
                    'weight_kg' => $kgVal,
                    'amount' => $vienVal,
                ];
            }

            if ($error === '' && count($cleanLines) === 0) {
                $error = $jatbi->lang('Không có dòng dữ liệu hợp lệ');
            }

            if ($error !== '') {
                echo json_encode(['status' => 'error', 'content' => $error, 'sound' => $setting['site_sound']]);
                return;
            }

            $userId = $app->getSession("accounts")['id'] ?? 0;
            $now = date('Y-m-d H:i:s');
            $batchId = $vars['id'];
            $vsStageId = $vsStage['id'];

            $ok = $app->action(function () use ($app, $batchId, $vsStageId, $cleanLines, $userId, $now) {
                $app->insert("production_stage_movements", [
                    "batch" => $batchId,
                    "stage" => $vsStageId,
                    "type" => "import",
                    "stage_related" => null,
                    "notes" => "Nhập ngọc thô vào kho Vệ sinh",
                    "date" => $now,
                    "user" => $userId,
                    "deleted" => 0,
                ]);
                $movementId = $app->id();
                if (!$movementId) return false;

                foreach ($cleanLines as $cl) {
                    $app->insert("production_stage_movement_items", [
                        "movement" => $movementId,
                        "pearl" => $cl['pearl'],
                        "weight_kg" => $cl['weight_kg'],
                        "amount" => $cl['amount'],
                        "weight_kg_hao_hut" => 0,
                        "amount_hao_hut" => 0,
                        "deleted" => 0,
                    ]);
                }
                return true;
            });

            if ($ok) {
                $jatbi->logs('production_stage_movements', 'warehouse_import', ['batch' => $batchId, 'lines' => $cleanLines]);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Nhập kho Vệ sinh thành công'), 'url' => $_SERVER['HTTP_REFERER']]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Có lỗi xảy ra, vui lòng thử lại')]);
            }
        }
    })->setPermissions(['stage_import']);


    // ============================================================
    // 1d. GIAI ĐOẠN 3 — ENGINE "CHUYỂN KHO NỘI BỘ THEO LÔ"
    //     Dùng chung cho VS→KX, KX→LT, LT→CT. Kho nguồn = current_stage
    //     của lô, kho đích lấy theo $stageFlow. Tự tính hao hụt =
    //     tồn trước - số chuyển đi, chặn không cho chuyển vượt tồn.
    // ============================================================

    $app->router("/transfer/{id}", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template, $stageFlow, $getStageByCode, $getStageById, $getBatchStockAtStage) {
        $batch = $app->get("production_batches", "*", ["id" => $vars['id'], "deleted" => 0]);
        if (!$batch) {
            echo $app->render($template . '/error.html', ['content' => $jatbi->lang('Không tìm thấy lô sản xuất')], $jatbi->ajax());
            return;
        }

        $fromStage = $getStageById($batch['current_stage']);
        if (!$fromStage || !isset($stageFlow[$fromStage['code']])) {
            echo $app->render($template . '/error.html', ['content' => $jatbi->lang('Lô này không ở trong luồng chuyển kho nội bộ (VS/KX/LT)')], $jatbi->ajax());
            return;
        }
        $toStage = $getStageByCode($stageFlow[$fromStage['code']]);
        if (!$toStage) {
            echo $app->render($template . '/error.html', ['content' => $jatbi->lang('Chưa cấu hình kho đích trong warehouse_stages')], $jatbi->ajax());
            return;
        }

        $vars['title'] = $jatbi->lang('Chuyển kho') . ': ' . $fromStage['name'] . ' → ' . $toStage['name'];
        $stock = $getBatchStockAtStage($vars['id'], $fromStage['id']);

        if ($app->method() === 'GET') {
            if (empty($stock)) {
                echo $app->render($template . '/error.html', ['content' => $jatbi->lang('Lô này chưa có tồn kho tại') . ' ' . $fromStage['name']], $jatbi->ajax());
                return;
            }
            $vars['batch'] = $batch;
            $vars['from_stage'] = $fromStage;
            $vars['to_stage'] = $toStage;
            $vars['stock'] = array_values($stock);
            echo $app->render($template . '/qaqc/transfer-post.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            if (empty($stock)) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Lô này chưa có tồn kho tại') . ' ' . $fromStage['name']]);
                return;
            }

            $linesRaw = $_POST['lines'] ?? [];
            $cleanLines = [];
            $error = '';

            foreach ($stock as $pearlId => $s) {
                $row = $linesRaw[$pearlId] ?? [];
                $kg = $app->xss($row['weight_kg'] ?? '');
                $vien = $app->xss($row['amount'] ?? '');
                $kgOut = (is_numeric($kg)) ? floatval($kg) : 0;
                $vienOut = (is_numeric($vien)) ? floatval($vien) : 0;

                if ($kgOut < 0 || $vienOut < 0) {
                    $error = $jatbi->lang('Số lượng chuyển đi không được âm');
                    break;
                }
                if ($kgOut > $s['weight_kg'] + 0.0001) {
                    $error = $jatbi->lang('Số kg chuyển đi vượt quá tồn kho của') . ' ' . ($s['pearl_name'] ?? '');
                    break;
                }
                if ($vienOut > $s['amount'] + 0.0001) {
                    $error = $jatbi->lang('Số viên chuyển đi vượt quá tồn kho của') . ' ' . ($s['pearl_name'] ?? '');
                    break;
                }

                $cleanLines[] = [
                    'pearl' => $pearlId,
                    'weight_kg_out' => $kgOut,
                    'weight_kg_hao_hut' => max(0, $s['weight_kg'] - $kgOut),
                    'amount_out' => $vienOut,
                    'amount_hao_hut' => max(0, $s['amount'] - $vienOut),
                ];
            }

            if ($error === '') {
                $totalOut = array_sum(array_column($cleanLines, 'weight_kg_out')) + array_sum(array_column($cleanLines, 'amount_out'));
                if ($totalOut <= 0) {
                    $error = $jatbi->lang('Vui lòng nhập số lượng chuyển đi cho ít nhất 1 dòng');
                }
            }

            if ($error !== '') {
                echo json_encode(['status' => 'error', 'content' => $error, 'sound' => $setting['site_sound']]);
                return;
            }

            $userId = $app->getSession("accounts")['id'] ?? 0;
            $now = date('Y-m-d H:i:s');
            $batchId = $vars['id'];
            $fromId = $fromStage['id'];
            $toId = $toStage['id'];

            $ok = $app->action(function () use ($app, $batchId, $fromId, $toId, $cleanLines, $userId, $now) {
                // Phiếu xuất khỏi kho nguồn — chốt hao hụt tại đây
                $app->insert("production_stage_movements", [
                    "batch" => $batchId, "stage" => $fromId, "type" => "export",
                    "stage_related" => $toId, "notes" => "Chuyển kho nội bộ",
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

                // Phiếu nhập vào kho đích — nhận đúng số đã chuyển đi, hao hụt = 0
                $app->insert("production_stage_movements", [
                    "batch" => $batchId, "stage" => $toId, "type" => "import",
                    "stage_related" => $fromId, "notes" => "Chuyển kho nội bộ",
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

                // Lô chuyển hẳn sang kho kế tiếp
                $app->update("production_batches", ["current_stage" => $toId], ["id" => $batchId]);

                return true;
            });

            if ($ok) {
                $jatbi->logs('production_stage_movements', 'transfer', ['batch' => $batchId, 'from' => $fromId, 'to' => $toId, 'lines' => $cleanLines]);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Chuyển kho thành công') . ': ' . $fromStage['name'] . ' → ' . $toStage['name'], 'url' => $_SERVER['HTTP_REFERER']]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Có lỗi xảy ra, vui lòng thử lại')]);
            }
        }
    })->setPermissions(['stage_transfer']);


    // ============================================================
    // 1e. GIAI ĐOẠN 3 — DANH SÁCH "LÔ ĐANG TỒN TẠI TỪNG KHO"
    //     Hiển thị cho VS/KX/LT (CT sẽ do giai đoạn 4 xử lý riêng).
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
                        'type' => 'button',
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
                        'type' => 'button',
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
                    $stockBtns[] = [
                        'type' => 'button',
                        'name' => $jatbi->lang("Chuyển kho"),
                        'permission' => ['stage_transfer'],
                        'action' => ['data-url' => '/qaqc/transfer/' . $r['batch'], 'data-action' => 'modal']
                    ];
                }

                $rowItem["action"] = $app->component("action", ["button" => $stockBtns]);
                $datas[] = $rowItem;
            }

            echo json_encode(["draw" => $draw, "recordsTotal" => $count, "recordsFiltered" => $count, "data" => $datas]);
        }
    })->setPermissions(['stage_stock']);


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
                echo $app->render($template . '/error.html', $vars, $jatbi->ajax());
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
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Vui lòng chọn cách tính đơn vị hợp lệ'), 'sound' => $setting['site_sound']]);
                return;
            }

            $update = ["unit_mode" => $unit_mode];
            $app->update("pearl", $update, ["id" => $vars['id']]);
            $jatbi->logs('pearl', 'edit_unit_mode', $update);
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Cập nhật thành công'), 'url' => $_SERVER['HTTP_REFERER']]);
        }
    })->setPermissions(['pearl_unit.edit']);



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

                $datas[] = [
                    "code" => '<span class="fw-bold">' . htmlspecialchars($b['code']) . '</span><br>' . $stageName,
                    "pearl_name" => !empty($lines) ? implode('', $lines) : '<span class="text-secondary">-</span>',
                    "weight_kg" => $totalKg > 0 ? number_format($totalKg, 2) . ' kg' : '-',
                    "amount" => $totalAmount > 0 ? number_format($totalAmount) . ' ' . $jatbi->lang("viên") : '<span class="text-secondary">-</span>',
                    "date" => date('d/m/Y H:i', strtotime($b['date'])),
                    "action" => '<a href="' . $jatbi->url('/qaqc/crafting-process/' . $b['id']) . '" class="btn btn-primary btn-sm rounded-pill px-3 pjax-load fw-semibold"><i class="ti ti-tools me-1"></i> ' . $jatbi->lang("Vào Chế tác") . '</a>',
                ];
            });

            echo json_encode(["draw" => $draw, "recordsTotal" => $count, "recordsFiltered" => $count, "data" => $datas]);
        }
    })->setPermissions(['crafting']);

    $app->router('/crafting-process/{id}', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template, $getStageByCode, $getBatchStockAtStage) {
        $vars['title'] = $jatbi->lang("Nghiệm thu Chế tác & Lên Thành Phẩm QAQC");

        $batch = $app->get("production_batches", "*", ["id" => $vars['id'], "deleted" => 0]);
        if (!$batch) {
            echo $app->render($template . '/error.html', ['content' => $jatbi->lang('Không tìm thấy lô sản xuất')], $jatbi->ajax());
            return;
        }

        $ltStage = $getStageByCode('LT');
        $ctStage = $getStageByCode('CT');
        $tpStage = $getStageByCode('TP');
        $ltStageId = $ltStage['id'] ?? 3;
        $ctStageId = $ctStage['id'] ?? 4;
        $tpStageId = $tpStage['id'] ?? 5;

        $currentStageId = $batch['current_stage'];
        if ($currentStageId != $ltStageId && $currentStageId != $ctStageId) {
            echo $app->render($template . '/error.html', ['content' => $jatbi->lang('Lô này hiện không ở Kho Lưu Trữ (LT) hoặc Kho Chế tác (CT)')], $jatbi->ajax());
            return;
        }

        $stock = $getBatchStockAtStage($vars['id'], $currentStageId);
        if (empty($stock)) {
            echo $app->render($template . '/error.html', ['content' => $jatbi->lang('Lô này không còn tồn ngọc để vào chế tác')], $jatbi->ajax());
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
                echo json_encode(['status' => 'error', 'content' => $error, 'sound' => $setting['site_sound']]);
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

            $ok = $app->action(function () use ($app, $batchId, $batch, $currentStageId, $ctStageId, $tpStageId, $cleanLines, $userId, $now, $productCode, $productName, $categoryId, $groupId, $defaultCodeId, $unitId, $personnelId, $price, $cost, $notes, $groupCrafting, $daiId, $daiAmountPerItem, $daiInfo, $totalFinishAmount) {
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

                return true;
            });

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
            echo $app->render($template . '/error.html', ['content' => $jatbi->lang('Không tìm thấy lô sản xuất')], $jatbi->ajax());
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
                echo $app->render($template . '/error.html', ['content' => $jatbi->lang('Lô này không còn tồn thành phẩm tại Kho TP QAQC')], $jatbi->ajax());
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
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Số lượng phân bổ không hợp lệ (Tối đa: ') . $totalStockAmount . ')', 'sound' => $setting['site_sound']]);
                return;
            }
            if ($storeId <= 0) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Vui lòng chọn Cửa hàng tiếp nhận'), 'sound' => $setting['site_sound']]);
                return;
            }

            $userId = $app->getSession("accounts")['id'] ?? 0;
            $now = date('Y-m-d H:i:s');
            $batchId = $vars['id'];

            $storeInfo = $app->get("stores", ["id", "name"], ["id" => $storeId]);
            $branchInfo = $branchId > 0 ? $app->get("branch", ["id", "name"], ["id" => $branchId]) : null;
            $allocDesc = "Phân bổ từ Kho Chế tác QAQC xuống " . ($storeInfo['name'] ?? '') . ($branchInfo ? ' - ' . $branchInfo['name'] : '');

            $ok = $app->action(function () use ($app, $batchId, $batch, $tpStageId, $stock, $craftingId, $crafting, $amount, $storeId, $branchId, $notes, $allocDesc, $userId, $now, $jatbi) {
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

                return true;
            });

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
            echo $app->render($template . '/error.html', ['content' => $jatbi->lang('Không tìm thấy lô sản xuất')], $jatbi->ajax());
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
                echo $app->render($template . '/error.html', ['content' => $jatbi->lang('Lô này không còn tồn thành phẩm tại Kho TP QAQC')], $jatbi->ajax());
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

            $ok = $app->action(function () use ($app, $batchId, $tpStageId, $stock, $craftingId, $amount, $customerName, $priceSell, $notes, $userId, $now) {
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

                return true;
            });

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
            echo $app->render($template . '/error.html', ['content' => $jatbi->lang('Không tìm thấy lô sản xuất')], $jatbi->ajax());
            return;
        }

        $tpStage = $getStageByCode('TP');
        $tpStageId = $tpStage['id'] ?? 5;
        $stock = $getBatchStockAtStage($vars['id'], $tpStageId);

        if ($app->method() === 'GET') {
            if (empty($stock)) {
                echo $app->render($template . '/error.html', ['content' => $jatbi->lang('Lô này không còn tồn thành phẩm tại Kho TP QAQC')], $jatbi->ajax());
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
                echo json_encode(['status' => 'error', 'content' => $error, 'sound' => $setting['site_sound']]);
                return;
            }

            $userId = $app->getSession("accounts")['id'] ?? 0;
            $now = date('Y-m-d H:i:s');
            $batchId = $vars['id'];

            $ok = $app->action(function () use ($app, $batchId, $tpStageId, $cleanLines, $notes, $userId, $now) {
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

                return true;
            });

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

})->middleware('login');
