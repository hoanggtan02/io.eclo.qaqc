<?php
$jatbi = $app->getValueData('jatbi');
$setting = $app->getValueData('setting');
return [
    "content" => [
        "item" => [
            'qaqc' => [
                "menu" => $jatbi->lang("Kho Sản Xuất QAQC"),
                "url" => '/qaqc',
                "icon" => '<i class="ti ti-microscope"></i>',
                "sub" => [
                    'stage_vs' => [
                        "name" => $jatbi->lang("1. Kho Vệ Sinh"),
                        "router" => '/qaqc/stage/VS',
                        "icon" => '<i class="ti ti-droplet"></i>',
                    ],
                    'stage_kx' => [
                        "name" => $jatbi->lang("2. Kho Khoan Xiên"),
                        "router" => '/qaqc/stage/KX',
                        "icon" => '<i class="ti ti-settings"></i>',
                    ],
                    'stage_lt' => [
                        "name" => $jatbi->lang("3. Kho Lưu Trữ"),
                        "router" => '/qaqc/stage/LT',
                        "icon" => '<i class="ti ti-archive"></i>',
                    ],
                    // 'crafting' => [
                    //     "name" => $jatbi->lang("4. Kho Chế Tác"),
                    //     "router" => '/qaqc/crafting',
                    //     "icon" => '<i class="ti ti-hammer"></i>',
                    // ],
                    // 'finish_stock' => [
                    //     "name" => $jatbi->lang("5. Kho Thành Phẩm QAQC"),
                    //     "router" => '/qaqc/finish-stock',
                    //     "icon" => '<i class="ti ti-award"></i>',
                    // ],
                    'batch' => [
                        "name" => $jatbi->lang("Lô sản xuất"),
                        "router" => '/qaqc/batch',
                        "icon" => '<i class="ti ti-package"></i>',
                    ],
                    'stage_import_move' => [
                        "name" => $jatbi->lang("Danh sách nhập hàng"),
                        "router" => '/qaqc/stage-import-move/KX',
                        "icon" => '<i class="ti ti-download"></i>',
                    ],
                    'stage_history' => [
                        "name" => $jatbi->lang("Lịch sử chuyển kho"),
                        "router" => '/qaqc/stage-history/ALL',
                        "icon" => '<i class="ti ti-history"></i>',
                    ],
                    // 'pearl_unit' => [
                    //     "name" => $jatbi->lang("Cấu hình đơn vị ngọc"),
                    //     "router" => '/qaqc/pearl-unit',
                    //     "icon" => '<i class="ti ti-adjustments"></i>',
                    // ],
                ],
                "main" => 'false',
                "permission" => [
                    'qaqc' => $jatbi->lang("Kho sản xuất QAQC"),
                    'batch' => $jatbi->lang("Xem danh sách lô sản xuất"),
                    'batch.edit' => $jatbi->lang("Sửa lô sản xuất"),
                    'stage_import' => $jatbi->lang("Tạo lô sản xuất & Nhập kho Vệ sinh trực tiếp"),
                    'stage_transfer' => $jatbi->lang("Chuyển kho nội bộ theo lô (VS/KX)"),
                    'stage_import_move' => $jatbi->lang("Nhận hàng chuyển kho QAQC"),
                    'stage_history' => $jatbi->lang("Xem lịch sử chuyển kho QAQC"),
                    'stage_appraisal' => $jatbi->lang("Thẩm định ngọc (LT → CT)"),
                    'stage_vs' => $jatbi->lang("Xem Kho Vệ Sinh"),
                    'stage_kx' => $jatbi->lang("Xem Kho Khoan Xuyên"),
                    'stage_lt' => $jatbi->lang("Xem Kho Lưu Trữ"),
                    'crafting' => $jatbi->lang("Xem danh sách Kho Chế tác"),
                    'crafting.process' => $jatbi->lang("Nghiệm thu Chế tác & chuyển Kho TP"),
                    'finish_stock' => $jatbi->lang("Xem Kho Thành phẩm QAQC"),
                    'finish_stock.export' => $jatbi->lang("Thao tác 3 hướng xuất QAQC"),
                    'pearl_unit' => $jatbi->lang("Xem cách tính đơn vị theo loại ngọc"),
                    'pearl_unit.edit' => $jatbi->lang("Sửa cách tính đơn vị theo loại ngọc"),
                ]
            ],
        ],
    ],
];