<?php
$jatbi = $app->getValueData('jatbi');
$setting = $app->getValueData('setting');
return [
    "content" => [
        "item" => [
            'crafting' => [
                "menu" => $jatbi->lang("Chế tác"),
                "url" => '/crafting',
                "icon" => '<i class="ti ti-hammer"></i>',
                "sub" => [
                    'craftinggold' => [
                        "name" => $jatbi->lang("Kho chế tác vàng"),
                        "router" => '/crafting/craftinggold',
                        "icon" => '<i class="ti ti-box" style="color: #f1c40f;"></i>',
                    ],
                    'craftingsilver' => [
                        "name" => $jatbi->lang("Kho chế tác bạc"),
                        "router" => '/crafting/craftingsilver',
                        "icon" => '<i class="ti ti-box" style="color: #bdc3c7;"></i>',
                    ],
                    'craftingchain' => [
                        "name" => $jatbi->lang("Kho chế tác chuỗi"),
                        "router" => '/crafting/craftingchain',
                        "icon" => '<i class="ti ti-link"></i>',
                    ],
                    'goldsmithing' => [
                        "name" => $jatbi->lang("Chế tác vàng"),
                        "router" => '/crafting/goldsmithing',
                        "icon" => '<i class="ti ti-flame" style="color: #f1c40f;"></i>',
                    ],
                    'silversmithing' => [
                        "name" => $jatbi->lang("Chế tác bạc"),
                        "router" => '/crafting/silversmithing',
                        "icon" => '<i class="ti ti-flame" style="color: #bdc3c7;"></i>',
                    ],
                    'chainmaking' => [
                        "name" => $jatbi->lang("Chế tác chuỗi"),
                        "router" => '/crafting/chainmaking',
                        "icon" => '<i class="ti ti-flame"></i>',
                    ],
                    'fixed' => [
                        "name" => $jatbi->lang("Kho sửa chế tác"),
                        "router" => '/crafting/fixed',
                        "icon" => '<i class="ti ti-tool"></i>',
                    ],
                ],
                "main" => 'false',
                "permission" => [
                    "crafting.add" => $jatbi->lang("Chế tác"),

                    "crafting.history" => $jatbi->lang("Xem lịch sử chế tác"),
                    "crafting.import" => $jatbi->lang("Nhập chế tác"),

                    'split' => $jatbi->lang("Tách đai ngọc"),

                    'craftinggold' => $jatbi->lang("Xem kho chế tác vàng"),
                    'craftinggold.add' => $jatbi->lang("Thêm vào kho chế tác vàng"),
                    'craftinggold.edit' => $jatbi->lang("Sửa trong kho chế tác vàng"),
                    'craftinggold.deleted' => $jatbi->lang("Xóa khỏi kho chế tác vàng"),

                    'craftingsilver' => $jatbi->lang("Xem kho chế tác bạc"),
                    'craftingsilver.add' => $jatbi->lang("Thêm vào kho chế tác bạc"),
                    'craftingsilver.edit' => $jatbi->lang("Sửa trong kho chế tác bạc"),
                    'craftingsilver.deleted' => $jatbi->lang("Xóa khỏi kho chế tác bạc"),

                    'craftingchain' => $jatbi->lang("Xem kho chế tác chuỗi"),
                    'craftingchain.add' => $jatbi->lang("Thêm vào kho chế tác chuỗi"),
                    'craftingchain.edit' => $jatbi->lang("Sửa trong kho chế tác chuỗi"),
                    'craftingchain.deleted' => $jatbi->lang("Xóa khỏi kho chế tác chuỗi"),

                    'goldsmithing' => $jatbi->lang("Xem lệnh chế tác vàng"),
                    'goldsmithing.add' => $jatbi->lang("Tạo lệnh chế tác vàng"),
                    'goldsmithing.edit' => $jatbi->lang("Sửa lệnh chế tác vàng"),
                    'goldsmithing.deleted' => $jatbi->lang("Xóa lệnh chế tác vàng"),
                    'goldsmithing.approve' => $jatbi->lang("Duyệt lệnh chế tác vàng"),

                    'silversmithing' => $jatbi->lang("Xem lệnh chế tác bạc"),
                    'silversmithing.add' => $jatbi->lang("Tạo lệnh chế tác bạc"),
                    'silversmithing.edit' => $jatbi->lang("Sửa lệnh chế tác bạc"),
                    'silversmithing.deleted' => $jatbi->lang("Xóa lệnh chế tác bạc"),
                    'silversmithing.approve' => $jatbi->lang("Duyệt lệnh chế tác bạc"),

                    'chainmaking' => $jatbi->lang("Xem lệnh chế tác chuỗi"),
                    'chainmaking.add' => $jatbi->lang("Tạo lệnh chế tác chuỗi"),
                    'chainmaking.edit' => $jatbi->lang("Sửa lệnh chế tác chuỗi"),
                    'chainmaking.deleted' => $jatbi->lang("Xóa lệnh chế tác chuỗi"),
                    'chainmaking.approve' => $jatbi->lang("Duyệt lệnh chế tác chuỗi"),

                    'fixed' => $jatbi->lang("Xem kho sửa chế tác"),
                    'fixed.add' => $jatbi->lang("Thêm vào kho sửa chế tác"),
                    'fixed.edit' => $jatbi->lang("Sửa trong kho sửa chế tác"),
                    'fixed.deleted' => $jatbi->lang("Xóa khỏi kho sửa chế tác"),
                ]
            ],
        ],
    ],
];
