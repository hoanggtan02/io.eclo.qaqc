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
                    'qaqc' => [
                        "name" => $jatbi->lang("QAQC"),
                        "router" => '/qaqc',
                        "icon" => '<i class="ti ti-ticket"></i>',
                    ],
                    'batch' => [
                        "name" => $jatbi->lang("Lô sản xuất"),
                        "router" => '/qaqc/batch',
                        "icon" => '<i class="ti ti-package"></i>',
                    ],
                    'stage_stock' => [
                        "name" => $jatbi->lang("Tồn kho theo kho"),
                        "router" => '/qaqc/stage-stock',
                        "icon" => '<i class="ti ti-building-warehouse"></i>',
                    ],
                    'pearl_unit' => [
                        "name" => $jatbi->lang("Cách tính đơn vị"),
                        "router" => '/qaqc/pearl-unit',
                        "icon" => '<i class="ti ti-adjustments"></i>',
                    ],
                ],
                "main" => 'false',
                "permission" => [
                    // 'qaqc' => $jatbi->lang("Kho sản xuất QAQC"),
                    'batch' => $jatbi->lang("Xem danh sách lô sản xuất"),
                    'batch.add' => $jatbi->lang("Tạo lô sản xuất"),
                    'batch.edit' => $jatbi->lang("Sửa lô sản xuất"),
                    'stage_import' => $jatbi->lang("Nhập kho Vệ sinh cho lô sản xuất"),
                    'stage_transfer' => $jatbi->lang("Chuyển kho nội bộ theo lô (VS/KX/LT)"),
                    'stage_stock' => $jatbi->lang("Xem tồn kho theo từng kho"),
                    'pearl_unit' => $jatbi->lang("Xem cách tính đơn vị theo loại ngọc"),
                    'pearl_unit.edit' => $jatbi->lang("Sửa cách tính đơn vị theo loại ngọc"),
                ]
            ],
        ],
    ],
];