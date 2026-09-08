<?php

use Picqer\Barcode\BarcodeGeneratorPNG;
use PhpOffice\PhpSpreadsheet\IOFactory;

if (!defined('ECLO'))
    die("Hacking attempt");

use ECLO\App;

$template = __DIR__ . '/../templates';
$jatbi = $app->getValueData('jatbi');
$common = $jatbi->getPluginCommon('io.eclo.proposal');
$setting = $app->getValueData('setting');

$stores_json = $app->getCookie('stores') ?? json_encode([]);
$stores = json_decode($stores_json, true);
$session = $app->getSession("accounts");
$accStore = [];
if (isset($session['id'])) {
    $account = $app->get("accounts", "*", [
        "id" => $session['id'],
        "deleted" => 0,
        "status" => "A",
    ]);
    if ($account['stores'] == '') {
        $accStore[0] = "0"; // chỉ thêm 1 lần
    }

    foreach ($stores as $itemStore) {
        $accStore[$itemStore['value']] = $itemStore['value'];
    }
}


//mã cố định
$app->group($setting['manager'] . "/warehouses", function ($app) use ($jatbi, $setting, $accStore, $stores,$template) {
    $app->router('/default_code', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Mã cố định");

        if ($app->method() === 'GET') {
            echo $app->render($template . '/warehouses/default-code.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
            $statusValue = isset($_POST['status']) ? $_POST['status'] : '';
            $orderName = isset($_POST['order'][0]['name']) ? $_POST['order'][0]['name'] : 'id';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            $where = [
                "AND" => [
                    "default_code.deleted" => 0,
                ],
                "LIMIT" => [$start, $length],
                "ORDER" => [$orderName => strtoupper($orderDir)]
            ];

            if ($searchValue != '') {
                $where['AND']['OR'] = [
                    'default_code.name[~]' => $searchValue,
                    'default_code.notes[~]' => $searchValue,
                    'default_code.code[~]' => $searchValue,
                ];
            }

            if ($statusValue != '') {
                $where['AND']['default_code.status'] = $statusValue;
            }

            $countWhere = [
                "AND" => array_merge(
                    ["default_code.deleted" => 0],
                    $searchValue != '' ? [
                        "OR" => [
                            'default_code.name[~]' => $searchValue,
                            'default_code.notes[~]' => $searchValue,
                            'default_code.code[~]' => $searchValue,
                        ]
                    ] : [],
                    $statusValue != '' ? ["default_code.status" => $statusValue] : []
                )
            ];
            $count = $app->count("default_code", $countWhere);

            $datas = [];
            $app->select("default_code", [
                'default_code.id',
                'default_code.code',
                'default_code.name',
                'default_code.notes',
                'default_code.status'
            ], $where, function ($data) use (&$datas, $jatbi, $app) {
                $datas[] = [
                    "checkbox" => ($app->component("box", ["data" => $data['id'] ?? '']) ?? '<input type="checkbox">'),
                    "code" => ($data['code'] ?? ''),
                    "name" => ($data['name'] ?? ''),
                    "notes" => ($data['notes'] ?? ''),
                    "status" => $app->component("status", ["url" => "/warehouses/default_code-status/" . $data['id'], "data" => $data['status'], "permission" => ['default_code.edit']]),
                    "action" => ($app->component("action", [
                        "button" => [
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Sửa"),
                                'permission' => ['default_code.edit'],
                                'action' => ['data-url' => '/warehouses/default_code-edit/' . ($data['id'] ?? ''), 'data-action' => 'modal']
                            ],
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Xóa"),
                                'permission' => ['default_code.deleted'],
                                'action' => ['data-url' => '/warehouses/default_code-deleted?box=' . ($data['id'] ?? ''), 'data-action' => 'modal']
                            ],
                        ]
                    ]))
                ];
            });

            echo json_encode(
                [
                    "draw" => $draw,
                    "recordsTotal" => $count,
                    "recordsFiltered" => $count,
                    "data" => $datas ?? []
                ],

            );
        }
    })->setPermissions(['default_code']);

    $app->router('/default_code-add', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Thêm Mã cố định");

        if ($app->method() === 'GET') {
            echo $app->render($template . '/warehouses/default-code-post.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json',]);
            if (empty($_POST['name'])) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Vui lòng không để trống")]);
                return;
            }
            if (empty($_POST['code'])) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Vui lòng không để trống")]);
                return;
            }
            $insert = [
                "code" => $app->xss($_POST['code']),
                "name" => $app->xss($_POST['name']),
                "notes" => $app->xss($_POST['notes']),
                "status" => $app->xss($_POST['status']),
            ];
            $app->insert("default_code", $insert);
            $jatbi->logs('default_code', 'add', $insert);
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
        }
    })->setPermissions(['default_code.add']);



    $app->router("/default_code-edit/{id}", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Sửa Mã cố định");

        if ($app->method() === 'GET') {
            $vars['data'] = $app->select("default_code", [
                "id",
                "code",
                "name",
                "notes",
                "status"
            ], [
                "AND" => [
                    "id" => $vars['id'],
                    "deleted" => 0
                ],
                "LIMIT" => 1
            ]);

            if (!empty($vars['data'])) {
                $vars['data'] = $vars['data'][0];
                echo $app->render($template . '/warehouses/default-code-post.html', $vars, $jatbi->ajax());
            } else {
                echo $app->render($template . '/error.html', $vars, $jatbi->ajax());
            }
        } elseif ($app->method() === 'POST') {
            $app->header([
                'Content-Type' => 'application/json',
            ]);

            $data = $app->select("default_code", [
                "id",
                "code",
                "name",
                "notes",
                "status"
            ], [
                "AND" => [
                    "id" => $vars['id'],
                    "deleted" => 0
                ],
                "LIMIT" => 1
            ]);

            if (!empty($data)) {
                $data = $data[0];
                $error = [];
                if ($app->xss($_POST['name']) == '') {
                    $error = ["status" => "error", "content" => $jatbi->lang("Vui lòng không để trống")];
                }
                if ($app->xss($_POST['code']) == '') {
                    $error = ["status" => "error", "content" => $jatbi->lang("Vui lòng không để trống")];
                }

                if (empty($error)) {
                    $insert = [
                        "code" => $app->xss($_POST['code']),
                        "name" => $app->xss($_POST['name']),
                        "notes" => $app->xss($_POST['notes']),
                        "status" => $app->xss($_POST['status']),
                    ];
                    $app->update("default_code", $insert, ["id" => $data['id']]);
                    $jatbi->logs('default_code', 'edit', $insert);
                    echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công"), "url" => $_SERVER['HTTP_REFERER']]);
                } else {
                    echo json_encode($error);
                }
            } else {
                echo json_encode(["status" => "error", "content" => $jatbi->lang("Không tìm thấy dữ liệu")]);
            }
        }
    })->setPermissions(['default_code.edit']);



    $app->router("/default_code-deleted", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Xóa mã cố định");
        if ($app->method() === 'GET') {
            echo $app->render($template . '/common/deleted.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header([
                'Content-Type' => 'application/json',
            ]);
            $boxid = explode(',', $app->xss($_GET['box']));
            $datas = $app->select("default_code", "*", ["id" => $boxid, "deleted" => 0]);
            if (count($datas) > 0) {
                foreach ($datas as $data) {
                    $app->update("default_code", ["deleted" => 1], ["id" => $data['id']]);
                    $name[] = $data['name'];
                }
                $jatbi->logs('default_code', 'default_code-deleted', $datas);
                $jatbi->trash('/warehouses/default_code-restore', "Xóa mã cố định: " . implode(', ', $name), ["database" => 'customers_card', "data" => $boxid]);
                echo json_encode(['status' => 'success', "content" => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Có lỗi xẩy ra")]);
            }
        }
    })->setPermissions(['default_code.deleted']);



    $app->router("/default_code-status/{id}", 'POST', function ($vars) use ($app, $jatbi) {
        $app->header([
            'Content-Type' => 'application/json',
        ]);
        $data = $app->get("default_code", "*", ["id" => $vars['id'], "deleted" => 0]);
        if ($data > 1) {
            if ($data > 1) {
                if ($data['status'] === 'A') {
                    $status = "D";
                } elseif ($data['status'] === 'D') {
                    $status = "A";
                }
                $app->update("default_code", ["status" => $status], ["id" => $data['id']]);
                $jatbi->logs('default_code', 'default_code-status', $data);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Cập nhật thất bại"),]);
            }
        } else {
            echo json_encode(["status" => "error", "content" => $jatbi->lang("Không tìm thấy dữ liệu")]);
        }
    })->setPermissions(['default_code.edit']);


    //đơn vị
    $app->router('/units', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Đơn vị");

        if ($app->method() === 'GET') {
            echo $app->render($template . '/warehouses/units.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
            $statusValue = isset($_POST['status']) ? $_POST['status'] : '';
            $orderName = isset($_POST['order'][0]['name']) ? $_POST['order'][0]['name'] : 'id';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            $where = [
                "AND" => [
                    "units.deleted" => 0,
                ],
                "LIMIT" => [$start, $length],
                "ORDER" => [$orderName => strtoupper($orderDir)]
            ];

            if ($searchValue != '') {
                $where['AND']['OR'] = [
                    'units.name[~]' => $searchValue,
                    'units.notes[~]' => $searchValue,
                ];
            }

            if ($statusValue != '') {
                $where['AND']['units.status'] = $statusValue;
            }

            $countWhere = [
                "AND" => array_merge(
                    ["units.deleted" => 0],
                    $searchValue != '' ? [
                        "OR" => [
                            'units.name[~]' => $searchValue,
                            'units.notes[~]' => $searchValue,
                        ]
                    ] : [],
                    $statusValue != '' ? ["units.status" => $statusValue] : []
                )
            ];
            $count = $app->count("units", $countWhere);

            $datas = [];
            $app->select("units", [
                'units.id',

                'units.name',
                'units.notes',
                'units.status'
            ], $where, function ($data) use (&$datas, $jatbi, $app) {
                $datas[] = [
                    "checkbox" => ($app->component("box", ["data" => $data['id'] ?? '']) ?? '<input type="checkbox">'),
                    "name" => ($data['name'] ?? ''),
                    "notes" => ($data['notes'] ?? ''),
                    "status" => ($app->component("status", [
                        "url" => "/warehouses/units-status/" . ($data['id'] ?? ''),
                        "data" => $data['status'] ?? '',
                        "permission" => ['units.edit'],
                    ])),
                    "action" => ($app->component("action", [
                        "button" => [
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Sửa"),
                                'permission' => ['units.edit'],
                                'action' => ['data-url' => '/warehouses/units-edit/' . ($data['id'] ?? ''), 'data-action' => 'modal']
                            ],
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Xóa"),
                                'permission' => ['units.deleted'],
                                'action' => ['data-url' => '/warehouses/units-deleted?box=' . ($data['id'] ?? ''), 'data-action' => 'modal']
                            ],
                        ]
                    ]))
                ];
            });

            echo json_encode(
                [
                    "draw" => $draw,
                    "recordsTotal" => $count,
                    "recordsFiltered" => $count,
                    "data" => $datas ?? []
                ],

            );
        }
    })->setPermissions(['units']);


    $app->router('/units-add', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Thêm đơn vị");

        if ($app->method() === 'GET') {
            echo $app->render($template . '/warehouses/units-post.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json',]);
            if (empty($_POST['name'])) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Vui lòng không để trống")]);
                return;
            }

            $insert = [
                "name" => $app->xss($_POST['name']),
                "notes" => $app->xss($_POST['notes']),
                "status" => $app->xss($_POST['status']),
            ];
            $app->insert("units", $insert);
            $jatbi->logs('units', 'add', $insert);
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
        }
    })->setPermissions(['units.add']);


    $app->router("/units-edit/{id}", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Sửa đơn vị");

        if ($app->method() === 'GET') {
            $vars['data'] = $app->select("units", [
                "id",

                "name",
                "notes",
                "status"
            ], [
                "AND" => [
                    "id" => $vars['id'],
                    "deleted" => 0
                ],
                "LIMIT" => 1
            ]);

            if (!empty($vars['data'])) {
                $vars['data'] = $vars['data'][0];
                echo $app->render($template . '/warehouses/units-post.html', $vars, $jatbi->ajax());
            } else {
                echo $app->render($template . '/error.html', $vars, $jatbi->ajax());
            }
        } elseif ($app->method() === 'POST') {
            $app->header([
                'Content-Type' => 'application/json',
            ]);

            $data = $app->select("units", [
                "id",

                "name",
                "notes",
                "status"
            ], [
                "AND" => [
                    "id" => $vars['id'],
                    "deleted" => 0
                ],
                "LIMIT" => 1
            ]);

            if (!empty($data)) {
                $data = $data[0];
                $error = [];
                if ($app->xss($_POST['name']) == '') {
                    $error = ["status" => "error", "content" => $jatbi->lang("Vui lòng không để trống")];
                }

                if (empty($error)) {
                    $insert = [

                        "name" => $app->xss($_POST['name']),
                        "notes" => $app->xss($_POST['notes']),
                        "status" => $app->xss($_POST['status']),
                    ];
                    $app->update("units", $insert, ["id" => $data['id']]);
                    $jatbi->logs('units', 'edit', $insert);
                    echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công"), "url" => $_SERVER['HTTP_REFERER']]);
                } else {
                    echo json_encode($error);
                }
            } else {
                echo json_encode(["status" => "error", "content" => $jatbi->lang("Không tìm thấy dữ liệu")]);
            }
        }
    })->setPermissions(['units.edit']);

    $app->router("/units-deleted", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Xóa đơn vị");
        if ($app->method() === 'GET') {
            echo $app->render($template . '/common/deleted.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header([
                'Content-Type' => 'application/json',
            ]);
            $boxid = explode(',', $app->xss($_GET['box']));
            $datas = $app->select("units", "*", ["id" => $boxid, "deleted" => 0]);
            if (count($datas) > 0) {
                foreach ($datas as $data) {
                    $app->update("units", ["deleted" => 1], ["id" => $data['id']]);
                    $name[] = $data['name'];
                }
                $jatbi->logs('units', 'units-deleted', $datas);
                $jatbi->trash('/warehouses/units-restore', "Xóa đơn vị: " . implode(', ', $name), ["database" => 'customers_card', "data" => $boxid]);
                echo json_encode(['status' => 'success', "content" => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Có lỗi xẩy ra")]);
            }
        }
    })->setPermissions(['units.deleted']);



    $app->router("/units-status/{id}", 'POST', function ($vars) use ($app, $jatbi) {
        $app->header([
            'Content-Type' => 'application/json',
        ]);
        $data = $app->get("units", "*", ["id" => $vars['id'], "deleted" => 0]);
        if ($data > 1) {
            if ($data > 1) {
                if ($data['status'] === 'A') {
                    $status = "D";
                } elseif ($data['status'] === 'D') {
                    $status = "A";
                }
                $app->update("units", ["status" => $status], ["id" => $data['id']]);
                $jatbi->logs('units', 'units-status', $data);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Cập nhật thất bại"),]);
            }
        } else {
            echo json_encode(["status" => "error", "content" => $jatbi->lang("Không tìm thấy dữ liệu")]);
        }
    })->setPermissions(['units.edit']);




    //li ngọc
    $app->router('/sizes', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Li ngọc");

        if ($app->method() === 'GET') {
            echo $app->render($template . '/warehouses/sizes.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
            $statusValue = isset($_POST['status']) ? $_POST['status'] : '';
            $orderName = isset($_POST['order'][0]['name']) ? $_POST['order'][0]['name'] : 'id';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            $where = [
                "AND" => [
                    "sizes.deleted" => 0,
                ],
                "LIMIT" => [$start, $length],
                "ORDER" => [$orderName => strtoupper($orderDir)]
            ];

            if ($searchValue != '') {
                $where['AND']['OR'] = [
                    'sizes.name[~]' => $searchValue,
                    'sizes.notes[~]' => $searchValue,
                ];
            }

            if ($statusValue != '') {
                $where['AND']['sizes.status'] = $statusValue;
            }

            $countWhere = [
                "AND" => array_merge(
                    ["sizes.deleted" => 0],
                    $searchValue != '' ? [
                        "OR" => [
                            'sizes.name[~]' => $searchValue,
                            'sizes.notes[~]' => $searchValue,
                        ]
                    ] : [],
                    $statusValue != '' ? ["sizes.status" => $statusValue] : []
                )
            ];
            $count = $app->count("sizes", $countWhere);

            $datas = [];
            $app->select("sizes", [
                'sizes.id',
                'sizes.code',
                'sizes.name',
                'sizes.notes',
                'sizes.status'
            ], $where, function ($data) use (&$datas, $jatbi, $app) {
                $datas[] = [
                    "checkbox" => ($app->component("box", ["data" => $data['id'] ?? '']) ?? '<input type="checkbox">'),
                    "code" => ($data['code'] ?? ''),
                    "name" => ($data['name'] ?? ''),
                    "notes" => ($data['notes'] ?? ''),
                    "status" => ($app->component("status", [
                        "url" => "/warehouses/sizes-status/" . ($data['id'] ?? ''),
                        "data" => $data['status'] ?? '',
                        "permission" => ['sizes.edit'],
                    ])),
                    "action" => ($app->component("action", [
                        "button" => [
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Sửa"),
                                'permission' => ['sizes.edit'],
                                'action' => ['data-url' => '/warehouses/sizes-edit/' . ($data['id'] ?? ''), 'data-action' => 'modal']
                            ],
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Xóa"),
                                'permission' => ['sizes.deleted'],
                                'action' => ['data-url' => '/warehouses/sizes-deleted?box=' . ($data['id'] ?? ''), 'data-action' => 'modal']
                            ],
                        ]
                    ]))
                ];
            });

            echo json_encode(
                [
                    "draw" => $draw,
                    "recordsTotal" => $count,
                    "recordsFiltered" => $count,
                    "data" => $datas ?? []
                ],

            );
        }
    })->setPermissions(['sizes']);



    $app->router('/sizes-add', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Thêm li ngọc");

        if ($app->method() === 'GET') {
            echo $app->render($template . '/warehouses/sizes-post.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json',]);
            if (empty($_POST['name'])) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Vui lòng không để trống")]);
                return;
            }
            //   if (empty($_POST['code'])) {
            //     echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Vui lòng không để trống")]);
            //     return;
            // }

            $insert = [
                "code" => $app->xss($_POST['code']),
                "name" => $app->xss($_POST['name']),
                "notes" => $app->xss($_POST['notes']),
                "status" => $app->xss($_POST['status']),
            ];
            $app->insert("sizes", $insert);
            $jatbi->logs('sizes', 'add', $insert);
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
        }
    })->setPermissions(['sizes.add']);


    $app->router("/sizes-edit/{id}", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Sửa li ngọc");

        if ($app->method() === 'GET') {
            $vars['data'] = $app->select("sizes", [
                "id",
                "code",
                "name",
                "notes",
                "status"
            ], [
                "AND" => [
                    "id" => $vars['id'],
                    "deleted" => 0
                ],
                "LIMIT" => 1
            ]);

            if (!empty($vars['data'])) {
                $vars['data'] = $vars['data'][0];
                echo $app->render($template . '/warehouses/sizes-post.html', $vars, $jatbi->ajax());
            } else {
                echo $app->render($template . '/error.html', $vars, $jatbi->ajax());
            }
        } elseif ($app->method() === 'POST') {
            $app->header([
                'Content-Type' => 'application/json',
            ]);

            $data = $app->select("sizes", [
                "id",
                "code",
                "name",
                "notes",
                "status"
            ], [
                "AND" => [
                    "id" => $vars['id'],
                    "deleted" => 0
                ],
                "LIMIT" => 1
            ]);

            if (!empty($data)) {
                $data = $data[0];
                $error = [];
                if ($app->xss($_POST['name']) == '') {
                    $error = ["status" => "error", "content" => $jatbi->lang("Vui lòng không để trống")];
                }

                if (empty($error)) {
                    $insert = [
                        "code" => $app->xss($_POST['code']),
                        "name" => $app->xss($_POST['name']),
                        "notes" => $app->xss($_POST['notes']),
                        "status" => $app->xss($_POST['status']),
                    ];
                    $app->update("sizes", $insert, ["id" => $data['id']]);
                    $jatbi->logs('sizes', 'edit', $insert);
                    echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công"), "url" => $_SERVER['HTTP_REFERER']]);
                } else {
                    echo json_encode($error);
                }
            } else {
                echo json_encode(["status" => "error", "content" => $jatbi->lang("Không tìm thấy dữ liệu")]);
            }
        }
    })->setPermissions(['sizes.edit']);

    $app->router("/sizes-deleted", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Xóa li ngọc");
        if ($app->method() === 'GET') {
            echo $app->render($template . '/common/deleted.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header([
                'Content-Type' => 'application/json',
            ]);
            $boxid = explode(',', $app->xss($_GET['box']));
            $datas = $app->select("sizes", "*", ["id" => $boxid, "deleted" => 0]);
            if (count($datas) > 0) {
                foreach ($datas as $data) {
                    $app->update("sizes", ["deleted" => 1], ["id" => $data['id']]);
                    $name[] = $data['name'];
                }
                $jatbi->logs('sizes', 'sizes-deleted', $datas);
                $jatbi->trash('/warehouses/sizes-restore', "Xóa li ngọc: " . implode(', ', $name), ["database" => 'customers_card', "data" => $boxid]);
                echo json_encode(['status' => 'success', "content" => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Có lỗi xẩy ra")]);
            }
        }
    })->setPermissions(['sizes.deleted']);



    $app->router("/sizes-status/{id}", 'POST', function ($vars) use ($app, $jatbi) {
        $app->header([
            'Content-Type' => 'application/json',
        ]);
        $data = $app->get("sizes", "*", ["id" => $vars['id'], "deleted" => 0]);
        if ($data > 1) {
            if ($data > 1) {
                if ($data['status'] === 'A') {
                    $status = "D";
                } elseif ($data['status'] === 'D') {
                    $status = "A";
                }
                $app->update("sizes", ["status" => $status], ["id" => $data['id']]);
                $jatbi->logs('sizes', 'sizes-status', $data);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Cập nhật thất bại"),]);
            }
        } else {
            echo json_encode(["status" => "error", "content" => $jatbi->lang("Không tìm thấy dữ liệu")]);
        }
    })->setPermissions(['sizes.edit']);




    //màu sắc
    $app->router('/colors', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Màu sắc");

        if ($app->method() === 'GET') {
            echo $app->render($template . '/warehouses/colors.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
            $statusValue = isset($_POST['status']) ? $_POST['status'] : '';
            $orderName = isset($_POST['order'][0]['name']) ? $_POST['order'][0]['name'] : 'id';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            $where = [
                "AND" => [
                    "colors.deleted" => 0,
                ],
                "LIMIT" => [$start, $length],
                "ORDER" => [$orderName => strtoupper($orderDir)]
            ];

            if ($searchValue != '') {
                $where['AND']['OR'] = [
                    'colors.name[~]' => $searchValue,
                    'colors.notes[~]' => $searchValue,
                ];
            }

            if ($statusValue != '') {
                $where['AND']['colors.status'] = $statusValue;
            }

            $countWhere = [
                "AND" => array_merge(
                    ["colors.deleted" => 0],
                    $searchValue != '' ? [
                        "OR" => [
                            'colors.name[~]' => $searchValue,
                            'colors.notes[~]' => $searchValue,
                        ]
                    ] : [],
                    $statusValue != '' ? ["colors.status" => $statusValue] : []
                )
            ];
            $count = $app->count("colors", $countWhere);

            $datas = [];
            $app->select("colors", [
                'colors.id',
                'colors.code',
                'colors.name',
                'colors.notes',
                'colors.status'
            ], $where, function ($data) use (&$datas, $jatbi, $app) {
                $datas[] = [
                    "checkbox" => ($app->component("box", ["data" => $data['id'] ?? '']) ?? '<input type="checkbox">'),
                    "code" => ($data['code'] ?? ''),
                    "name" => ($data['name'] ?? ''),
                    "notes" => ($data['notes'] ?? ''),
                    "status" => ($app->component("status", [
                        "url" => "/warehouses/colors-status/" . ($data['id'] ?? ''),
                        "data" => $data['status'] ?? '',
                        "permission" => ['colors.edit'],
                    ])),
                    "action" => ($app->component("action", [
                        "button" => [
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Sửa"),
                                'permission' => ['colors.edit'],
                                'action' => ['data-url' => '/warehouses/colors-edit/' . ($data['id'] ?? ''), 'data-action' => 'modal']
                            ],
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Xóa"),
                                'permission' => ['colors.deleted'],
                                'action' => ['data-url' => '/warehouses/colors-deleted?box=' . ($data['id'] ?? ''), 'data-action' => 'modal']
                            ],
                        ]
                    ]))
                ];
            });

            echo json_encode(
                [
                    "draw" => $draw,
                    "recordsTotal" => $count,
                    "recordsFiltered" => $count,
                    "data" => $datas ?? []
                ],

            );
        }
    })->setPermissions(['colors']);




    $app->router('/colors-add', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Thêm màu sắc");

        if ($app->method() === 'GET') {
            echo $app->render($template . '/warehouses/colors-post.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json',]);
            if (empty($_POST['name'])) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Vui lòng không để trống")]);
                return;
            }
            //   if (empty($_POST['code'])) {
            //     echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Vui lòng không để trống")]);
            //     return;
            // }

            $insert = [
                "code" => $app->xss($_POST['code']),
                "name" => $app->xss($_POST['name']),
                "notes" => $app->xss($_POST['notes']),
                "status" => $app->xss($_POST['status']),
            ];
            $app->insert("colors", $insert);
            $jatbi->logs('colors', 'add', $insert);
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
        }
    })->setPermissions(['colors.add']);


    $app->router("/colors-edit/{id}", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Sửa màu sắc");

        if ($app->method() === 'GET') {
            $vars['data'] = $app->select("colors", [
                "id",
                "code",
                "name",
                "notes",
                "status"
            ], [
                "AND" => [
                    "id" => $vars['id'],
                    "deleted" => 0
                ],
                "LIMIT" => 1
            ]);

            if (!empty($vars['data'])) {
                $vars['data'] = $vars['data'][0];
                echo $app->render($template . '/warehouses/colors-post.html', $vars, $jatbi->ajax());
            } else {
                echo $app->render($template . '/error.html', $vars, $jatbi->ajax());
            }
        } elseif ($app->method() === 'POST') {
            $app->header([
                'Content-Type' => 'application/json',
            ]);

            $data = $app->select("colors", [
                "id",
                "code",
                "name",
                "notes",
                "status"
            ], [
                "AND" => [
                    "id" => $vars['id'],
                    "deleted" => 0
                ],
                "LIMIT" => 1
            ]);

            if (!empty($data)) {
                $data = $data[0];
                $error = [];
                if ($app->xss($_POST['name']) == '') {
                    $error = ["status" => "error", "content" => $jatbi->lang("Vui lòng không để trống")];
                }

                if (empty($error)) {
                    $insert = [
                        "code" => $app->xss($_POST['code']),
                        "name" => $app->xss($_POST['name']),
                        "notes" => $app->xss($_POST['notes']),
                        "status" => $app->xss($_POST['status']),
                    ];
                    $app->update("colors", $insert, ["id" => $data['id']]);
                    $jatbi->logs('colors', 'edit', $insert);
                    echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công"), "url" => $_SERVER['HTTP_REFERER']]);
                } else {
                    echo json_encode($error);
                }
            } else {
                echo json_encode(["status" => "error", "content" => $jatbi->lang("Không tìm thấy dữ liệu")]);
            }
        }
    })->setPermissions(['colors.edit']);

    $app->router("/colors-deleted", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Xóa màu sắc");
        if ($app->method() === 'GET') {
            echo $app->render($template . '/common/deleted.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header([
                'Content-Type' => 'application/json',
            ]);
            $boxid = explode(',', $app->xss($_GET['box']));
            $datas = $app->select("colors", "*", ["id" => $boxid, "deleted" => 0]);
            if (count($datas) > 0) {
                foreach ($datas as $data) {
                    $app->update("colors", ["deleted" => 1], ["id" => $data['id']]);
                    $name[] = $data['name'];
                }
                $jatbi->logs('colors', 'sizes-deleted', $datas);
                $jatbi->trash('/warehouses/colors-restore', "Xóa màu sắc: " . implode(', ', $name), ["database" => 'customers_card', "data" => $boxid]);
                echo json_encode(['status' => 'success', "content" => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Có lỗi xẩy ra")]);
            }
        }
    })->setPermissions(['colors.deleted']);

    $app->router("/colors-status/{id}", 'POST', function ($vars) use ($app, $jatbi) {
        $app->header([
            'Content-Type' => 'application/json',
        ]);
        $data = $app->get("colors", "*", ["id" => $vars['id'], "deleted" => 0]);
        if ($data > 1) {
            if ($data > 1) {
                if ($data['status'] === 'A') {
                    $status = "D";
                } elseif ($data['status'] === 'D') {
                    $status = "A";
                }
                $app->update("colors", ["status" => $status], ["id" => $data['id']]);
                $jatbi->logs('colors', 'colors-status', $data);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Cập nhật thất bại"),]);
            }
        } else {
            echo json_encode(["status" => "error", "content" => $jatbi->lang("Không tìm thấy dữ liệu")]);
        }
    })->setPermissions(['colors.edit']);




    // loại ngọc

    $app->router('/pearl', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Loại ngọc");

        if ($app->method() === 'GET') {
            echo $app->render($template . '/warehouses/pearl.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
            $statusValue = isset($_POST['status']) ? $_POST['status'] : '';
            $orderName = isset($_POST['order'][0]['name']) ? $_POST['order'][0]['name'] : 'id';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            $where = [
                "AND" => [
                    "pearl.deleted" => 0,
                ],
                "LIMIT" => [$start, $length],
                "ORDER" => [$orderName => strtoupper($orderDir)]
            ];

            if ($searchValue != '') {
                $where['AND']['OR'] = [
                    'pearl.name[~]' => $searchValue,
                    'pearl.notes[~]' => $searchValue,
                ];
            }

            if ($statusValue != '') {
                $where['AND']['pearl.status'] = $statusValue;
            }

            $countWhere = [
                "AND" => array_merge(
                    ["pearl.deleted" => 0],
                    $searchValue != '' ? [
                        "OR" => [
                            'pearl.name[~]' => $searchValue,
                            'pearl.notes[~]' => $searchValue,
                        ]
                    ] : [],
                    $statusValue != '' ? ["pearl.status" => $statusValue] : []
                )
            ];
            $count = $app->count("pearl", $countWhere);

            $datas = [];
            $app->select("pearl", [
                'pearl.id',
                'pearl.code',
                'pearl.name',
                'pearl.notes',
                'pearl.status'
            ], $where, function ($data) use (&$datas, $jatbi, $app) {
                $datas[] = [
                    "checkbox" => ($app->component("box", ["data" => $data['id'] ?? '']) ?? '<input type="checkbox">'),
                    "code" => ($data['code'] ?? ''),
                    "name" => ($data['name'] ?? ''),
                    "notes" => ($data['notes'] ?? ''),
                    "status" => ($app->component("status", [
                        "url" => "/warehouses/pearl-status/" . ($data['id'] ?? ''),
                        "data" => $data['status'] ?? '',
                        "permission" => ['pearl.edit'],
                    ])),
                    "action" => ($app->component("action", [
                        "button" => [
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Sửa"),
                                'permission' => ['pearl.edit'],
                                'action' => ['data-url' => '/warehouses/pearl-edit/' . ($data['id'] ?? ''), 'data-action' => 'modal']
                            ],
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Xóa"),
                                'permission' => ['pearl.deleted'],
                                'action' => ['data-url' => '/warehouses/pearl-deleted?box=' . ($data['id'] ?? ''), 'data-action' => 'modal']
                            ],
                        ]
                    ]))
                ];
            });

            echo json_encode(
                [
                    "draw" => $draw,
                    "recordsTotal" => $count,
                    "recordsFiltered" => $count,
                    "data" => $datas ?? []
                ],

            );
        }
    })->setPermissions(['pearl']);


    $app->router('/pearl-add', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Thêm loại ngọc");

        if ($app->method() === 'GET') {
            echo $app->render($template . '/warehouses/pearl-post.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json',]);
            if (empty($_POST['name'])) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Vui lòng không để trống")]);
                return;
            }
            //   if (empty($_POST['code'])) {
            //     echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Vui lòng không để trống")]);
            //     return;
            // }

            $insert = [
                "code" => $app->xss($_POST['code']),
                "name" => $app->xss($_POST['name']),
                "notes" => $app->xss($_POST['notes']),
                "status" => $app->xss($_POST['status']),
            ];
            $app->insert("pearl", $insert);
            $jatbi->logs('pearl', 'add', $insert);
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
        }
    })->setPermissions(['pearl.add']);


    $app->router("/pearl-edit/{id}", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Sửa loại ngọc");

        if ($app->method() === 'GET') {
            $vars['data'] = $app->select("pearl", [
                "id",
                "code",
                "name",
                "notes",
                "status"
            ], [
                "AND" => [
                    "id" => $vars['id'],
                    "deleted" => 0
                ],
                "LIMIT" => 1
            ]);

            if (!empty($vars['data'])) {
                $vars['data'] = $vars['data'][0];
                echo $app->render($template . '/warehouses/pearl-post.html', $vars, $jatbi->ajax());
            } else {
                echo $app->render($template . '/error.html', $vars, $jatbi->ajax());
            }
        } elseif ($app->method() === 'POST') {
            $app->header([
                'Content-Type' => 'application/json',
            ]);

            $data = $app->select("pearl", [
                "id",
                "code",
                "name",
                "notes",
                "status"
            ], [
                "AND" => [
                    "id" => $vars['id'],
                    "deleted" => 0
                ],
                "LIMIT" => 1
            ]);

            if (!empty($data)) {
                $data = $data[0];
                $error = [];
                if ($app->xss($_POST['name']) == '') {
                    $error = ["status" => "error", "content" => $jatbi->lang("Vui lòng không để trống")];
                }

                if (empty($error)) {
                    $insert = [
                        "code" => $app->xss($_POST['code']),
                        "name" => $app->xss($_POST['name']),
                        "notes" => $app->xss($_POST['notes']),
                        "status" => $app->xss($_POST['status']),
                    ];
                    $app->update("pearl", $insert, ["id" => $data['id']]);
                    $jatbi->logs('pearl', 'edit', $insert);
                    echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công"), "url" => $_SERVER['HTTP_REFERER']]);
                } else {
                    echo json_encode($error);
                }
            } else {
                echo json_encode(["status" => "error", "content" => $jatbi->lang("Không tìm thấy dữ liệu")]);
            }
        }
    })->setPermissions(['pearl.edit']);

    $app->router("/pearl-deleted", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Xóa loại ngọc");
        if ($app->method() === 'GET') {
            echo $app->render($template . '/common/deleted.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header([
                'Content-Type' => 'application/json',
            ]);
            $boxid = explode(',', $app->xss($_GET['box']));
            $datas = $app->select("pearl", "*", ["id" => $boxid, "deleted" => 0]);
            if (count($datas) > 0) {
                foreach ($datas as $data) {
                    $app->update("pearl", ["deleted" => 1], ["id" => $data['id']]);
                    $name[] = $data['name'];
                }
                $jatbi->logs('pearl', 'sizes-deleted', $datas);
                $jatbi->trash('/warehouses/pearl-restore', "Xóa màu sắc: " . implode(', ', $name), ["database" => 'customers_card', "data" => $boxid]);
                echo json_encode(['status' => 'success', "content" => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Có lỗi xẩy ra")]);
            }
        }
    })->setPermissions(['pearl.deleted']);

    $app->router("/pearl-status/{id}", 'POST', function ($vars) use ($app, $jatbi) {
        $app->header([
            'Content-Type' => 'application/json',
        ]);
        $data = $app->get("pearl", "*", ["id" => $vars['id'], "deleted" => 0]);
        if ($data > 1) {
            if ($data > 1) {
                if ($data['status'] === 'A') {
                    $status = "D";
                } elseif ($data['status'] === 'D') {
                    $status = "A";
                }
                $app->update("pearl", ["status" => $status], ["id" => $data['id']]);
                $jatbi->logs('pearl', 'pearl-status', $data);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Cập nhật thất bại"),]);
            }
        } else {
            echo json_encode(["status" => "error", "content" => $jatbi->lang("Không tìm thấy dữ liệu")]);
        }
    })->setPermissions(['pearl.edit']);



    //nhóm sản phẩm
    $app->router('/group', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Nhóm sản phẩm");

        if ($app->method() === 'GET') {
            echo $app->render($template . '/warehouses/group.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
            $statusValue = isset($_POST['status']) ? $_POST['status'] : '';
            $orderName = isset($_POST['order'][0]['name']) ? $_POST['order'][0]['name'] : 'id';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            $where = [
                "AND" => [
                    "products_group.deleted" => 0,
                ],
                "LIMIT" => [$start, $length],
                "ORDER" => [$orderName => strtoupper($orderDir)]
            ];

            if ($searchValue != '') {
                $where['AND']['OR'] = [
                    'products_group.name[~]' => $searchValue,
                    'products_group.notes[~]' => $searchValue,
                ];
            }

            if ($statusValue != '') {
                $where['AND']['products_group.status'] = $statusValue;
            }

            $countWhere = [
                "AND" => array_merge(
                    ["products_group.deleted" => 0],
                    $searchValue != '' ? [
                        "OR" => [
                            'products_group.name[~]' => $searchValue,
                            'products_group.notes[~]' => $searchValue,
                        ]
                    ] : [],
                    $statusValue != '' ? ["products_group.status" => $statusValue] : []
                )
            ];
            $count = $app->count("products_group", $countWhere);

            $datas = [];
            $app->select("products_group", [
                'products_group.id',
                'products_group.code',
                'products_group.name',
                'products_group.notes',
                'products_group.status'
            ], $where, function ($data) use (&$datas, $jatbi, $app) {
                $datas[] = [
                    "checkbox" => ($app->component("box", ["data" => $data['id'] ?? '']) ?? '<input type="checkbox">'),
                    "code" => ($data['code'] ?? ''),
                    "name" => ($data['name'] ?? ''),
                    "notes" => ($data['notes'] ?? ''),
                    "status" => ($app->component("status", [
                        "url" => "/warehouses/group-status/" . ($data['id'] ?? ''),
                        "data" => $data['status'] ?? '',
                        "permission" => ['group.edit'],
                    ])),
                    "action" => ($app->component("action", [
                        "button" => [
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Sửa"),
                                'permission' => ['group.edit'],
                                'action' => ['data-url' => '/warehouses/group-edit/' . ($data['id'] ?? ''), 'data-action' => 'modal']
                            ],
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Xóa"),
                                'permission' => ['group.deleted'],
                                'action' => ['data-url' => '/warehouses/group-deleted?box=' . ($data['id'] ?? ''), 'data-action' => 'modal']
                            ],
                        ]
                    ]))
                ];
            });

            echo json_encode(
                [
                    "draw" => $draw,
                    "recordsTotal" => $count,
                    "recordsFiltered" => $count,
                    "data" => $datas ?? []
                ],

            );
        }
    })->setPermissions(['group']);


    $app->router('/group-add', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Thêm nhóm sản phẩm");

        if ($app->method() === 'GET') {
            echo $app->render($template . '/warehouses/group-post.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json',]);
            if (empty($_POST['name'])) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Vui lòng không để trống")]);
                return;
            }
            //   if (empty($_POST['code'])) {
            //     echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Vui lòng không để trống")]);
            //     return;
            // }

            $insert = [
                "code" => $app->xss($_POST['code']),
                "name" => $app->xss($_POST['name']),
                "notes" => $app->xss($_POST['notes']),
                "status" => $app->xss($_POST['status']),
            ];
            $app->insert("products_group", $insert);
            $jatbi->logs('group', 'add', $insert);
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
        }
    })->setPermissions(['group.add']);


    $app->router("/group-edit/{id}", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Sửa nhóm sản phẩm");

        if ($app->method() === 'GET') {
            $vars['data'] = $app->select("products_group", [
                "id",
                "code",
                "name",
                "notes",
                "status"
            ], [
                "AND" => [
                    "id" => $vars['id'],
                    "deleted" => 0
                ],
                "LIMIT" => 1
            ]);

            if (!empty($vars['data'])) {
                $vars['data'] = $vars['data'][0];
                echo $app->render($template . '/warehouses/group-post.html', $vars, $jatbi->ajax());
            } else {
                echo $app->render($template . '/error.html', $vars, $jatbi->ajax());
            }
        } elseif ($app->method() === 'POST') {
            $app->header([
                'Content-Type' => 'application/json',
            ]);

            $data = $app->select("products_group", [
                "id",
                "code",
                "name",
                "notes",
                "status"
            ], [
                "AND" => [
                    "id" => $vars['id'],
                    "deleted" => 0
                ],
                "LIMIT" => 1
            ]);

            if (!empty($data)) {
                $data = $data[0];
                $error = [];
                if ($app->xss($_POST['name']) == '') {
                    $error = ["status" => "error", "content" => $jatbi->lang("Vui lòng không để trống")];
                }

                if (empty($error)) {
                    $insert = [
                        "code" => $app->xss($_POST['code']),
                        "name" => $app->xss($_POST['name']),
                        "notes" => $app->xss($_POST['notes']),
                        "status" => $app->xss($_POST['status']),
                    ];
                    $app->update("products_group", $insert, ["id" => $data['id']]);
                    $jatbi->logs('group', 'edit', $insert);
                    echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công"), "url" => $_SERVER['HTTP_REFERER']]);
                } else {
                    echo json_encode($error);
                }
            } else {
                echo json_encode(["status" => "error", "content" => $jatbi->lang("Không tìm thấy dữ liệu")]);
            }
        }
    })->setPermissions(['group.edit']);

    $app->router("/group-deleted", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Xóa nhóm sản phẩm");
        if ($app->method() === 'GET') {
            echo $app->render($template . '/common/deleted.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header([
                'Content-Type' => 'application/json',
            ]);
            $boxid = explode(',', $app->xss($_GET['box']));
            $datas = $app->select("products_group", "*", ["id" => $boxid, "deleted" => 0]);
            if (count($datas) > 0) {
                foreach ($datas as $data) {
                    $app->update("products_group", ["deleted" => 1], ["id" => $data['id']]);
                    $name[] = $data['name'];
                }
                $jatbi->logs('products_group', 'group-deleted', $datas);
                $jatbi->trash('/warehouses/group-restore', "Xóa nhóm sản phẩm: " . implode(', ', $name), ["database" => 'customers_card', "data" => $boxid]);
                echo json_encode(['status' => 'success', "content" => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Có lỗi xẩy ra")]);
            }
        }
    })->setPermissions(['group.deleted']);

    $app->router("/group-status/{id}", 'POST', function ($vars) use ($app, $jatbi) {
        $app->header([
            'Content-Type' => 'application/json',
        ]);
        $data = $app->get("products_group", "*", ["id" => $vars['id'], "deleted" => 0]);
        if ($data > 1) {
            if ($data > 1) {
                if ($data['status'] === 'A') {
                    $status = "D";
                } elseif ($data['status'] === 'D') {
                    $status = "A";
                }
                $app->update("products_group", ["status" => $status], ["id" => $data['id']]);
                $jatbi->logs('products_group', 'products_group-status', $data);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Cập nhật thất bại"),]);
            }
        } else {
            echo json_encode(["status" => "error", "content" => $jatbi->lang("Không tìm thấy dữ liệu")]);
        }
    })->setPermissions(['group.edit']);



    //danh mục
    $app->router('/categorys', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Danh mục");

        if ($app->method() === 'GET') {
            echo $app->render($template . '/warehouses/categorys.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
            $statusValue = isset($_POST['status']) ? $_POST['status'] : '';
            $orderName = isset($_POST['order'][0]['name']) ? $_POST['order'][0]['name'] : 'id';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            $where = [
                "AND" => [
                    "categorys.deleted" => 0,
                ],
                "LIMIT" => [$start, $length],
                "ORDER" => [$orderName => strtoupper($orderDir)]
            ];

            if ($searchValue != '') {
                $where['AND']['OR'] = [
                    'categorys.name[~]' => $searchValue,
                    'categorys.notes[~]' => $searchValue,
                ];
            }

            if ($statusValue != '') {
                $where['AND']['categorys.status'] = $statusValue;
            }

            $countWhere = [
                "AND" => array_merge(
                    ["categorys.deleted" => 0],
                    $searchValue != '' ? [
                        "OR" => [
                            'categorys.name[~]' => $searchValue,
                            'categorys.notes[~]' => $searchValue,
                        ]
                    ] : [],
                    $statusValue != '' ? ["categorys.status" => $statusValue] : []
                )
            ];
            $count = $app->count("categorys", $countWhere);

            $datas = [];
            $app->select("categorys", [
                'categorys.id',
                'categorys.code',
                'categorys.name',
                'categorys.notes',
                'categorys.status'
            ], $where, function ($data) use (&$datas, $jatbi, $app) {
                $datas[] = [
                    "checkbox" => ($app->component("box", ["data" => $data['id'] ?? '']) ?? '<input type="checkbox">'),
                    "code" => ($data['code'] ?? ''),
                    "name" => ($data['name'] ?? ''),
                    "notes" => ($data['notes'] ?? ''),
                    "status" => ($app->component("status", [
                        "url" => "/warehouses/categorys-status/" . ($data['id'] ?? ''),
                        "data" => $data['status'] ?? '',
                        "permission" => ['categorys.edit'],
                    ])),
                    "action" => ($app->component("action", [
                        "button" => [
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Sửa"),
                                'permission' => ['categorys.edit'],
                                'action' => ['data-url' => '/warehouses/categorys-edit/' . ($data['id'] ?? ''), 'data-action' => 'modal']
                            ],
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Xóa"),
                                'permission' => ['categorys.deleted'],
                                'action' => ['data-url' => '/warehouses/categorys-deleted?box=' . ($data['id'] ?? ''), 'data-action' => 'modal']
                            ],
                        ]
                    ]))
                ];
            });

            echo json_encode(
                [
                    "draw" => $draw,
                    "recordsTotal" => $count,
                    "recordsFiltered" => $count,
                    "data" => $datas ?? []
                ],

            );
        }
    })->setPermissions(['categorys']);


    $app->router("/categorys-add", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Thêm danh mục");

        if ($app->method() === 'GET') {
            $vars['data'] = [];
            $vars['categorys'] = $app->select("categorys", ["id (value)", "name (text)"], ["deleted" => 0, "status" => "A"]);
            echo $app->render($template . '/warehouses/categorys-post.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header([
                'Content-Type' => 'application/json',
            ]);

            $error = [];
            if (empty($_POST['name'])) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Vui lòng không để trống")]);
                return;
            }
            if (empty($_POST['main'])) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Vui lòng không để trống")]);
                return;
            }

            if (empty($error)) {
                $insert = [
                    "main" => $app->xss($_POST['main']),
                    "name" => $app->xss($_POST['name']),
                    "code" => $app->xss($_POST['code']),
                    "notes" => $app->xss($_POST['notes']),
                    "status" => $app->xss($_POST['status']),
                ];
                $app->insert("categorys", $insert);
                $jatbi->logs('categorys', 'add', $insert);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Thêm thành công"), "url" => $_SERVER['HTTP_REFERER']]);
            } else {
                echo json_encode($error);
            }
        }
    })->setPermissions(['categorys.add']);

    $app->router("/categorys-edit/{id}", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Sửa danh mục");

        if ($app->method() === 'GET') {
            $vars['data'] = $app->select("categorys", [
                "id",
                "main",
                "name",
                "code",
                "notes",
                "status"
            ], [
                "AND" => [
                    "id" => $vars['id'],
                    "deleted" => 0
                ],
                "LIMIT" => 1
            ]);

            if (!empty($vars['data'])) {
                $vars['data'] = $vars['data'][0];
                $vars['categorys'] = $app->select("categorys", ["id (value)", "name (text)"], ["deleted" => 0, "status" => "A"]);
                echo $app->render($template . '/warehouses/categorys-post.html', $vars, $jatbi->ajax());
            } else {
                echo $app->render($template . '/error.html', $vars, $jatbi->ajax());
            }
        } elseif ($app->method() === 'POST') {
            $app->header([
                'Content-Type' => 'application/json',
            ]);

            $data = $app->select("categorys", [
                "id",
                "main",
                "name",
                "code",
                "notes",
                "status"
            ], [
                "AND" => [
                    "id" => $vars['id'],
                    "deleted" => 0
                ],
                "LIMIT" => 1
            ]);

            if (!empty($data)) {
                $data = $data[0];
                $error = [];
                if (empty($_POST['name'])) {
                    echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Vui lòng không để trống")]);
                    return;
                }
                if (empty($_POST['main'])) {
                    echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Vui lòng không để trống")]);
                    return;
                }

                if (empty($error)) {
                    $insert = [
                        "main" => $app->xss($_POST['main']),
                        "name" => $app->xss($_POST['name']),
                        "code" => $app->xss($_POST['code']),
                        "notes" => $app->xss($_POST['notes']),
                        "status" => $app->xss($_POST['status']),
                    ];
                    $app->update("categorys", $insert, ["id" => $data['id']]);
                    $jatbi->logs('categorys', 'edit', $insert);
                    echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công"), "url" => $_SERVER['HTTP_REFERER']]);
                } else {
                    echo json_encode($error);
                }
            } else {
                echo json_encode(["status" => "error", "content" => $jatbi->lang("Không tìm thấy dữ liệu")]);
            }
        }
    })->setPermissions(['categorys.edit']);

    $app->router("/categorys-deleted", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Xóa danh mục");

        if ($app->method() === 'GET') {
            echo $app->render($template . '/common/deleted.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header([
                'Content-Type' => 'application/json',
            ]);
            $boxid = explode(',', $app->xss($_GET['box']));
            $datas = $app->select("categorys", "*", ["id" => $boxid, "deleted" => 0]);
            if (count($datas) > 0) {
                foreach ($datas as $data) {
                    $app->update("categorys", ["deleted" => 1], ["id" => $data['id']]);
                    $name[] = $data['name'];
                }
                $jatbi->logs('categorys', 'categorys-deleted', $datas);
                $jatbi->trash('/warehouses/categorys-restore', "Xóa cửa hàng: " . implode(', ', $name), ["database" => 'stores', "data" => $boxid]);
                echo json_encode(['status' => 'success', "content" => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Có lỗi xảy ra")]);
            }
        }
    })->setPermissions(['categorys.deleted']);

    $app->router("/categorys-status/{id}", 'POST', function ($vars) use ($app, $jatbi) {
        $app->header([
            'Content-Type' => 'application/json',
        ]);
        $data = $app->get("categorys", "*", ["id" => $vars['id'], "deleted" => 0]);
        if ($data > 1) {
            if ($data > 1) {
                if ($data['status'] === 'A') {
                    $status = "D";
                } elseif ($data['status'] === 'D') {
                    $status = "A";
                }
                $app->update("categorys", ["status" => $status], ["id" => $data['id']]);
                $jatbi->logs('categorys', 'warehouses-categorys-status', $data);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Cập nhật thất bại"),]);
            }
        } else {
            echo json_encode(["status" => "error", "content" => $jatbi->lang("Không tìm thấy dữ liệu")]);
        }
    })->setPermissions(['categorys.edit']);



    //kho nguyên liệu
    $app->router('/ingredient', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        if ($app->method() === 'GET') {
            $vars['title'] = $jatbi->lang("Kho nguyên liệu");
            $vars['type_options'] = array_merge(
                [['value' => '', 'text' => $jatbi->lang('Tất cả')]],
                [
                    ['value' => '1', 'text' => $jatbi->lang('Đai')],
                    ['value' => '2', 'text' => $jatbi->lang('Ngọc')],
                    ['value' => '3', 'text' => $jatbi->lang('Khác')],
                ]
            );
            $vars['pearl'] = array_merge([['value' => '', 'text' => $jatbi->lang('Tất cả')]], $app->select('pearl', ['id(value)', 'name(text)'], ['deleted' => 0, 'status' => 'A']));

            $vars['sizess'] = array_merge([['value' => '', 'text' => $jatbi->lang('Tất cả')]], $app->select('sizes', ['id(value)', 'name(text)'], ['deleted' => 0, 'status' => 'A']));
            $vars['colors'] = array_merge([['value' => '', 'text' => $jatbi->lang('Tất cả')]], $app->select('colors', ['id(value)', 'name(text)'], ['deleted' => 0, 'status' => 'A']));
            $vars['units'] = array_merge([['value' => '', 'text' => $jatbi->lang('Tất cả')]], $app->select('units', ['id(value)', 'name(text)'], ['deleted' => 0, 'status' => 'A']));
            $vars['groups'] = array_merge([['value' => '', 'text' => $jatbi->lang('Tất cả')]], $app->select('products_group', ['id(value)', 'name(text)'], ['deleted' => 0, 'status' => 'A']));
            $vars['categorys'] = array_merge([['value' => '', 'text' => $jatbi->lang('Tất cả')]], $app->select('categorys', ['id(value)', 'name(text)'], ['deleted' => 0, 'status' => 'A']));
            $vars['stores'] = array_merge([['value' => '', 'text' => $jatbi->lang('Tất cả')]], $app->select('stores', ['id(value)', 'name(text)'], ['deleted' => 0, 'status' => 'A']));
            echo $app->render($template . '/warehouses/ingredient.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);

            // --- 1. Đọc tham số ---
            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $orderName = isset($_POST['order'][0]['column']) ? $_POST['columns'][$_POST['order'][0]['column']]['name'] : 'ingredient.id';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
            $typeFilter = isset($_POST['type']) ? $_POST['type'] : '';
            $pearlFilter = isset($_POST['pearl']) ? $_POST['pearl'] : '';
            $sizesFilter = isset($_POST['sizes']) ? $_POST['sizes'] : '';
            $colorsFilter = isset($_POST['colors']) ? $_POST['colors'] : '';
            $unitsFilter = isset($_POST['units']) ? $_POST['units'] : '';
            $groupFilter = isset($_POST['group']) ? $_POST['group'] : '';
            $categorysFilter = isset($_POST['categorys']) ? $_POST['categorys'] : '';
            // $store = isset($_POST['stores']) ? $app->xss($_POST['stores']) : $accStore;
            $statusFilter = isset($_POST['status']) ? $_POST['status'] : '';



            $sosanhFilter = isset($_POST['sosanh']) ? $_POST['sosanh'] : '';
            $amountFromFilter = isset($_POST['amount_from']) ? $_POST['amount_from'] : '';
            $amountToFilter = isset($_POST['amount_to']) ? $_POST['amount_to'] : '';

            // --- 2. Xây dựng truy vấn với JOIN ---
            $joins = [
                "[>]pearl" => ["pearl" => "id"],
                "[>]sizes" => ["sizes" => "id"],
                "[>]colors" => ["colors" => "id"],
                "[>]units" => ["units" => "id"],
                "[>]products_group" => ["group" => "id"],
                "[>]categorys" => ["categorys" => "id"],
            ];

            $where = ["AND" => ["ingredient.deleted" => 0]];

            // Áp dụng bộ lọc
            if (!empty($searchValue)) {
                $where['AND']['OR'] = [
                    'ingredient.code[~]' => $searchValue,
                    'ingredient.name_ingredient[~]' => $searchValue
                ];
            }

            if (!empty($typeFilter)) {
                $where['AND']['ingredient.type'] = $typeFilter;
            }
            if (!empty($pearlFilter)) {
                $where['AND']['ingredient.pearl'] = $pearlFilter;
            }
            if (!empty($sizesFilter)) {
                $where['AND']['ingredient.sizes'] = $sizesFilter;
            }
            if (!empty($colorsFilter)) {
                $where['AND']['ingredient.colors'] = $colorsFilter;
            }
            if (!empty($unitsFilter)) {
                $where['AND']['ingredient.units'] = $unitsFilter;
            }
            if (!empty($groupFilter)) {
                $where['AND']['ingredient.group'] = $groupFilter;
            }
            if (!empty($categorysFilter)) {
                $where['AND']['ingredient.categorys'] = $categorysFilter;
            }

            //  if ($store != [0]) {
            //     $where['AND']['ingredient.stores'] = $store;
            // }

            if (!empty($statusFilter)) {
                $where['AND']['ingredient.status'] = $statusFilter;
            } else {
                $where['AND']['ingredient.status'] = ["A", "D"];
            }

            // --- Áp dụng logic lọc số lượng ---
            if (!empty($sosanhFilter)) {
                if ($sosanhFilter === '<>') {
                    // Lọc theo khoảng giá trị
                    if (!empty($amountFromFilter) && !empty($amountToFilter)) {
                        $where['AND']['ingredient.amount[<>]'] = [$amountFromFilter, $amountToFilter];
                    }
                } else {

                    if (!empty($amountFromFilter)) {
                        $where['AND']['ingredient.amount[' . $sosanhFilter . ']'] = $amountFromFilter;
                    }
                }
            }

            // --- 3. Đếm bản ghi ---
            $count = $app->count("ingredient", $joins, "ingredient.id", $where);

            // Thêm sắp xếp và phân trang
            $where['LIMIT'] = [$start, $length];
            $where['ORDER'] = [$orderName => strtoupper($orderDir)];

            // --- 4. Lấy dữ liệu chính và tính tổng cho trang hiện tại ---
            $datas = [];
            $totalAmount_page = 0;
            $totalPrice_page = 0;

            $columns = [
                "ingredient.id",
                "ingredient.type",
                "ingredient.code",
                "ingredient.name_ingredient",
                "ingredient.amount",
                "ingredient.price",
                "ingredient.status",
                "pearl.name(pearl_name)",
                "sizes.name(size_name)",
                "colors.name(color_name)",
                "units.name(unit_name)",
                "products_group.name(group_name)",
                "categorys.name(category_name)"
            ];

            $app->select("ingredient", $joins, $columns, $where, function ($data) use (&$datas, &$totalAmount_page, &$totalPrice_page, $jatbi, $app) {
                $totalAmount_page += floatval($data['amount']);
                $totalPrice_page += floatval($data['price'] ?? 0);

                $type_name = '';
                if ($data['type'] == 1)
                    $type_name = $jatbi->lang("Đai");
                elseif ($data['type'] == 2)
                    $type_name = $jatbi->lang("Ngọc");
                else
                    $type_name = $jatbi->lang("Khác");

                $thuoc_tinh_1 = '';
                $thuoc_tinh_2 = '';
                $thuoc_tinh_3 = '';
                if ($data['type'] == 2) {
                    $thuoc_tinh_1 = $data['pearl_name'];
                    $thuoc_tinh_2 = $data['size_name'];
                    $thuoc_tinh_3 = $data['color_name'];
                } elseif ($data['type'] == 1) {
                    $thuoc_tinh_1 = $data['category_name'];
                    $thuoc_tinh_2 = $data['color_name'];
                    $thuoc_tinh_3 = $data['group_name'];
                }

                $datas[] = [
                    "checkbox" => (string) ($app->component("box", ["data" => $data['id'] ?? '']) ?? ''),
                    "type" => $type_name,
                    "code" => $data['code'],
                    "name_ingredient" => $data['name_ingredient'],
                    "pearl" => (string) ($thuoc_tinh_1 ?: ''),
                    "sizes" => (string) ($thuoc_tinh_2 ?: ''),
                    "colors" => (string) ($thuoc_tinh_3 ?: ''),
                    "amount" => number_format($data['amount'] ?? 0, 1),
                    "units" => (string) ($data['unit_name'] ?: ''),
                    "price" => number_format($data['price'] ?? 0),
                    "status" => (string) ($app->component("status", ["url" => "/warehouses/ingredient-status/" . $data['id'], "data" => $data['status'], "permission" => ['ingredient.edit']]) ?? ''),
                    "action" => (string) ($app->component("action", [
                        "button" => [
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Sửa"),
                                'permission' => ['ingredient.edit'],
                                'action' => ['data-url' => '/warehouses/ingredient-edit/' . ($data['id'] ?? ''), 'data-action' => 'modal']
                            ],
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Xóa"),
                                'permission' => ['ingredient.deleted'],
                                'action' => ['data-url' => '/warehouses/ingredient-deleted?box=' . ($data['id'] ?? ''), 'data-action' => 'modal']
                            ],
                            [
                                'type' => 'link',
                                'name' => $jatbi->lang("Xem"),
                                'permission' => ['ingredient'],
                                'action' => [
                                    'href' => '/warehouses/ingredient-details/' . $data['id'],
                                ]
                            ],
                        ]
                    ])),
                ];
            });

            // --- 5. Trả về JSON ---
            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => $count,
                "recordsFiltered" => $count,
                "data" => $datas,
                "footerData" => ["totalAmount" => number_format($totalAmount_page, 1), "totalPrice" => number_format($totalPrice_page, 0)]
            ]);
        }
    })->setPermissions(['ingredient']);


    $app->router("/ingredient-add", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Thêm nguyên liệu");

        // --- XỬ LÝ GET: HIỂN THỊ FORM ---
        if ($app->method() === 'GET') {
            $vars['data'] = ['type' => 1, 'status' => 'A'];


            $mapData = function ($item) {
                return ['value' => $item['id'], 'text' => $item['code'] . ' - ' . $item['name']];
            };
            $vars['groups'] = array_map($mapData, $app->select('products_group', ["id", "name", "code"], ['deleted' => 0, 'status' => 'A']));
            $vars['categorys'] = array_map($mapData, $app->select('categorys', ["id", "name", "code"], ['deleted' => 0, 'status' => 'A']));
            $vars['colors'] = array_map($mapData, $app->select('colors', ["id", "name", "code"], ['deleted' => 0, 'status' => 'A']));
            $vars['pearls'] = array_map($mapData, $app->select('pearl', ["id", "name", "code"], ['deleted' => 0, 'status' => 'A']));

            $vars['sizes'] = array_map($mapData, $app->select('sizes', ["id", "name", "code"], ['deleted' => 0, 'status' => 'A']));
            $vars['units'] = $app->select('units', ["id (value)", "name (text)"], ['deleted' => 0, 'status' => 'A']);

            echo $app->render($template . '/warehouses/ingredient-post.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);
            $error = [];
            $type = $app->xss($_POST['type'] ?? '');
            $post = array_map([$app, 'xss'], $_POST);


            $required_fields = [
                '1' => ['categorys', 'group', 'number', 'colors', 'notes'],
                '2' => ['sizes', 'pearl', 'colors1', 'quality', 'notes'],
                '3' => ['number1', 'group1', 'ingredient1', 'notes']
            ];
            if (!isset($required_fields[$type])) {
                $error = ['status' => 'error', 'content' => $jatbi->lang('Loại nguyên liệu không hợp lệ')];
            } else {
                foreach ($required_fields[$type] as $field) {
                    if (empty($post[$field])) {
                        $error = ['status' => 'error', 'content' => $jatbi->lang('Vui lòng điền đầy đủ thông tin')];
                        break;
                    }
                }
            }
            if (empty($error)) {
                $conditions = ['deleted' => 0];
                if ($type == 1) {
                    $conditions += ["group" => $post['group'], "categorys" => $post['categorys'], "number" => $post['number'], "colors" => $post['colors']];
                } elseif ($type == 2) {
                    $conditions += ["sizes" => $post['sizes'], "pearl" => $post['pearl'], "colors" => $post['colors1'], "quality" => $post['quality']];
                } elseif ($type == 3) {
                    $conditions += ["number" => $post['number1'], "group" => $post['group1']];
                }

                if ($app->has("ingredient", $conditions)) {
                    $error = ['status' => 'error', 'content' => $jatbi->lang('Nguyên liệu đã tồn tại')];
                }
            }

            if (empty($error)) {
                $code = '';
                $number = '';
                $name = '';

                if ($type == 1) {
                    $getcolor = $app->get("colors", "code", ["id" => $post['colors']]);
                    $getgroup = $app->get("products_group", "code", ["id" => $post['group']]);
                    $getcategorys = $app->get("categorys", "code", ["id" => $post['categorys']]);
                    $code = $getgroup . $getcategorys . $post['number'] . $getcolor;
                    $number = $post['number'];
                } elseif ($type == 2) {
                    $color = $app->get('colors', "code", ['id' => $post['colors1']]);
                    $size = $app->get('sizes', "code", ['id' => $post['sizes']]);
                    $pearl = $app->get('pearl', "code", ['id' => $post['pearl']]);
                    $code = $pearl . $color . $size . $post['quality'];
                    $number = $post['quality'];
                } elseif ($type == 3) {
                    $getgroup = $app->get('products_group', "code", ['id' => $post['group1']]);
                    $code = $getgroup . $post['number1'];
                    $number = $post['number1'];
                    $name = $post['ingredient1'];
                }

                $user_id = $app->getSession("accounts")['id'] ?? 0;

                $insert = [
                    "type" => $type,
                    "number" => $number,
                    "code" => $code,
                    "name_ingredient" => $name,
                    "categorys" => ($type == 1) ? $post['categorys'] : null,
                    "group" => ($type == 1) ? $post['group'] : (($type == 3) ? $post['group1'] : null),
                    "quality" => ($type == 2) ? $post['quality'] : null,
                    "colors" => ($type == 2) ? $post['colors1'] : (($type == 1) ? $post['colors'] : null),
                    "pearl" => ($type == 2) ? $post['pearl'] : null,
                    "sizes" => ($type == 2) ? $post['sizes'] : null,
                    "price" => str_replace(',', '', $post['price'] ?? 0),
                    "cost" => str_replace(',', '', $post['cost'] ?? 0),
                    "units" => $post['units'] ?? null,
                    "notes" => $post['notes'] ?? '',
                    "active" => $jatbi->active(32),
                    "date" => date('Y-m-d H:i:s'),
                    "status" => $post['status'] ?? 'A',
                    "user" => $user_id,
                ];


                $app->insert("ingredient", $insert);


                $new_ingredient_id = $app->id();


                $insert['id'] = $new_ingredient_id;

                $jatbi->logs('ingredient', 'add', $insert, $user_id);

                echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Thêm mới thành công'), 'url' => 'auto']);
            } else {
                echo json_encode($error);
            }
        }
    })->setPermissions(['ingredient.add']);


    $app->router("/ingredient-edit/{id}", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $id = $vars['id'] ?? 0;
        $vars['title'] = $jatbi->lang("Sửa nguyên liệu");


        if ($app->method() === 'GET') {
            $vars['data'] = $app->get("ingredient", "*", ["id" => $id, "deleted" => 0]);
            if (!$vars['data']) {
                header("HTTP/1.0 404 Not Found");
                exit;
            }

            $mapData = function ($item) {
                return ['value' => $item['id'], 'text' => $item['code'] . ' - ' . $item['name']];
            };

            $vars['groups'] = array_map($mapData, $app->select('products_group', ["id", "name", "code"], ['deleted' => 0, 'status' => 'A']));
            $vars['categorys'] = array_map($mapData, $app->select('categorys', ["id", "name", "code"], ['deleted' => 0, 'status' => 'A']));
            $vars['colors'] = array_map($mapData, $app->select('colors', ["id", "name", "code"], ['deleted' => 0, 'status' => 'A']));
            $vars['pearls'] = array_map($mapData, $app->select('pearl', ["id", "name", "code"], ['deleted' => 0, 'status' => 'A']));
            $vars['sizes'] = array_map($mapData, $app->select('sizes', ["id", "name", "code"], ['deleted' => 0, 'status' => 'A']));
            $vars['units'] = $app->select('units', ["id (value)", "name (text)"], ['deleted' => 0, 'status' => 'A']);

            echo $app->render($template . '/warehouses/ingredient-post.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            // Lấy dữ liệu cũ để so sánh và ghi log
            $old_data = $app->get("ingredient", "*", ["id" => $id, "deleted" => 0]);
            if (!$old_data) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Không tìm thấy dữ liệu')]);
                return;
            }

            $error = [];
            $type = $app->xss($_POST['type'] ?? '');
            $post = array_map([$app, 'xss'], $_POST);


            $required_fields = [
                '1' => ['categorys', 'group', 'number', 'colors', 'notes'],
                '2' => ['sizes', 'pearl', 'colors1', 'quality', 'notes'],
                '3' => ['number1', 'group1', 'ingredient1', 'notes']
            ];
            if (!isset($required_fields[$type])) {
                $error = ['status' => 'error', 'content' => $jatbi->lang('Loại nguyên liệu không hợp lệ')];
            } else {
                foreach ($required_fields[$type] as $field) {
                    if (empty($post[$field])) {
                        $error = ['status' => 'error', 'content' => $jatbi->lang('Vui lòng điền đầy đủ thông tin')];
                        break;
                    }
                }
            }


            if (empty($error)) {
                $conditions = ["deleted" => 0, "id[!]" => $id];
                if ($type == 1)
                    $conditions += ["group" => $post['group'], "categorys" => $post['categorys'], "number" => $post['number'], "colors" => $post['colors']];
                elseif ($type == 2)
                    $conditions += ["sizes" => $post['sizes'], "pearl" => $post['pearl'], "colors" => $post['colors1'], "quality" => $post['quality']];
                elseif ($type == 3)
                    $conditions += ["number" => $post['number1'], "group" => $post['group1']];

                if ($app->has("ingredient", $conditions)) {
                    $error = ['status' => 'error', 'content' => $jatbi->lang('Nguyên liệu đã tồn tại')];
                }
            }


            if (empty($error)) {
                $code = '';
                $number = '';
                $name = '';
                if ($type == 1) {
                    $getcolor = $app->get("colors", "code", ["id" => $post['colors']]);
                    $getgroup = $app->get("products_group", "code", ["id" => $post['group']]);
                    $getcategorys = $app->get("categorys", "code", ["id" => $post['categorys']]);
                    $code = $getgroup . $getcategorys . $post['number'] . $getcolor;
                    $number = $post['number'];
                } elseif ($type == 2) {
                    $color = $app->get('colors', "code", ['id' => $post['colors1']]);
                    $size = $app->get('sizes', "code", ['id' => $post['sizes']]);
                    $pearl = $app->get('pearl', "code", ['id' => $post['pearl']]);
                    $code = $pearl . $color . $size . $post['quality'];
                    $number = $post['quality'];
                } elseif ($type == 3) {
                    $getgroup = $app->get('products_group', "code", ['id' => $post['group1']]);
                    $code = $getgroup . $post['number1'];
                    $number = $post['number1'];
                    $name = $post['ingredient1'];
                }

                $user_id = $app->getSession("accounts")['id'] ?? 0;

                $update = [
                    "type" => $type,
                    "number" => $number,
                    "code" => $code,
                    "name_ingredient" => $name,
                    "categorys" => ($type == 1) ? $post['categorys'] : null,
                    "group" => ($type == 1) ? $post['group'] : (($type == 3) ? $post['group1'] : null),
                    "quality" => ($type == 2) ? $post['quality'] : null,
                    "colors" => ($type == 2) ? $post['colors1'] : (($type == 1) ? $post['colors'] : null),
                    "pearl" => ($type == 2) ? $post['pearl'] : null,
                    "sizes" => ($type == 2) ? $post['sizes'] : null,
                    "price" => str_replace(',', '', $post['price'] ?? 0),
                    "cost" => str_replace(',', '', $post['cost'] ?? 0),
                    "units" => $post['units'] ?? null,
                    "notes" => $post['notes'] ?? '',
                    "status" => $post['status'] ?? 'A',
                ];

                $app->update("ingredient", $update, ["id" => $id]);


                $jatbi->logs('ingredient', 'edit', [$update, $old_data], $user_id);

                echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Cập nhật thành công'), 'url' => 'auto']);
            } else {
                echo json_encode($error);
            }
        }
    })->setPermissions(['ingredient.edit']);



    $app->router("/ingredient-deleted", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $template) {
        $vars['title'] = $jatbi->lang("Xóa nguyên liệu");

        if ($app->method() === 'GET') {
            echo $app->render($setting['template'] . '/common/deleted.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header([
                'Content-Type' => 'application/json',
            ]);
            $boxid = explode(',', $app->xss($_GET['box']));
            $datas = $app->select("ingredient", "*", ["id" => $boxid, "deleted" => 0]);

            if (count($datas) > 0) {
                foreach ($datas as $data) {
                    $app->update("ingredient", ["deleted" => 1], ["id" => $data['id']]);
                    $name[] = $data['code'];
                }

                $user_id = $app->getSession("accounts")['id'] ?? 0;


                $jatbi->logs('ingredient', 'deleted', $datas, $user_id);

                $jatbi->trash('/warehouses/ingredient-restore', "Xóa nguyên liệu: " . implode(', ', $name), ["database" => 'ingredient', "data" => $boxid]);

                echo json_encode(['status' => 'success', "content" => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Có lỗi xảy ra")]);
            }
        }
    })->setPermissions(['ingredient.deleted']);

    $app->router("/ingredient-status/{id}", 'POST', function ($vars) use ($app, $jatbi) {
        $app->header([
            'Content-Type' => 'application/json',
        ]);
        $data = $app->get("ingredient", "*", ["id" => $vars['id'], "deleted" => 0]);
        if ($data > 1) {
            if ($data > 1) {
                if ($data['status'] === 'A') {
                    $status = "D";
                } elseif ($data['status'] === 'D') {
                    $status = "A";
                }
                $app->update("ingredient", ["status" => $status], ["id" => $data['id']]);
                $jatbi->logs('ingredient', 'warehouses-ingredient-status', $data);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Cập nhật thất bại"),]);
            }
        } else {
            echo json_encode(["status" => "error", "content" => $jatbi->lang("Không tìm thấy dữ liệu")]);
        }
    })->setPermissions(['ingredient.edit']);



    //lỗi sp
    $app->router('/list_products_errors', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template, $accStore, $stores) {
        if ($app->method() === 'GET') {
            $vars['title'] = $jatbi->lang("Kho hàng lỗi");

            // Lấy thông tin cửa hàng của tài khoản từ session để giới hạn bộ lọc
            $session = $app->getSession("accounts");
            $account = isset($session['id']) ? $app->get("accounts", ["stores"], ["id" => $session['id']]) : null;
            array_unshift($stores, [
                'value' => '',
                'text' => $jatbi->lang('Tất cả')
            ]);
            $vars['stores'] = $stores;

            echo $app->render($template . '/warehouses/list_products_errors.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);

            // --- 1. Đọc tham số ---
            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
            $orderName = isset($_POST['order'][0]['column']) ? $_POST['columns'][$_POST['order'][0]['column']]['name'] : 'products.id';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            // Lấy giá trị từ các bộ lọc
            $storesFilter = isset($_POST['stores']) ? $_POST['stores'] : '';
            $branchFilter = isset($_POST['branch']) ? $_POST['branch'] : '';
            $dateFilter = isset($_POST['date']) ? $_POST['date'] : '';

            // Lấy thông tin cửa hàng của tài khoản từ session để áp dụng logic lọc
            $session = $app->getSession("accounts");
            $account = isset($session['id']) ? $app->get("accounts", ["stores"], ["id" => $session['id']]) : null;
            $store = isset($_POST['stores']) ? $app->xss($_POST['stores']) : $accStore;

            // --- 2. Xây dựng truy vấn với JOIN ---
            $joins = [
                "[>]units" => ["units" => "id"],
                "[>]categorys" => ["categorys" => "id"],
                "[>]vendors" => ["vendor" => "id"],
                "[>]stores" => ["stores" => "id"],
                "[>]branch" => ["branch" => "id"],
                "[>]accountants_code (tkno_info)" => ["tkno" => "id"],
                "[>]accountants_code (tkco_info)" => ["tkco" => "id"],
            ];

            $where = [
                "AND" => [
                    "products.deleted" => 0,
                    "products.amount_error[>]" => 0
                ],
            ];

            // Áp dụng bộ lọc
            if (!empty($searchValue)) {
                $where['AND']['OR'] = [
                    'products.name[~]' => $searchValue,
                    'products.code[~]' => $searchValue,
                ];
            }
            if (!empty($branchFilter)) {
                $where['AND']['products.branch'] = $branchFilter;
            }

            if (!empty($branchFilter)) {
                $where['AND']['products.stores'] = $storesFilter;
            }

            // Logic lọc theo cửa hàng dựa trên quyền của người dùng
            if ($store && $store !== '') {
                $where['AND']['products.stores'] = is_array($store) ? $store : [$store];
            }
            if (!empty($dateFilter)) {
                $date_parts = explode(' - ', $dateFilter);
                if (count($date_parts) == 2) {
                    $date_from = date('Y-m-d 00:00:00', strtotime(str_replace('/', '-', trim($date_parts[0]))));
                    $date_to = date('Y-m-d 23:59:59', strtotime(str_replace('/', '-', trim($date_parts[1]))));
                    $where['AND']['products.date[<>]'] = [$date_from, $date_to];
                }
            }

            // --- 3. Đếm bản ghi ---
            $count = $app->count("products", $joins, "products.id", $where);

            // Thêm sắp xếp và phân trang
            $where['ORDER'] = [$orderName => strtoupper($orderDir)];
            $where['LIMIT'] = [$start, $length];

            // --- 4. Lấy dữ liệu chính và tính tổng cho trang hiện tại ---
            $datas = [];
            $totalAmountError_page = 0;

            $columns = [
                "products.id",
                "products.code",
                "products.name",
                "products.amount_error",
                "products.price",
                "products.notes",
                "products.images",
                "products.active",
                "units.name(unit_name)",
                "categorys.name(category_name)",
                "vendors.name(vendor_name)",
                "stores.name(store_name)",
                "branch.name(branch_name)",
                "tkno_info.code(tkno_code)",
                "tkno_info.name(tkno_name)",
                "tkco_info.code(tkco_code)",
                "tkco_info.name(tkco_name)",
            ];


            $app->select("products", $joins, $columns, $where, function ($data) use (&$datas, &$totalAmountError_page, $jatbi, $app, $template) {
                $totalAmountError_page += floatval($data['amount_error']);
                $action_buttons = [];

                if ($data['amount_error'] > 0) {
                    $action_buttons[] = [
                        'type' => 'button',
                        'name' => $jatbi->lang("Chuyển"),
                        'action' => ['data-url' => '/warehouses/product-remove/' . $data['id'], 'data-action' => 'modal']
                    ];
                    $action_buttons[] = [
                        'type' => 'button',
                        'name' => $jatbi->lang("Hủy"),
                        'action' => ['data-url' => '/warehouses/product-cancel/' . $data['id'], 'data-action' => 'modal']
                    ];
                } else {
                    $action_buttons[] = [
                        'type' => 'button',
                        'name' => $jatbi->lang("Chuyển"),
                        'class' => 'disabled'
                    ];
                    $action_buttons[] = [
                        'type' => 'button',
                        'name' => $jatbi->lang("Hủy"),
                        'class' => 'disabled'
                    ];
                }
                $datas[] = [
                    "images" => '<img src="https://chart.googleapis.com/chart?chs=100x100&cht=qr&chl=' . ($data['active'] ?? '') . '" class="w-100 rounded-3">',
                    "code" => $data['code'],
                    "name" => $data['name'],
                    "amount_error" => number_format($data['amount_error'], 1),
                    "units" => $data['unit_name'],
                    "price" => number_format($data['price'] ?? 0),
                    "categorys" => $data['category_name'],
                    "vendor" => $data['vendor_name'],
                    "notes" => $data['notes'],
                    "tkno" => ($data['tkno_code'] ? $data['tkno_code'] . ' ' . $data['tkno_name'] : ''),
                    "tkco" => ($data['tkco_code'] ? $data['tkco_code'] . ' ' . $data['tkco_name'] : ''),
                    "stores" => $data['store_name'],
                    "branch" => $data['branch_name'],
                    "action" => $app->component("action", [
                        "button" => $action_buttons
                    ]),
                ];
            });

            // --- 5. Trả về JSON ---
            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => $count,
                "recordsFiltered" => $count,
                "data" => $datas,
                "footerData" => [
                    "totalAmountError" => number_format($totalAmountError_page, 1)
                ]
            ]);
        }
    })->setPermissions(['list_products_errors']);


    // $app->router('/products', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting) {
    //     if ($app->method() === 'GET') {
    //         $vars['title'] = $jatbi->lang("Kho thành phẩm");
    //         $vars['stores'] = $app->select("stores", ['id', 'name'], ["deleted" => 0, "status" => 'A']);
    //         $vars['branchs'] = $app->select("branch", ['id', 'name'], ["deleted" => 0, "status" => 'A']);
    //         $vars['categorys'] = $app->select("categorys", ['id', 'name'], ["deleted" => 0, "status" => 'A']);
    //         $vars['units'] = $app->select("units", ['id', 'name'], ["deleted" => 0, "status" => 'A']);
    //         $vars['groups'] = $app->select("products_group", ['id', 'name'], ["deleted" => 0, "status" => 'A']); // Sửa lại tên bảng groups
    //         echo $app->render($template . '/warehouses/products.html', $vars);

    //     } elseif ($app->method() === 'POST') {
    //         $app->header(['Content-Type' => 'application/json; charset=utf-8']);

    //         // --- 1. Đọc tham số ---
    //         $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
    //         $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
    //         $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
    //         $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
    //         $orderName = isset($_POST['order'][0]['column']) ? $_POST['columns'][$_POST['order'][0]['column']]['name'] : 'products.id';
    //         $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

    //         // Lấy giá trị từ các bộ lọc
    //         $filter_categorys = isset($_POST['categorys']) ? $_POST['categorys'] : '';
    //         $filter_branch = isset($_POST['branch']) ? $_POST['branch'] : '';
    //         $filter_units = isset($_POST['units']) ? $_POST['units'] : '';
    //         $filter_status = isset($_POST['status']) ? $_POST['status'] : '';
    //         $filter_groups = isset($_POST['groups']) ? $_POST['groups'] : '';

    //         // --- 2. Xây dựng truy vấn với JOIN ---
    //         $joins = [
    //             "[>]units" => ["units" => "id"],
    //             "[>]categorys" => ["categorys" => "id"],
    //             "[>]vendors" => ["vendor" => "id"],
    //             "[>]stores" => ["stores" => "id"],
    //             "[>]branch" => ["branch" => "id"],
    //             "[>]products_group" => ["group" => "id"],
    //             "[>]accountants_code (tkno_info)" => ["tkno" => "id"],
    //             "[>]accountants_code (tkco_info)" => ["tkco" => "id"],
    //         ];

    //         $where = [
    //             "AND" => [
    //                 "products.deleted" => 0,
    //                 "products.type" => [1, 2],
    //             ],
    //         ];

    //         // Áp dụng bộ lọc
    //         if (!empty($searchValue)) {
    //             $where["AND"]["OR"] = [
    //                 "products.name[~]" => $searchValue,
    //                 "products.code[~]" => $searchValue,
    //             ];
    //         }
    //         if ($filter_categorys !== '') {
    //             $where["AND"]["products.categorys"] = $filter_categorys;
    //         }
    //         if ($filter_branch !== '') {
    //             $where["AND"]["products.branch"] = $filter_branch;
    //         }
    //         if ($filter_units !== '') {
    //             $where["AND"]["products.units"] = $filter_units;
    //         }
    //         if ($filter_groups !== '') {
    //             $where["AND"]["products.group"] = $filter_groups;
    //         }
    //         if ($filter_status !== '') {
    //             $where["AND"]["products.status"] = $filter_status;
    //         } else {
    //             $where["AND"]["products.status"] = ["A", "D"];
    //         }

    //         // --- 3. Đếm bản ghi ---
    //         $totalRecords = $app->count("products", ["deleted" => 0, "type" => [1, 2]]);
    //         $filteredRecords = $app->count("products", $joins, "products.id", $where);

    //         // Thêm sắp xếp và phân trang
    //         $where["ORDER"] = [$orderName => strtoupper($orderDir)];
    //         $where["LIMIT"] = [$start, $length];

    //         // --- 4. Lấy dữ liệu chính và tính tổng ---
    //         $datas = [];
    //         $sum_sl = 0;
    //         $sum_price = 0;

    //         $columns = [
    //             "products.id",
    //             "products.code",
    //             "products.name",
    //             "products.amount",
    //             "products.price",
    //             "products.status",
    //             "products.amount_status",
    //             "products.notes",
    //             "products.images",
    //             "units.name(unit_name)",
    //             "categorys.name(category_name)",
    //             "vendors.name(vendor_name)",
    //             "stores.name(store_name)",
    //             "branch.name(branch_name)",
    //             "tkno_info.code(tkno_code)",
    //             "tkno_info.name(tkno_name)",
    //             "tkco_info.code(tkco_code)",
    //             "tkco_info.name(tkco_name)",
    //         ];

    //         $app->select("products", $joins, $columns, $where, function ($data) use (&$datas, &$sum_price, &$sum_sl, $jatbi, $app, $setting) {
    //             $sum_sl += floatval($data['amount']);
    //             $sum_price += floatval($data['price']);

    //             $datas[] = [
    //                 "checkbox" => $app->component("box", ["data" => $data['id']]),
    //                 "qr_code" => '<img src="https://chart.googleapis.com/chart?chs=100x100&cht=qr&chl=' . ($data['code'] ?? '') . '&choe=UTF-8" class="w-100 rounded-3">',
    //                 "images" => '<img src="/' . ($setting['upload']['images']['products']['url'] ?? '') . 'thumb/' . ($data['images'] ?? 'no-image.png') . '" class="border border-light rounded-3 shadow-sm w-100">',
    //                 "code" => $data['code'],
    //                 "name" => $data['name'],
    //                 "amount" => '<span class="fw-bold text-primary">' . number_format($data['amount'], 2) . '</span>',
    //                 "units" => $data['unit_name'],
    //                 "price" => number_format($data['price'] ?? 0),
    //                 "categorys" => $data['category_name'],
    //                 "vendor" => $data['vendor_name'],
    //                 "status" => '<div class="form-check form-switch"><input class="form-check-input update-status" type="checkbox" ' . ($data['status'] == 'A' ? 'checked' : '') . ' data-status="/warehouses/products-status/' . $data['id'] . '/"><label class="form-check-label"></label></div>',
    //                 "amount_status" => '<div class="form-check form-switch"><input class="form-check-input update-status" type="checkbox" ' . ($data['amount_status'] == '1' ? 'checked' : '') . ' data-status="/warehouses/amount_status/' . $data['id'] . '/"><label class="form-check-label"></label></div>',
    //                 "notes" => $data['notes'],
    //                 "tkno" => ($data['tkno_code'] ?? '') . ' ' . ($data['tkno_name'] ?? ''),
    //                 "tkco" => ($data['tkco_code'] ?? '') . ' ' . ($data['tkco_name'] ?? ''),
    //                 "stores" => $data['store_name'],
    //                 "branch" => $data['branch_name'],
    //                 "action" => $app->component("action", [
    //                     "button" => [
    //                         ['type' => 'button', 'name' => $jatbi->lang("Sửa"), 'permission' => ['products.edit'], 'action' => ['data-url' => '/warehouses/products-edit/' . $data['id'], 'data-action' => 'modal']],
    //                         ['type' => 'button', 'name' => $jatbi->lang("Xóa"), 'permission' => ['products.delete'], 'action' => ['data-url' => '/warehouses/products-delete?box=' . $data['id'], 'data-action' => 'modal']],
    //                     ]
    //                 ]),
    //             ];
    //         });

    //         // --- 5. Trả về JSON ---
    //         echo json_encode([
    //             "draw" => $draw,
    //             "recordsTotal" => $totalRecords,
    //             "recordsFiltered" => $filteredRecords,
    //             "data" => $datas,
    //             "footerData" => [
    //                 "sum_sl" => number_format($sum_sl),
    //                 "sum_price" => number_format($sum_price)
    //             ]
    //         ]);
    //     }
    // })->setPermissions(['products']);


    $app->router('/products', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting, $accStore,$template, $stores) {
        if ($app->method() === 'GET') {
            $vars['title'] = $jatbi->lang("Kho thành phẩm");
            array_unshift($stores, [
                'value' => '',
                'text' => $jatbi->lang('Tất cả')
            ]);
            $vars['stores'] = $stores;
            $vars['categorys'] = array_merge([['value' => '', 'text' => $jatbi->lang('Tất cả')]], $app->select('categorys', ['id(value)', 'name(text)'], ['deleted' => 0, 'status' => 'A']));
            $vars['groups'] = array_merge([['value' => '', 'text' => $jatbi->lang('Tất cả')]], $app->select('groups', ['id(value)', 'name(text)'], ['deleted' => 0, 'status' => 'A']));
            $vars['branchs'] = $app->select("branch", ['id (value)', 'name (text)'], ["deleted" => 0, "status" => 'A']);
            $vars['units'] = array_merge([['value' => '', 'text' => $jatbi->lang('Tất cả')]], $app->select('units', ['id(value)', 'name(text)'], ['deleted' => 0, 'status' => 'A']));


            echo $app->render($template . '/warehouses/products.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);

            // --- 1. Đọc tham số ---
            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
            $orderName = isset($_POST['order'][0]['column']) ? $_POST['columns'][$_POST['order'][0]['column']]['name'] : 'products.id';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            $filter_categorys = isset($_POST['categorys']) ? $_POST['categorys'] : '';
            $filter_branch = isset($_POST['branch']) ? $_POST['branch'] : '';
            $filter_units = isset($_POST['units']) ? $_POST['units'] : '';
            $filter_status = isset($_POST['status']) ? $_POST['status'] : '';
            $filter_groups = isset($_POST['groups']) ? $_POST['groups'] : '';
            $store = isset($_POST['stores']) ? $app->xss($_POST['stores']) : $accStore;


            $sosanhFilter = isset($_POST['sosanh']) ? $_POST['sosanh'] : '';
            $amountFromFilter = isset($_POST['amount_from']) ? $_POST['amount_from'] : '';
            $amountToFilter = isset($_POST['amount_to']) ? $_POST['amount_to'] : '';

            // --- 2. Xây dựng truy vấn với WHERE giống customers ---

            $where = [
                "AND" => [
                    "products.deleted" => 0,
                    "products.type" => [1, 2],
                ],
                "LIMIT" => [$start, $length],
                "ORDER" => [$orderName => strtoupper($orderDir)],
            ];

            if (!empty($searchValue)) {
                $where["AND"]["OR"] = [
                    "products.name[~]" => $searchValue,
                    "products.code[~]" => $searchValue,
                ];
            }
            if (!empty($filter_categorys)) {
                $where["AND"]["products.categorys"] = $filter_categorys;
            }
            if (!empty($filter_branch) && $stores = null) {
                $where["AND"]["products.branch"] = $filter_branch;
            }
            if (!empty($filter_units)) {
                $where["AND"]["products.units"] = $filter_units;
            }
            if (!empty($filter_groups)) {
                $where["AND"]["products.group"] = $filter_groups;
            }
            if (!empty($filter_status)) {
                $where["AND"]["products.status"] = $filter_status;
            }
            if ($store && $store !== '') {
                $where['AND']['products.stores'] = is_array($store) ? $store : [$store];
            }

            if (!empty($sosanhFilter)) {
                if ($sosanhFilter === '<>') {

                    if (!empty($amountFromFilter) && !empty($amountToFilter)) {

                        $where['AND']['products.amount[<>]'] = [$amountFromFilter, $amountToFilter];
                    }
                } else {
                    if (!empty($amountFromFilter)) {

                        $where['AND']['products.amount[' . $sosanhFilter . ']'] = $amountFromFilter;
                    }
                }
            }
            $datas = [];
            $sum_sl = 0;
            $sum_price = 0;

            $columns = [
                "products.id",
                "products.code",
                "products.name",
                "products.amount",
                "products.price",
                "products.status",
                "products.amount_status",
                "products.notes",
                "products.categorys",
                "products.branch",
                "products.units",
                "products.group",
                "products.images",
                "units.name(unit_name)",
                "categorys.name(category_name)",
                "vendors.name(vendor_name)",
                "stores.name(store_name)",
                "branch.name(branch_name)",
                "tkno_info.code(tkno_code)",
                "tkno_info.name(tkno_name)",
                "tkco_info.code(tkco_code)",
                "tkco_info.name(tkco_name)",
            ];

            $joins = [
                "[>]units" => ["units" => "id"],
                "[>]categorys" => ["categorys" => "id"],
                "[>]vendors" => ["vendor" => "id"],
                "[>]stores" => ["stores" => "id"],
                "[>]branch" => ["branch" => "id"],
                "[>]products_group" => ["group" => "id"],
                "[>]accountants_code (tkno_info)" => ["tkno" => "id"],
                "[>]accountants_code (tkco_info)" => ["tkco" => "id"],
            ];

            $count = $app->count("products", $joins, "products.id", $where);

            // Sử dụng cùng điều kiện WHERE cho select
            $app->select("products", $joins, $columns, $where, function ($data) use (&$datas, &$sum_price, &$sum_sl, $jatbi, $app, $setting) {
                $sum_sl += floatval($data['amount']);
                $sum_price += floatval($data['price']);

                $datas[] = [
                    "checkbox" => $app->component("box", ["data" => $data['id']]),
                    "qr_code" => '<img src="https://chart.googleapis.com/chart?chs=100x100&cht=qr&chl=' . ($data['code'] ?? '') . '&choe=UTF-8" class="w-100 rounded-3">',
                    "images" => '<img src="/' . ($setting['upload']['images']['products']['url'] ?? '') . 'thumb/' . ($data['images'] ?? 'no-image.png') . '" class="border border-light rounded-3 shadow-sm w-100">',
                    "code" => $data['code'],
                    "name" => $data['name'],
                    "amount" => '<span class="fw-bold text-primary">' . number_format($data['amount'], 2) . '</span>',
                    "units" => $data['unit_name'],
                    "price" => number_format($data['price'] ?? 0),
                    "categorys" => $data['category_name'],
                    "vendor" => $data['vendor_name'],
                    "status" => $app->component("status", ["url" => "/warehouses/products-status/" . $data['id'], "data" => $data['status'], "permission" => ['products.edit']]),
                    "amount_status" => $app->component("amount_status", ["url" => "/warehouses/products_amount_status/" . $data['id'], "data" => $data['amount_status'], "permission" => ['products.edit']]),
                    "notes" => $data['notes'],
                    "tkno" => ($data['tkno_code'] ?? '') . ' ' . ($data['tkno_name'] ?? ''),
                    "tkco" => ($data['tkco_code'] ?? '') . ' ' . ($data['tkco_name'] ?? ''),
                    "stores" => $data['store_name'],
                    "branch" => $data['branch_name'],
                    "action" => $app->component("action", [
                        "button" => [
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Sửa"),
                                'permission' => ['products.edit'],
                                'action' => ['data-url' => '/warehouses/products-edit/' . $data['id'], 'data-action' => 'modal']
                            ],
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("QR"),
                                'permission' => ['products'],
                                'action' => ['data-url' => '/warehouses/products-barcode/' . $data['id'], 'data-action' => 'modal']
                            ],
                            [
                                'type' => 'link',
                                'name' => $jatbi->lang("Xem"),
                                'permission' => ['products'],
                                'action' => [
                                    'href' => '/warehouses/products-details/' . $data['id'],
                                ]
                            ],
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Lỗi"),
                                'permission' => ['products'],
                                'action' => ['data-url' => '/warehouses/products-error/' . $data['id'], 'data-action' => 'modal']
                            ],
                        ]
                    ]),
                ];
            });

            // --- 5. Trả về JSON ---
            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => $count,
                "recordsFiltered" => $count,
                "data" => $datas,
                "footerData" => [
                    "sum_sl" => number_format($sum_sl),
                    "sum_price" => number_format($sum_price)
                ]
            ]);
        }
    })->setPermissions(['products']);

    $app->router('/products-add', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting,$template, $stores) {
        $vars['title'] = $jatbi->lang("Thêm Sản phẩm");

        if ($app->method() === 'GET') {
            $vars['data'] = ['status' => 'A', 'vat_type' => 1, 'amount_status' => 1];
            $vars['groups'] = $app->select("products_group", ["id(value)", "name(text)"], ["deleted" => 0]);
            $vars['categorys'] = $app->select("categorys", ["id(value)", "name(text)"], ["deleted" => 0]);
            $vars['units'] = $app->select("units", ["id(value)", "name(text)"], ["deleted" => 0]);
            $vars['default_codes'] = $app->select("default_code", ["id(value)", "name(text)"], ["deleted" => 0]);
            $accountants_data = $app->select("accountants_code", ["id", "name", "code"], ["deleted" => 0]);
            $vars['main_products'] = [
                ['value' => '', 'text' => $jatbi->lang('Sản phẩm chính')]
            ];

            $formatted_accountants = [
                ['value' => '', 'text' => $jatbi->lang('Tài khoản')]
            ];

            foreach ($accountants_data as $item) {
                $formatted_accountants[] = [
                    'value' => $item['id'],
                    'text' => $item['code'] . ' - ' . $item['name']
                ];
            }
            array_unshift($stores, [
                'value' => '',
                'text' => $jatbi->lang('Tất cả')
            ]);
            $vars['stores'] = $stores;

            // Gán mảng đã có lựa chọn mặc định cho view
            $vars['accountants'] = $formatted_accountants;

            echo $app->render($template . '/warehouses/products-post.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);
            $post = array_map([$app, 'xss'], $_POST);
            $error = [];

            $required_fields = ['code', 'name', 'categorys', 'units', 'amount_status', 'stores', 'branch'];
            foreach ($required_fields as $field) {
                if (empty($post[$field])) {
                    $error = ['status' => 'error', 'content' => $jatbi->lang("Vui lòng điền đủ các trường bắt buộc")];
                    break;
                }
            }

            if (empty($error)) {
                $store_code = $app->get("stores", "code", ["id" => $post['stores']]);
                $branch_code = $app->get("branch", "code", ["id" => $post['branch']]);
                $full_code = $store_code . $branch_code . $post['code'];
                if ($app->has("products", ["code" => $full_code, "deleted" => 0])) {
                    $error = ['status' => 'error', 'content' => $jatbi->lang('Mã sản phẩm đã tồn tại')];
                }
            }

            if (!empty($error)) {
                // --- BẮT ĐẦU TÍCH HỢP LOGIC XỬ LÝ ẢNH ---
                $image_name = 'no-images.jpg';
                $active_hash = $jatbi->active(32); // Mã active này sẽ được dùng cho cả sản phẩm và thư mục ảnh
                $upload_info_for_db = null;

                if (isset($_FILES['images']) && $_FILES['images']['error'] === UPLOAD_ERR_OK) {
                    $handle = $app->upload($_FILES['images']);
                    if ($handle->uploaded) {
                        $path_upload = $setting['uploads'] . '/products/' . $active_hash . '/';
                        $path_upload_thumb = $path_upload . 'thumb/';
                        if (!is_dir($path_upload_thumb)) {
                            mkdir($path_upload_thumb, 0755, true);
                        }
                        $new_image_name_body = $jatbi->active(16);

                        // Xử lý ảnh gốc
                        $handle->file_new_name_body = $new_image_name_body;
                        $handle->process($path_upload);

                        // Xử lý ảnh thumb
                        if ($handle->processed) {
                            $handle->image_resize = true;
                            $handle->image_ratio_crop = true;
                            $handle->image_y = $setting['upload']['images']['products']['thumb_y'] ?? 300;
                            $handle->image_x = $setting['upload']['images']['products']['thumb_x'] ?? 300;
                            $handle->file_new_name_body = $new_image_name_body;
                            $handle->process($path_upload_thumb);

                            if ($handle->processed) {
                                $image_name = $handle->file_dst_name;
                                $upload_info_for_db = [
                                    "type" => "products",
                                    "content" => 'products/' . $active_hash . '/' . $image_name,
                                    "date" => date("Y-m-d H:i:s"),
                                    "active" => $active_hash,
                                    "size" => $handle->file_src_size,
                                    "data" => json_encode(['...'])
                                ];
                            }
                        }
                    }
                }
                $insert = [
                    "type" => 1,
                    "main" => $post['main'] ?? 0,
                    "code" => $full_code,
                    "name" => $post['name'],
                    "categorys" => $post['categorys'],
                    "vat" => $post['vat'] ?? 0,
                    "vat_type" => $post['vat_type'],
                    "vendor" => $post['vendor'] ?? null,
                    "amount_status" => $post['amount_status'],
                    "group" => $post['group'],
                    "price" => str_replace(',', '', $post['price'] ?? 0),
                    "units" => $post['units'],
                    "notes" => $post['notes'],
                    "active" => $jatbi->active(32),
                    "images" => $image_name,
                    "date" => date('Y-m-d H:i:s'),
                    "status" => $post['status'] ?? 'A',
                    "user" => $app->getSession("accounts")['id'] ?? 0,
                    "new" => 1,
                    "stores" => $post['stores'],
                    "branch" => $post['branch'],
                    "code_products" => $post['code'],
                    "tkno" => $post['tkno'] ?? '',
                    "tkco" => $post['tkco'] ?? '',
                    "default_code" => $post['default_code'],
                ];
                $app->insert("products", $insert);
                $getID = $app->id();
                if ($upload_info_for_db) {
                    $upload_info_for_db['parent'] = $getID;
                    $app->insert("uploads", $upload_info_for_db);
                }

                $jatbi->logs('products', 'add', $insert);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Thêm mới thành công'), 'url' => 'auto']);
            } else {
                echo json_encode($error);
            }
        }
    })->setPermissions(['products.add']);

    $app->router('/products-edit/{id}', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting,$template) {
        $id = $vars['id'] ?? 0;
        $vars['title'] = $jatbi->lang("Sửa Sản phẩm");

        if ($app->method() === 'GET') {
            $data = $app->get("products", "*", ["id" => $id, "deleted" => 0]);
            if (!$data) {
                echo $app->render($template . '/error.html', $vars, $jatbi->ajax());
                return;
            }
            $vars['data'] = $data;

            $vars['groups'] = $app->select("products_group", ["id(value)", "name(text)"], ["deleted" => 0]);
            $vars['categorys'] = $app->select("categorys", ["id(value)", "name(text)"], ["deleted" => 0]);
            $vars['units'] = $app->select("units", ["id(value)", "name(text)"], ["deleted" => 0]);
            $default_codes_data = $app->select("default_code", ["id", "code", "name"], ["deleted" => 0]);

            $vars['default_codes'] = array_map(function ($item) {
                return ['value' => $item['id'], 'text' => $item['code'] . ' - ' . $item['name']];
            }, $default_codes_data);
            $accountants_data = $app->select("accountants_code", ["id", "code", "name"], ["deleted" => 0]);

            $vars['accountants'] = array_map(function ($item) {
                return ['value' => $item['id'], 'text' => $item['code'] . ' - ' . $item['name']];
            }, $accountants_data);
            $vars['stores'] = $app->select("stores", ["id(value)", "name(text)"], ["deleted" => 0]);

            if (!empty($data['stores'])) {
                $vars['branchs'] = $app->select("branch", ["id(value)", "name(text)"], ["stores" => $data['stores'], "deleted" => 0, "status" => 'A']);
            }

            if (!empty($data['images']) && $data['images'] != 'no-images.jpg') {
                $vars['data']['image_preview_url'] = '/datas/products/' . $data['active'] . '/thumb/' . $data['images'];
            }

            echo $app->render($template . '/warehouses/products-post.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);

            $data = $app->get("products", "*", ["id" => $id, "deleted" => 0]);
            if (!$data) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Không tìm thấy sản phẩm')]);
                return;
            }

            $post = array_map([$app, 'xss'], $_POST);
            $error = [];

            $required_fields = ['code', 'name', 'categorys', 'units', 'amount_status'];
            foreach ($required_fields as $field) {
                if (empty($post[$field])) {
                    $error = ['status' => 'error', 'content' => $jatbi->lang("Vui lòng điền đủ các trường bắt buộc")];
                    break;
                }
            }

            if (empty($error)) {
                $full_code = $data['code'];

                if ($data['code_products'] != $post['code']) {
                    $store_code = $app->get("stores", "code", ["id" => $data['stores']]);
                    $branch_code = $app->get("branch", "code", ["id" => $data['branch']]);
                    $full_code = $store_code . $branch_code . $post['code'];
                }
                if ($full_code !== $data['code'] && $app->has("products", ["code" => $full_code, "deleted" => 0, "id[!]" => $id])) {
                    $error = ['status' => 'error', 'content' => $jatbi->lang('Mã sản phẩm đã tồn tại')];
                }
            }

            if (empty($error)) {
                // 3. Xử lý Upload ảnh (nếu có file mới)
                $image_name = $data['images'];
                $upload_info_for_db = null;
                $active_hash = $jatbi->active(32);


                if (isset($_FILES['images']) && $_FILES['images']['error'] === UPLOAD_ERR_OK) {
                    $handle = $app->upload($_FILES['images']);
                    if ($handle->uploaded) {
                        $path_upload = $setting['uploads'] . '/products/' . $active_hash . '/';
                        $path_upload_thumb = $path_upload . 'thumb/';
                        if (!is_dir($path_upload_thumb)) {
                            mkdir($path_upload_thumb, 0755, true);
                        }
                        $new_image_name_body = $jatbi->active(16);

                        // Xử lý ảnh gốc
                        $handle->file_new_name_body = $new_image_name_body;
                        $handle->process($path_upload);

                        // Xử lý ảnh thumb
                        if ($handle->processed) {
                            $handle->image_resize = true;
                            $handle->image_ratio_crop = true;
                            $handle->image_y = $setting['upload']['images']['products']['thumb_y'] ?? 300;
                            $handle->image_x = $setting['upload']['images']['products']['thumb_x'] ?? 300;
                            $handle->file_new_name_body = $new_image_name_body;
                            $handle->process($path_upload_thumb);

                            if ($handle->processed) {
                                $image_name = $handle->file_dst_name;
                                $upload_info_for_db = [
                                    "type" => "products",
                                    "content" => 'products/' . $active_hash . '/' . $image_name,
                                    "date" => date("Y-m-d H:i:s"),
                                    "active" => $active_hash,
                                    "size" => $handle->file_src_size,
                                    "data" => json_encode(['...'])
                                ];
                            }
                        }
                    }
                }
                $user_id = $app->getSession("accounts")['id'] ?? 0;
                // 4. Chuẩn bị mảng $update và lưu

                $update = [
                    "code" => $full_code,
                    "name" => $post['name'],
                    "main" => $post['main'] ?? 0,
                    "categorys" => $post['categorys'],
                    "vat" => $post['vat'] ?? 0,
                    "vat_type" => $post['vat_type'],
                    "vendor" => $post['vendor'] ?? null,
                    "amount_status" => $post['amount_status'],
                    "group" => $post['group'],
                    "price" => str_replace(',', '', $post['price'] ?? 0),
                    "units" => $post['units'],
                    "notes" => $post['notes'],
                    "images" => $image_name,
                    "status" => $post['status'] ?? 'A',
                    "code_products" => $post['code'],
                    "tkno" => $post['tkno'] ?? null,
                    "tkco" => $post['tkco'] ?? null,
                    "default_code" => $post['default_code'],
                    "user" => $user_id,
                ];

                $app->update("products", $update, ["id" => $id]);

                // Nếu có ảnh mới, cập nhật bảng uploads
                if ($upload_info_for_db) {
                    $upload_info_for_db['parent'] = $id;
                    $app->insert("uploads", $upload_info_for_db);
                }

                $jatbi->logs('products', 'edit', [$update, $data], $user_id);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Cập nhật thành công'), 'url' => 'auto']);
            } else {
                echo json_encode($error);
            }
        }
    })->setPermissions(['products.edit']);

    $app->router("/products-deleted", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Xóa sản phẩm");

        if ($app->method() === 'GET') {
            echo $app->render($template . '/common/deleted.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header([
                'Content-Type' => 'application/json',
            ]);
            $boxid = explode(',', $app->xss($_GET['box']));

            // Lấy dữ liệu của các sản phẩm sắp bị xóa để ghi log
            $datas = $app->select("products", "*", ["id" => $boxid, "deleted" => 0]);

            if (count($datas) > 0) {
                foreach ($datas as $data) {
                    $app->update("products", ["deleted" => 1], ["id" => $data['id']]);
                    $name[] = $data['name'];
                }

                // 1. Lấy user_id từ session (giống ingredient-deleted)
                $user_id = $app->getSession("accounts")['id'] ?? 0;

                // 2. Sửa lại lời gọi hàm logs cho đúng (giống ingredient-deleted)
                $jatbi->logs('products', 'deleted', $datas, $user_id);

                $jatbi->trash('/products/products-restore', "Xóa sản phẩm: " . implode(', ', $name), ["database" => 'products', "data" => $boxid]);

                echo json_encode(['status' => 'success', "content" => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Có lỗi xảy ra")]);
            }
        }
    })->setPermissions(['products.deleted']);

    $app->router("/products_amount_status", ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Cập nhật số lượng");
        if ($app->method() === 'GET') {
            echo $app->render($template . '/common/status.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header([
                'Content-Type' => 'application/json',
            ]);

            if (!isset($_GET['box']) || empty($_GET['box'])) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Không có sản phẩm được chọn")]);
                return;
            }

            $boxid = array_filter(array_map('intval', explode(',', $app->xss($_GET['box']))));
            if (empty($boxid)) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Danh sách sản phẩm không hợp lệ")]);
                return;
            }

            $datas = $app->select("products", "*", ["id" => $boxid, "amount_status" => [0, 1], "deleted" => 0]);
            if (count($datas) === 0) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Không tìm thấy sản phẩm hợp lệ")]);
                return;
            }

            $names = [];
            $updated_ids = [];
            foreach ($datas as $data) {
                $new_status = $data['amount_status'] === 1 ? 0 : 1;
                $app->update("products", ["amount_status" => $new_status], ["id" => $data['id']]);
                $names[] = $data['name'];
                $updated_ids[] = $data['id'];
            }

            $jatbi->logs('products', 'products-amount_status', $datas);
            $jatbi->trash('/products/products-amount_status', "Cập nhật trạng thái sản phẩm: " . implode(', ', $names), ["database" => 'products', "data" => $updated_ids]);

            echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
        }
    })->setPermissions(['products.edit']);


    $app->router("/products_amount_status/{id}", 'POST', function ($vars) use ($app, $jatbi) {
        $app->header([
            'Content-Type' => 'application/json',
        ]);
        $data = $app->get("products", "*", ["id" => $vars['id'], "deleted" => 0]);
        if ($data > 1) {
            if ($data > 1) {
                if ($data['amount_status'] === 1) {
                    $status = 0;
                } elseif ($data['amount_status'] === 0) {
                    $status = 1;
                }
                $app->update("products", ["amount_status" => $status], ["id" => $data['id']]);
                $jatbi->logs('products', 'products-amount_status', $data);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Cập nhật thất bại"),]);
            }
        } else {
            echo json_encode(["status" => "error", "content" => $jatbi->lang("Không tìm thấy dữ liệu")]);
        }
    })->setPermissions(['products.edit']);


    $app->router('/products-barcode/{id}', 'GET', function ($vars) use ($app, $jatbi, $template) {
        $id = $vars['id'] ?? 0;

        $data = $app->get("products", [
            "id",
            "code",
            "price",
            "default_code"
        ], ["id" => $id]);

        if (!$data) {
            // Xử lý trường hợp không tìm thấy sản phẩm
            echo $app->render($template . '/error.html', ['content' => 'Sản phẩm không tồn tại'], $jatbi->ajax());
            return;
        }

        $vars['data'] = $data;
        $vars['title'] = $jatbi->lang("In Barcode") . " - " . $data['code'];

        $barcode_image_base64 = null;
        $default_code_value = null;

        if (!empty($data['default_code'])) {
            $default_code = $app->get("default_codes", "code", ["id" => $data["default_code"], "deleted" => 0]);

            if ($default_code) {
                $default_code_value = $default_code;
                $generator = new BarcodeGeneratorPNG();
                $barcode_image_base64 = base64_encode($generator->getBarcode($default_code, $generator::TYPE_CODE_128, 1, 20));
            }
        }
        $vars['barcode_image'] = $barcode_image_base64;
        $vars['barcode_value'] = $default_code_value;

        // Render giao diện modal
        echo $app->render($template . '/warehouses/products-barcode.html', $vars, $jatbi->ajax());
    })->setPermissions(['products']);


    $app->router('/warehouses-history/{type}', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting,$template) {
        $type = $vars['type'] ?? 'import';

        // Cấu hình dựa trên type
        $config = [
            'import' => ['title' => 'Lịch sử nhập hàng', 'code' => 'import'],
            'move' => ['title' => 'Lịch sử chuyển hàng', 'code' => ['move', 'return']],
            'cancel' => ['title' => 'Lịch sử hủy hàng', 'code' => 'cancel']
        ];
        $current_config = $config[$type] ?? $config['import'];

        $vars['title'] = $jatbi->lang($current_config['title']);
        $vars['type'] = $type;

        if ($app->method() === 'GET') {
            $vars['accounts'] = $app->select("accounts", ["id(value)", "name(text)"], ["deleted" => 0, "status" => 'A']);
            echo $app->render($template . '/warehouses/history.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);

            // Đọc tham số từ DataTables và bộ lọc
            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
            $orderName = isset($_POST['order'][0]['column']) ? $_POST['columns'][$_POST['order'][0]['column']]['name'] : 'warehouses.id';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            $filter_categorys = isset($_POST['categorys']) ? $_POST['categorys'] : '';
            $filter_branch = isset($_POST['branch']) ? $_POST['branch'] : '';
            $filter_units = isset($_POST['units']) ? $_POST['units'] : '';
            $filter_status = isset($_POST['status']) ? $_POST['status'] : '';
            $filter_groups = isset($_POST['groups']) ? $_POST['groups'] : '';
            // $store = isset($_POST['stores']) ? $app->xss($_POST['stores']) : $accStore;
            $filter_date = isset($_POST['date']) ? $_POST['date'] : '';

            $filter_user = $_POST['user'] ?? '';

            // Xây dựng mệnh đề WHERE
            $where = [
                "AND" => [
                    "warehouses.deleted" => 0,
                    "warehouses.data" => 'products',
                    "warehouses.type" => $current_config['code'],
                ]
            ];

            if (!empty($searchValue)) {
                $where['AND']['OR'] = [
                    'warehouses.id[~]' => $searchValue,
                    'warehouses.content[~]' => $searchValue,
                ];
            }

            if (!empty($filter_user)) {
                $where['AND']['warehouses.user'] = $filter_user;
            }

            if (!empty($filter_date)) {
                $dates = explode(' - ', $filter_date);
                if (count($dates) === 2) {
                    $date_from = date('Y-m-d 00:00:00', strtotime(str_replace('/', '-', trim($dates[0]))));
                    $date_to = date('Y-m-d 23:59:59', strtotime(str_replace('/', '-', trim($dates[1]))));
                    $where["AND"]["warehouses.date_poster[<>]"] = [$date_from, $date_to];
                }
            }

            $joins = [
                "[<]accounts" => ["user" => "id"],
                "[<]stores" => ["stores" => "id"],
                "[<]branch" => ["branch" => "id"],
                // "[<]stores(receive_store)" => ["stores_receive" => "id"],
                // "[<]branch(receive_branch)" => ["branch_receive" => "id"],
            ];

            // Đếm bản ghi
            $count = $app->count("warehouses", $joins, "warehouses.id", $where);

            $where["ORDER"] = [$orderName => strtoupper($orderDir)];
            $where["LIMIT"] = [$start, $length];

            // Lấy dữ liệu
            $columns = [
                "warehouses.id",
                "warehouses.code",
                "warehouses.content",
                "warehouses.date_poster",
                "warehouses.type",
                "warehouses.receive_status",
                "warehouses.receive_date",
                "accounts.name(user_name)",
                "stores.name(store_name)",
                "branch.name(branch_name)",
                // "receive_store.name(receive_store_name)",
                // "receive_branch.name(receive_branch_name)",
            ];


            $datas = $app->select("warehouses", $joins, $columns, $where);

            $resultData = [];
            foreach ($datas as $data) {
                $store_info = ($data['store_name'] ?? '') . ' - ' . ($data['branch_name'] ?? '');
                // if ($data['receive_store_name']) {
                //     $store_info .= ' -> ' . ($data['receive_store_name']) . ' - ' . ($data['receive_branch_name'] ?? '');
                // }

                // Xử lý hiển thị trạng thái
                $status_html = '';
                if ($data['type'] == 'import')
                    $status_html = '<span class="badge bg-primary">' . $jatbi->lang('Đã nhập hàng') . '</span>';
                elseif ($data['type'] == 'cancel')
                    $status_html = '<span class="badge bg-danger">' . $jatbi->lang('Đã hủy hàng') . '</span>';
                elseif (in_array($data['type'], ['move', 'return'])) {
                    // Giả sử có mảng $Status_warehouser_move
                    $status_info = $setting['Status_warehouser_move'][$data['receive_status']] ?? ['color' => 'secondary', 'name' => 'Không rõ'];
                    $status_html = '<span class="badge bg-' . $status_info['color'] . '">' . $status_info['name'] . '</span>';
                }

                $resultData[] = [
                    "code" => '<a data-action="modal" data-url="/warehouses/history-views/' . $data['id'] . '">#' . $data['code'] . $data['id'] . '</a>',
                    "content" => $data['content'],
                    "date" => $jatbi->datetime($data['date_poster']),
                    "stores" => $store_info,
                    "user" => $data['user_name'],
                    "status" => $status_html,
                    "action" => $app->component("action", [
                        "button" => [
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang('Xem'),
                                'permission' => ['products'],
                                'action' => [
                                    'data-url' => '/warehouses/warehouses-history-views/' . $data['id'] . '',
                                    'data-action' => 'modal'
                                ]
                            ],
                            [
                                'type' => 'link',
                                'name' => $jatbi->lang("In"),
                                'permission' => ['products'],
                                'action' => ['href' => '/warehouses/warehouses-history-print/' . $data['id'], 'data-pjax' => '']
                            ],
                        ]
                    ]),
                ];
            }

            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => $count,
                "recordsFiltered" => $count,
                "data" => $resultData
            ]);
        }
    })->setPermissions(['products']);

    $app->router("/warehouses-history-views/{id}", 'GET', function ($vars) use ($app, $jatbi, $template) {
        $id = (int) ($vars['id'] ?? 0);

        $data = $app->get("warehouses", [
            "[>]accounts" => ["user" => "id"],
            "[>]stores (store_from)" => ["stores" => "id"],
            "[>]branch (branch_from)" => ["branch" => "id"],
            "[>]stores (store_to)" => ["stores_receive" => "id"],
            "[>]branch (branch_to)" => ["branch_receive" => "id"],
        ], [
            "warehouses.id",
            "warehouses.code",
            "warehouses.type",
            "warehouses.date",
            "warehouses.content",
            "warehouses.receive_status",
            "warehouses.purchase",
            "warehouses.stores",
            "warehouses.branch",
            "warehouses.stores_receive",
            "warehouses.branch_receive",
            "warehouses.user",
            "accounts.name(user_name)",
            "store_from.name(store_name)",
            "branch_from.name(branch_name)",
            "store_to.name(store_receive_name)",
            "branch_to.name(branch_receive_name)",
        ], [
            "warehouses.id" => $id
        ]);

        if (!$data) {
            echo $app->render($template . '/error.html', [], $jatbi->ajax());
            return;
        }

        $details = $app->select("warehouses_details", "*", ["warehouses" => $data['id']]);

        // BƯỚC 2: TỐI ƯU HÓA - Lấy dữ liệu cho các chi tiết (vẫn giữ nguyên vì đã tối ưu)
        $enriched_products = [];
        if (!empty($details)) {
            $product_ids = array_column($details, 'products');
            $products_info = $app->select("products", ["[>]units" => ["units" => "id"]], ["products.id", "products.code", "products.name", "units.name(unit_name)"], ["products.id" => $product_ids]);
            $products_map = array_column($products_info, null, 'id');

            $total_amount = 0;
            $total_sum = 0;

            foreach ($details as $key => &$detail) {
                $product_info = $products_map[$detail['products']] ?? [];
                $detail['code'] = $product_info['code'] ?? 'N/A';
                $detail['name'] = $product_info['name'] ?? 'Sản phẩm không xác định';
                $detail['unit_name'] = $product_info['unit_name'] ?? 'N/A';

                $amount = (float) ($detail['amount'] ?? 0);
                $price = (float) ($detail['price'] ?? 0);
                $detail['total_price'] = $amount * $price;

                $total_amount += $amount;
                $total_sum += $detail['total_price'];
            }
            unset($detail);
            $enriched_products = $details;
        }

        // BƯỚC 3: CHUẨN BỊ BIẾN VÀ RENDER VIEW
        $vars['title'] = $jatbi->lang("Chi tiết Phiếu kho");
        $vars['data'] = $data;
        $vars['details'] = $enriched_products;
        $vars['total_amount'] = $total_amount ?? 0;
        $vars['total_sum'] = $total_sum ?? 0;
        // $vars['total_in_words'] = ucfirst(docso($vars['total_sum'])) . ' đồng.'; // Bạn có thể kích hoạt lại dòng này nếu cần
        $vars['status_map'] = [1 => ['name' => 'Chờ nhận hàng', 'color' => 'warning'], 2 => ['name' => 'Đã nhận hàng', 'color' => 'success'], 3 => ['name' => 'Nhận một phần', 'color' => 'info']];

        echo $app->render($template . '/warehouses/history-view.html', $vars, $jatbi->ajax());
    })->setPermissions(['warehouses-move-history']);

    $app->router("/warehouses-history-print/{id}", 'GET', function ($vars) use ($app, $jatbi, $template) {

        // --- BƯỚC 1: LẤY DỮ LIỆU CHÍNH ---
        $id = (int) ($vars['id'] ?? 0);
        $data = $app->get("warehouses", ["id", "code"], ["id" => $id]);

        if (!$data) {
            return $app->render($template . '/error.html', []);
        }

        $details = $app->select("warehouses_details", "*", ["warehouses" => $data['id']]);

        $enriched_details = [];
        $totals = ['amount' => 0, 'price' => 0, 'total_price' => 0];

        if (!empty($details)) {
            $product_ids = array_column($details, 'products');

            $products_info = $app->select(
                "products",
                ["[>]units" => ["units" => "id"]],
                ["products.id", "products.name", "products.code", "products.price", "units.name(unit_name)"],
                ["products.id" => $product_ids]
            );
            $products_map = array_column($products_info, null, 'id');

            // --- BƯỚC 3: GỘP DỮ LIỆU VÀ TÍNH TOÁN ---
            foreach ($details as $detail) {
                $product = $products_map[$detail['products']] ?? null;
                if (!$product)
                    continue;

                $amount = (float) ($detail['amount'] ?? 0);
                $price = (float) ($product['price'] ?? 0);
                $total_price = $amount * $price;

                $enriched_details[] = array_merge($detail, [
                    'code' => $product['code'],
                    'name' => $product['name'],
                    'unit_name' => $product['unit_name'],
                    'current_price' => $price,
                    'total_price' => $total_price
                ]);

                // Tính tổng
                $totals['amount'] += $amount;
                $totals['price'] += $price;
                $totals['total_price'] += $total_price;
            }
        }

        $vars['title'] = $jatbi->lang("Danh sách sản phẩm");
        $vars['data'] = $data;
        $vars['details'] = $enriched_details;
        $vars['totals'] = $totals;

        echo $app->render($template . '/warehouses/history-print.html', $vars);
    })->setPermissions(['warehouses-move-history']);


    $app->router("/warehouses-barcode/{id}", 'GET', function ($vars) use ($app, $jatbi, $template) {
        $id = (int) ($vars['id'] ?? 0);
        $detail = $app->get("warehouses_details", ["products"], ["id" => $id]);

        if (!$detail) {
            return $app->render($template . '/error.html', [], $jatbi->ajax());
        }

        $product = $app->get("products", ["id", "code", "default_code", "price"], ["id" => $detail["products"]]);

        if (!$product) {
            return $app->render($template . '/error.html', [], $jatbi->ajax());
        }

        // --- BƯỚC 2: XỬ LÝ LOGIC TẠO BARCODE (Không làm trong view) ---
        $barcode_value = null;
        $barcode_base64 = null;

        if (!empty($product["default_code"])) {
            $barcode_value = $app->get("default_code", "code", ["id" => $product["default_code"], "deleted" => 0]);
            if ($barcode_value) {
                $generator = new BarcodeGeneratorPNG();
                $barcode_base64 = base64_encode($generator->getBarcode($barcode_value, $generator::TYPE_CODE_128, 1, 20));
            }
        }

        // --- BƯỚC 3: CHUẨN BỊ BIẾN VÀ RENDER VIEW ---
        $vars['title'] = $jatbi->lang("In Barcode");
        $vars['product'] = $product;
        $vars['barcode_value'] = $barcode_value;
        $vars['barcode_base64'] = $barcode_base64;

        echo $app->render($template . '/warehouses/barcode.html', $vars, $jatbi->ajax());
    });





    $app->router('/ingredient-details/{id}', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template, $accStore, $stores) {
        $jatbi->permission('ingredient');
        $id = (int) ($vars['id'] ?? 0);

        if ($app->method() === 'GET') {
            // Lấy thông tin nguyên liệu
            $data = $app->get("ingredient", [
                "[>]colors" => ["colors" => "id"],
                "[>]pearl" => ["pearl" => "id"],
                "[>]sizes" => ["sizes" => "id"],
                "[>]products_group" => ["group" => "id"],
                "[>]categorys" => ["categorys" => "id"],
                "[>]units" => ["units" => "id"],
                "[>]vendors" => ["vendor" => "id"]
            ], [
                "ingredient.id",
                "ingredient.code",
                "ingredient.amount(stock)",
                "ingredient.price",
                "colors.name(color_name)",
                "pearl.name(pearl_name)",
                "sizes.name(size_name)",
                "products_group.name(group_name)",
                "categorys.name(category_name)",
                "units.name(unit_name)",
                "vendors.name(vendor_name)"
            ], ["ingredient.id" => $id]);



            $date_from = date('Y-m-d 00:00:00', strtotime('first day of this month'));
            $date_to = date('Y-m-d 23:59:59');

            $vars['data'] = $data;

            $vars['title'] = $jatbi->lang("Nguyên liệu") . ': ' . $data['code'];
            $vars['date_from'] = $date_from;
            $vars['date_to'] = $date_to;
            array_unshift($stores, [
                'value' => '',
                'text' => $jatbi->lang('Tất cả')
            ]);
            $vars['stores'] = $stores;

            echo $app->render($template . '/warehouses/ingredient-details.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);


            $data = $app->get("ingredient", [
                "[>]colors" => ["colors" => "id"],
                "[>]pearl" => ["pearl" => "id"],
                "[>]sizes" => ["sizes" => "id"],
                "[>]products_group" => ["group" => "id"],
                "[>]categorys" => ["categorys" => "id"],
                "[>]units" => ["units" => "id"],
                "[>]vendors" => ["vendor" => "id"]
            ], [
                "ingredient.id",
                "ingredient.code",
                "ingredient.amount(stock)",
                "ingredient.price",
                "colors.name(color_name)",
                "pearl.name(pearl_name)",
                "sizes.name(size_name)",
                " products_group.name(group_name)",
                "categorys.name(category_name)",
                "units.name(unit_name)",
                "vendors.name(vendor_name)"
            ], ["ingredient.id" => $id]);

            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
            $orderName = isset($_POST['order'][0]['column']) ? $_POST['columns'][$_POST['order'][0]['column']]['name'] : 'warehouses_logs.id';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            // Lấy giá trị từ bộ lọc
            $filter_user = isset($_POST['user']) ? $_POST['user'] : '';
            $filter_date = isset($_POST['date']) ? $_POST['date'] : '';
            $store = isset($_POST['stores']) ? $app->xss($_POST['stores']) : $accStore;

            // Khởi tạo mặc định $date_from và $date_to
            $date_from = date('2021-01-01 00:00:00', strtotime('first day of this month'));
            $date_to = date('Y-m-d 23:59:59');


            if (!empty($filter_date)) {
                $date_parts = explode(' - ', $filter_date);
                if (count($date_parts) == 2) {
                    $date_from = date('Y-m-d 00:00:00', strtotime(str_replace('/', '-', trim($date_parts[0]))));
                    $date_to = date('Y-m-d 23:59:59', strtotime(str_replace('/', '-', trim($date_parts[1]))));
                }
            }

            $baseWhere = [
                "warehouses_logs.ingredient" => $id,
                "warehouses_logs.date[<>]" => [$date_from, $date_to],
                "warehouses_logs.data" => ['ingredient', 'products']
            ];


            if (!empty($filter_user)) {
                $baseWhere['AND']['warehouses_logs.user'] = $filter_user;
            }
            if ($store != [0]) {
                $where['AND']['warehouses_logs.stores'] = $store;
            }

            if (!empty($searchValue)) {
                $baseWhere['AND']['OR'] = [
                    'warehouses_logs.notes[~]' => $searchValue,
                    'warehouses_logs.warehouses[~]' => $searchValue,
                    'accounts.name[~]' => $searchValue
                ];
            }


            $joins = [
                "[>]accounts" => ["user" => "id"],
                "[>]stores" => ["stores" => "id"],
                "[>]stores(stores_receive)" => ["stores_receive" => "id"]
            ];


            $recordsFiltered = $app->count("warehouses_logs", $joins, "warehouses_logs.id", $baseWhere);


            $warehouses_details_for_footer = $app->select("warehouses_details", [
                "type",
                "amount",
                "amount_cancel",
                "amount_total"
            ], [
                "ingredient" => $id,
                "date[<>]" => [$date_from, $date_to],
                "data" => 'ingredient',
                "type" => ['import', 'export'],
            ]);


            $pagedWhere = $baseWhere;
            $pagedWhere["LIMIT"] = [$start, $length];
            $pagedWhere["ORDER"] = [$orderName => strtoupper($orderDir)];


            $warehouses_logs = $app->select("warehouses_logs", $joins, [
                "warehouses_logs.warehouses",
                "warehouses_logs.type",
                "warehouses_logs.data",
                "warehouses_logs.amount",
                "warehouses_logs.price",
                "warehouses_logs.notes",
                "warehouses_logs.stores",
                "warehouses_logs.stores_receive",
                "warehouses_logs.date",
                "accounts.name(user_name)",
                "stores.name(store_name)",
                "stores_receive.name(store_receive_name)"
            ], $pagedWhere);

            // Định dạng dữ liệu cho DataTables
            $output_data = [];
            foreach ($warehouses_logs as $log) {
                $output_data[] = [
                    "id" => '<a class="btn dropdown-item" data-action="modal" data-url="/warehouses/ingredient-history-views/' . $log['warehouses'] . '">#' . $log['warehouses'] . '</a>',
                    "import" => ($log['type'] == 'import' && $log['data'] == 'ingredient') ? number_format($log['amount'], 1) : '',
                    "export" => ($log['type'] == 'export' || ($log['type'] == 'import' && $log['data'] == 'products')) ? number_format($log['amount'], 1) : '',
                    "move" => ($log['type'] == 'move') ? number_format($log['amount'], 1) : '',
                    "cancel" => ($log['type'] == 'cancel') ? number_format($log['amount'], 1) : '',
                    "price" => number_format($log['price']),
                    "store" => $log['store_name'] . ($log['store_receive_name'] ? " -> " . $log['store_receive_name'] : ''),
                    "notes" => htmlspecialchars($log['notes'] ?? ''),
                    "date" => date('d/m/Y H:i:s', strtotime($log['date'])),
                    "user" => htmlspecialchars($log['user_name'] ?? 'N/A')
                ];
            }
            $totalImport = 0;
            $totalExport = 0;
            $totalCancel = 0;
            $totalStock = 0;
            foreach ($warehouses_details_for_footer as $warehouse) {
                if ($warehouse['type'] == 'import') {
                    $totalImport += $warehouse['amount'];
                    $totalCancel += $warehouse['amount_cancel'];
                    $totalStock += $warehouse['amount_total'];
                }
                if ($warehouse['type'] == 'export') {
                    $totalExport += $warehouse['amount'];
                }
            }

            // --- BƯỚC 3: TRẢ VỀ JSON ĐÃ KẾT HỢP ---
            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => $recordsFiltered,
                "recordsFiltered" => $recordsFiltered,
                "data" => $output_data,
                "footerData" => [
                    "totalImport" => number_format($totalImport, 1),
                    "totalExport" => number_format($totalExport, 1),
                    "totalMove" => number_format(0, 1),
                    "totalCancel" => number_format($totalCancel, 1),
                    "totalPrice" => number_format($data['price']),
                    "totalStock" => number_format($totalStock, 1) . ' ' . $data['unit_name']
                ]
            ]);
        }
    })->setPermissions(['ingredient']);

    $app->router('/products-details/{id}', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting,$template, $accStore) {
        $id = (int) ($vars['id'] ?? 0);
        if ($app->method() === 'GET') {
            // Lấy thông tin sản phẩm
            $data = $app->get("products", [
                "[>]units" => ["units" => "id"],
                "[>]categorys" => ["categorys" => "id"],
                "[>]products_group" => ["group" => "id"],
            ], [
                "products.id",
                "products.code",
                "products.name",
                "products.active",
                "products.images",
                "products.price",
                "units.name(unit_name)",
                "categorys.name(category_name)",
                "products_group.name(group_name)",
            ], ["products.id" => $id, "products.deleted" => 0]);

            if (!$data) {
                $app->error(404);
                return;
            }

            // Lấy danh sách cửa hàng cho form lọc
            $stores = array_merge(
                [['value' => '', 'text' => $jatbi->lang('Tất cả')]],
                $app->select('stores', ['id(value)', 'name(text)'], ['deleted' => 0, 'status' => 'A'])
            );

            // Mặc định khoảng thời gian
            $date_from = date('Y-m-d 00:00:00', strtotime('first day of this month'));
            $date_to = date('Y-m-d 23:59:59', strtotime('last day of this month'));

            // Chuẩn bị dữ liệu cho template
            $vars['data'] = $data;
            $vars['stores'] = $stores;
            $vars['title'] = $jatbi->lang("Chi tiết sản phẩm") . ': ' . $data['name'];
            $vars['date_from'] = $date_from;
            $vars['date_to'] = $date_to;

            echo $app->render($template . '/warehouses/products-details.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);

            // Đọc tham số từ DataTables
            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : ($setting['site_page'] ?? 10);
            $searchValue = isset($_POST['search']['value']) ? $app->xss($_POST['search']['value']) : '';
            $orderName = isset($_POST['order'][0]['column']) ? $_POST['columns'][$_POST['order'][0]['column']]['name'] : 'warehouses_logs.date';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            // Lấy giá trị từ bộ lọc
            $filter_date = isset($_POST['date']) ? $app->xss($_POST['date']) : '';
            $filter_stores = isset($_POST['stores']) ? $app->xss($_POST['stores']) : $accStore;

            // Xử lý bộ lọc ngày
            $date_from = date('2020-05-06 00:00:00', strtotime('first day of this month'));
            $date_to = date('2025-05-22 23:59:59', strtotime('last day of this month'));
            if (!empty($filter_date)) {
                $date_parts = explode(' - ', $filter_date);
                if (count($date_parts) == 2) {
                    $from = trim($date_parts[0]);
                    $to = trim($date_parts[1]);
                    // Kiểm tra định dạng ngày hợp lệ
                    if (strtotime(str_replace('/', '-', $from)) && strtotime(str_replace('/', '-', $to))) {
                        $date_from = date('Y-m-d 00:00:00', strtotime(str_replace('/', '-', $from)));
                        $date_to = date('Y-m-d 23:59:59', strtotime(str_replace('/', '-', $to)));
                    }
                }
            }

            // Điều kiện WHERE cơ bản
            $baseWhere = [
                "warehouses_logs.products" => $id,
                "warehouses_logs.date[<>]" => [$date_from, $date_to],
                "warehouses_logs.data" => "products",
                "warehouses_logs.deleted" => 0,
            ];

            // Thêm bộ lọc stores
            if (!empty($filter_stores)) {
                $baseWhere['AND']['warehouses_logs.stores'] = $filter_stores;
            }

            // Thêm tìm kiếm nếu có
            if (!empty($searchValue)) {
                // Loại bỏ tiền tố # hoặc #HĐ từ giá trị tìm kiếm
                $searchValue = preg_replace('/^#HĐ|^#/', '', $searchValue);
                $baseWhere['AND']['OR'] = [
                    'warehouses_logs.warehouses[~]' => $searchValue,
                    'warehouses_logs.invoices[~]' => $searchValue,
                ];
            }

            // Join với accounts, stores, stores_receive
            $joins = [
                "[>]accounts" => ["user" => "id"],
                "[>]stores" => ["stores" => "id"],
                "[>]stores(stores_receive)" => ["stores_receive" => "id"],
            ];
            // Đếm tổng số bản ghi
            $recordsFiltered = $app->count("warehouses_logs", $joins, "warehouses_logs.id", $baseWhere);

            // Lấy tất cả log trong kỳ để tính tổng
            $all_logs_for_period = $app->select("warehouses_logs", $joins, [
                "warehouses_logs.type",
                "warehouses_logs.amount",
            ], $baseWhere);

            // Khởi tạo mảng đầu kỳ và trong kỳ
            $dauky = ['import' => ['amount' => 0], 'error' => ['amount' => 0], 'export' => ['amount' => 0], 'move' => ['amount' => 0]];
            $trongky = ['import' => ['amount' => 0], 'error' => ['amount' => 0], 'export' => ['amount' => 0], 'move' => ['amount' => 0]];

            // Tính đầu kỳ
            $getStarts = $app->select("warehouses_logs", ['amount', 'type'], [
                "AND" => [
                    "date[<]" => $date_from,
                    "amount[>]" => 0,
                    "products" => $id,
                    "stores" => $filter_stores ?: $accStore,
                    "deleted" => 0,
                ],
            ]);
            foreach ($getStarts as $getStart) {
                if (in_array($getStart['type'], ['import', 'error', 'export', 'move'])) {
                    $dauky[$getStart['type']]['amount'] += (float) $getStart['amount'];
                }
            }

            // Tính trong kỳ và tổng hợp
            $totalImport = 0;
            $totalExport = 0;
            $totalMove = 0;
            $totalError = 0;
            foreach ($all_logs_for_period as $log) {
                $amount = floatval($log['amount']);
                if (in_array($log['type'], ['import', 'error', 'export', 'move'])) {
                    $trongky[$log['type']]['amount'] += $amount;
                    if ($log['type'] == 'import') {
                        $totalImport += $amount;
                    } elseif ($log['type'] == 'export' || $log['type'] == 'pairing') {
                        $totalExport += $amount;
                    } elseif ($log['type'] == 'move') {
                        $totalMove += $amount;
                    } elseif ($log['type'] == 'error') {
                        $totalError += $amount;
                    }
                }
            }

            // Tính tồn kho
            $nhap = $dauky['import']['amount'] - $dauky['error']['amount'] - $dauky['export']['amount'] - $dauky['move']['amount'] + $trongky['import']['amount'];
            $ton_kho = $nhap - $trongky['export']['amount'] - $trongky['error']['amount'] - $trongky['move']['amount'];

            // Thêm LIMIT và ORDER
            $pagedWhere = $baseWhere;
            $pagedWhere["LIMIT"] = [$start, $length];
            $pagedWhere["ORDER"] = [$orderName => strtoupper($orderDir)];

            // Lấy dữ liệu đã phân trang
            $warehouses_logs = $app->select("warehouses_logs", $joins, [
                "warehouses_logs.warehouses",
                "warehouses_logs.type",
                "warehouses_logs.invoices",
                "warehouses_logs.amount",
                "warehouses_logs.price",
                "warehouses_logs.notes",
                "warehouses_logs.stores",
                "warehouses_logs.stores_receive",
                "warehouses_logs.date",
                "warehouses_logs.user",
                "warehouses_logs.duration",
                "accounts.name(user_name)",
                "stores.name(store_name)",
                "stores_receive.name(store_receive_name)",
            ], $pagedWhere);

            // Định dạng dữ liệu cho DataTables
            $output_data = [];
            foreach ($warehouses_logs as $log) {
                $id_display = '#' . $log['warehouses'];
                if ($log['type'] == 'export' && $log['invoices']) {
                    $id_display = '<a class="pjax-load" href="/invoices/invoices-views/' . $log['invoices'] . '/">#HĐ' . $log['invoices'] . '</a>';
                } elseif (in_array($log['type'], ['import', 'move', 'export']) && $log['warehouses'] && !$log['invoices']) {
                    $id_display = '<a class="modal-url" data-url="/warehouses/warehouses-history-views/' . $log['warehouses'] . '/">#' . $log['warehouses'] . '</a>';
                } elseif ($log['type'] == 'error' && $log['warehouses']) {
                    $id_display = '<a class="modal-url" data-url="/warehouses/products-error-history-views/' . $log['warehouses'] . '">#' . $log['warehouses'] . '</a>';
                }

                $output_data[] = [
                    "id" => $id_display,
                    "import" => ($log['type'] == 'import') ? number_format($log['amount'], 2) : '',
                    "export" => ($log['type'] == 'export' || $log['type'] == 'pairing') ? number_format($log['amount'], 2) : '',
                    "move" => ($log['type'] == 'move') ? number_format($log['amount'], 2) : '',
                    "error" => ($log['type'] == 'error') ? number_format($log['amount'], 2) : '',
                    "price" => number_format($log['price']),
                    "store" => $log['store_name'] . ($log['store_receive_name'] ? " -> " . $log['store_receive_name'] : ''),
                    "notes" => htmlspecialchars($log['notes']),
                    "duration" => $log['duration'] ? $log['duration'] . ' ' . $jatbi->lang('tháng') : '',
                    "date" => date("d/m/Y H:i:s", strtotime($log['date'])),
                    "user" => htmlspecialchars($log['user_name'] ?? 'N/A'),
                ];
            }

            // Trả về JSON
            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => $recordsFiltered,
                "recordsFiltered" => $recordsFiltered,
                "data" => $output_data,
                "footerData" => [
                    "totalImport" => number_format($totalImport, 2),
                    "totalExport" => number_format($totalExport, 2),
                    "totalMove" => number_format($totalMove, 2),
                    "totalError" => number_format($totalError, 2),
                    "ton_kho" => number_format($ton_kho, 2),
                ],
            ]);
        }
    })->setPermissions(['products']);



    // $app->router('/logs_warehouses/ingredient', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting) {

    //     $vars['title'] = $jatbi->lang("Nhật ký Nguyên liệu");
    //     $vars['type'] = 'ingredient';


    //     if ($app->method() === 'GET') {

    //         $accounts_db = $app->select("accounts", ["id(value)", "name(text)"], ["deleted" => 0, "status" => 'A']);
    //         $vars['accounts'] = array_merge([['value' => '', 'text' => $jatbi->lang('Tất cả')]], $accounts_db);


    //         echo $app->render($template . '/warehouses/logs-ingredient.html', $vars);
    //     } elseif ($app->method() === 'POST') {
    //         $app->header(['Content-Type' => 'application/json; charset=utf-8']);
    //         $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
    //         $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
    //         $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
    //         $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
    //         $orderName = isset($_POST['order'][0]['column']) ? $_POST['columns'][$_POST['order'][0]['column']]['name'] : 'logs.id';
    //         $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';



    //         $filter_user = $_POST['user'] ?? '';
    //         $filter_date = $_POST['date'] ?? '';
    //         $type = $vars['type'] ?? 'products';


    //         if (empty($filter_date)) {
    //             $date_from = date('Y-m-d 00:00:00', strtotime($setting['site_start'] ?? '1970-01-01'));
    //             $date_to = date('Y-m-d 23:59:59');
    //         } else {
    //             $dates = explode(' - ', $filter_date);
    //             if (count($dates) === 2) {
    //                 $date_from = date('Y-m-d 00:00:00', strtotime(str_replace('/', '-', trim($dates[0]))));
    //                 $date_to = date('Y-m-d 23:59:59', strtotime(str_replace('/', '-', trim($dates[1]))));
    //             } else {
    //                 $date_from = date('Y-m-d 00:00:00', strtotime($setting['site_start'] ?? '1970-01-01'));
    //                 $date_to = date('Y-m-d 23:59:59');
    //             }
    //         }

    //         $where = [
    //             "AND" => [
    //                 "logs.deleted" => 0,
    //                 "logs.dispatch" => $type,
    //                 "logs.action" => ['add', 'edit', 'delete'],
    //                 "logs.date[<>]" => [$date_from, $date_to]
    //             ]
    //         ];

    //         if (!empty($searchValue)) {
    //             $where['AND']['OR'] = [
    //                 'logs.action[~]' => $searchValue,
    //             ];
    //         }

    //         if (!empty($filter_user)) {
    //             $where['AND']['logs.user'] = $filter_user;
    //         }

    //         $joins = ["[<]accounts" => ["user" => "id"]];
    //         $recordsFiltered = $app->count("logs", $joins, "logs.id", $where);
    //         $baseWhere = [
    //             "AND" => [
    //                 "logs.deleted" => 0,
    //                 "logs.dispatch" => $type,
    //                 "logs.action" => ['add', 'edit', 'delete'],
    //                 "logs.date[<>]" => [$date_from, $date_to]
    //             ]
    //         ];
    //         $recordsTotal = $app->count("logs", $joins, "logs.id", $baseWhere);

    //         $where["ORDER"] = [$orderName => strtoupper($orderDir)];
    //         $where["LIMIT"] = [$start, $length];
    //         $columns = ["logs.id", "logs.action", "logs.content", "logs.date", "logs.dispatch", "accounts.name(user_name)", "accounts.avatar(user_avatar)"];
    //         $datas = $app->select("logs", $joins, $columns, $where);


    //         $unit_map = [];
    //         $group_map = [];
    //         $category_map = [];
    //         $related_ids = ['units' => [], 'groups' => [], 'categorys' => []];
    //         foreach ($datas as $log) {
    //             $content = json_decode($log['content'], true);
    //             if (json_last_error() !== JSON_ERROR_NONE) {
    //                 $content = @unserialize($log['content']);
    //             }

    //             if (is_array($content)) {
    //                 if ($log['action'] == 'edit') {
    //                     $first_arr = $content[0] ?? $content;
    //                     $second_arr = $content[1] ?? [];
    //                     if (isset($first_arr['units']))
    //                         $related_ids['units'][] = $first_arr['units'];
    //                     if (isset($second_arr['units']))
    //                         $related_ids['units'][] = $second_arr['units'];
    //                     if (isset($first_arr['group']))
    //                         $related_ids['groups'][] = $first_arr['group'];
    //                     if (isset($second_arr['group']))
    //                         $related_ids['groups'][] = $second_arr['group'];
    //                     if (isset($first_arr['categorys']))
    //                         $related_ids['categorys'][] = $first_arr['categorys'];
    //                     if (isset($second_arr['categorys']))
    //                         $related_ids['categorys'][] = $second_arr['categorys'];
    //                 } else {
    //                     if (isset($content['units']))
    //                         $related_ids['units'][] = $content['units'];
    //                     if (isset($content['group']))
    //                         $related_ids['groups'][] = $content['group'];
    //                     if (isset($content['categorys']))
    //                         $related_ids['categorys'][] = $content['categorys'];
    //                 }
    //             }
    //         }
    //         if (!empty($related_ids['units'])) {
    //             $unit_map = array_column($app->select('units', ['id', 'name'], ['id' => array_unique($related_ids['units'])]), 'name', 'id');
    //         }
    //         if (!empty($related_ids['groups'])) {
    //             $group_map = array_column($app->select('products_group', ['id', 'name'], ['id' => array_unique($related_ids['groups'])]), 'name', 'id');
    //         }
    //         if (!empty($related_ids['categorys'])) {
    //             $category_map = array_column($app->select('categorys', ['id', 'name'], ['id' => array_unique($related_ids['categorys'])]), 'name', 'id');
    //         }

    //         $action_map = [
    //             'add' => 'Thêm',
    //             'edit' => 'Sửa',
    //             'delete' => 'Xóa'
    //         ];
    //         $resultData = [];
    //         foreach ($datas as $data) {
    //             $content = json_decode($data['content'], true);
    //             if (json_last_error() !== JSON_ERROR_NONE) {
    //                 $content = @unserialize($data['content']);
    //             }

    //             $content_new_str = '';
    //             $content_old_str = '';

    //             if (is_array($content)) {
    //                 if ($data['action'] == 'delete') {
    //                     if (isset($content[0]) && is_array($content[0])) {
    //                         $content_new_str = implode(', ', array_column($content, 'code'));
    //                     } else {
    //                         $content_new_str = $content['code'] ?? '';
    //                     }
    //                 } elseif ($data['action'] == 'edit') {
    //                     $content_new_data = $content[0] ?? [];
    //                     $content_old_data = $content[1] ?? [];
    //                 } else { // add
    //                     $content_new_data = $content;
    //                     $content_old_data = [];
    //                 }

    //                 if (isset($content_new_data) && !empty($content_new_data)) {
    //                     $price_new = isset($content_new_data['price']) ? (float) $content_new_data['price'] : 0;
    //                     $content_new_str = 'Sản phẩm: ' . ($content_new_data['code'] ?? '') .
    //                         ', tên sản phẩm: ' . ($content_new_data['name'] ?? '') .
    //                         ', nhóm sản phẩm: ' . ($group_map[$content_new_data['group']] ?? '') .
    //                         ', danh mục: ' . ($category_map[$content_new_data['categorys']] ?? '') .
    //                         ', đơn vị: ' . ($unit_map[$content_new_data['units']] ?? '') .
    //                         ', số tiền: ' . number_format($price_new) .
    //                         ', thuế VAT: ' . ($content_new_data['vat'] ?? '') .
    //                         ', nội dung: ' . ($content_new_data['notes'] ?? '');
    //                 }
    //                 if (isset($content_old_data) && !empty($content_old_data)) {
    //                     $price_old = isset($content_old_data['price']) ? (float) $content_old_data['price'] : 0;
    //                     $content_old_str = 'Sản phẩm: ' . ($content_old_data['code'] ?? '') .
    //                         ', tên sản phẩm: ' . ($content_old_data['name'] ?? '') .
    //                         ', nhóm sản phẩm: ' . ($group_map[$content_old_data['group']] ?? '') .
    //                         ', danh mục: ' . ($category_map[$content_old_data['categorys']] ?? '') .
    //                         ', đơn vị: ' . ($unit_map[$content_old_data['units']] ?? '') .
    //                         ', số tiền: ' . number_format($price_old) .
    //                         ', thuế VAT: ' . ($content_old_data['vat'] ?? '') .
    //                         ', nội dung: ' . ($content_old_data['notes'] ?? '');
    //                 }
    //             }
    //             $resultData[] = [
    //                 "user" => '<img src="/' . ($setting['upload']['images']['avatar']['url'] ?? '') . ($data['user_avatar'] ?? 'default.png') . '" class="avatar-sm rounded-circle me-2">' . ($data['user_name'] ?? 'N/A'),
    //                 "action" => $action_map[$data['action']] ?? ucfirst($data['action']),
    //                 "dispatch" => $data['dispatch'] == 'ingredient' ? 'Nguyên liệu' : 'Sản phẩm',
    //                 "content_new" => $content_new_str,
    //                 "content_old" => $content_old_str,
    //                 "date" => $jatbi->datetime($data['date']),
    //                 "date_sort" => $data['date']
    //             ];
    //         }
    //         echo json_encode([
    //             "draw" => $draw,
    //             "recordsTotal" => $recordsTotal,
    //             "recordsFiltered" => $recordsFiltered,
    //             "data" => $resultData
    //         ]);
    //     }
    // })->setPermissions(['ingredient']);








    $app->router('/logs_warehouses/ingredient', ['GET', 'POST'], function ($vars) use ($app, $jatbi,$template, $setting) {

        $vars['title'] = $jatbi->lang("Nhật ký Nguyên liệu");
        $vars['type'] = 'ingredient';

        if ($app->method() === 'GET') {
            $accounts_db = $app->select("accounts", ["id(value)", "name(text)"], ["deleted" => 0, "status" => 'A']);
            $vars['accounts'] = array_merge([['value' => '', 'text' => $jatbi->lang('Tất cả')]], $accounts_db);
            echo $app->render($template . '/warehouses/logs-ingredient.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);
            $draw = $_POST['draw'] ?? 0;
            $start = $_POST['start'] ?? 0;
            $length = $_POST['length'] ?? 10;
            $searchValue = $_POST['search']['value'] ?? '';

            $orderName = $_POST['columns'][intval($_POST['order'][0]['column'] ?? 0)]['date'] ?? 'logs.date';
            $orderDir = $_POST['order'][0]['dir'] ?? 'DESC';

            $filter_user = $_POST['user'] ?? '';
            $filter_date = $_POST['date'] ?? '';
            $type = $vars['type'] ?? 'products';


            if (empty($filter_date)) {
                $date_from = date('Y-m-d 00:00:00', strtotime($setting['site_start'] ?? '1970-01-01'));
                $date_to = date('Y-m-d 23:59:59');
            } else {
                $dates = explode(' - ', $filter_date);
                $date_from = date('Y-m-d 00:00:00', strtotime(str_replace('/', '-', trim($dates[0]))));
                $date_to = date('Y-m-d 23:59:59', strtotime(str_replace('/', '-', trim($dates[1]))));
            }

            $where = [
                "AND" => [
                    "logs.deleted" => 0,
                    "logs.dispatch" => $type,
                    "logs.action" => ['add', 'edit', 'deleted'],
                    "logs.date[<>]" => [$date_from, $date_to]
                ]
            ];

            if (!empty($filter_user)) {
                $where['AND']['logs.user'] = $filter_user;
            }

            $joins = ["[<]accounts" => ["user" => "id"]];


            $recordsTotal = $app->count("logs", $joins, "logs.id", $where);

            if (!empty($searchValue)) {
                $where['AND']['OR'] = [
                    'logs.action[~]' => $searchValue,
                    'logs.content[~]' => $searchValue,
                ];

                $recordsFiltered = $app->count("logs", $joins, "logs.id", $where);
            } else {

                $recordsFiltered = $recordsTotal;
            }

            $where["ORDER"] = [$orderName => strtoupper($orderDir)];
            $where["LIMIT"] = [$start, $length];
            $columns = ["logs.id", "logs.action", "logs.content", "logs.date", "logs.dispatch", "accounts.name(user_name)", "accounts.avatar(user_avatar)"];
            $datas = $app->select("logs", $joins, $columns, $where);


            $related_ids = ['units' => [], 'groups' => [], 'categorys' => []];


            foreach ($datas as $key => &$log) {
                $content = json_decode($log['content'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $content = @unserialize($log['content']);
                }
                $log['decoded_content'] = is_array($content) ? $content : [];

                $content_arrays = [];
                if ($log['action'] == 'edit') {
                    if (isset($log['decoded_content'][0]))
                        $content_arrays[] = $log['decoded_content'][0];
                    if (isset($log['decoded_content'][1]))
                        $content_arrays[] = $log['decoded_content'][1];
                } else {
                    $content_arrays[] = $log['decoded_content'];
                }

                foreach ($content_arrays as $c) {
                    if (isset($c['units']))
                        $related_ids['units'][$c['units']] = true;
                    if (isset($c['group']))
                        $related_ids['groups'][$c['group']] = true;
                    if (isset($c['categorys']))
                        $related_ids['categorys'][$c['categorys']] = true;
                }
            }
            unset($log);


            $unit_map = !empty($related_ids['units']) ? array_column($app->select('units', ['id', 'name'], ['id' => array_keys($related_ids['units'])]), 'name', 'id') : [];
            $group_map = !empty($related_ids['groups']) ? array_column($app->select('products_group', ['id', 'name'], ['id' => array_keys($related_ids['groups'])]), 'name', 'id') : [];
            $category_map = !empty($related_ids['categorys']) ? array_column($app->select('categorys', ['id', 'name'], ['id' => array_keys($related_ids['categorys'])]), 'name', 'id') : [];


            function buildContentString($data, $maps)
            {
                if (empty($data) || !is_array($data))
                    return '';
                $price = isset($data['price']) ? (float) $data['price'] : 0;
                return 'Sản phẩm: ' . ($data['code'] ?? '') .
                    ', tên: ' . ($data['name'] ?? '') .
                    ', nhóm: ' . ($maps['group_map'][$data['group']] ?? '') .
                    ', danh mục: ' . ($maps['category_map'][$data['categorys']] ?? '') .
                    ', đơn vị: ' . ($maps['unit_map'][$data['units']] ?? '') .
                    ', số tiền: ' . number_format($price) .
                    ', VAT: ' . ($data['vat'] ?? '') .
                    ', nội dung: ' . ($data['notes'] ?? '');
            }

            $action_map = ['add' => 'Thêm', 'edit' => 'Sửa', 'deleted' => 'Xóa'];
            $resultData = [];
            $maps = ['unit_map' => $unit_map, 'group_map' => $group_map, 'category_map' => $category_map];


            foreach ($datas as $data) {
                $content = $data['decoded_content'];
                $content_new_str = '';
                $content_old_str = '';


                if ($data['action'] == 'deleted') {
                    // SỬA LẠI ĐIỀU KIỆN IF TẠI ĐÂY
                    if (!empty($content) && is_array(reset($content))) { // Kiểm tra xóa nhiều/một cách linh hoạt
                        $content_new_str = implode(', ', array_column($content, 'code'));
                    } else { // Trường hợp dữ liệu không đúng định dạng
                        $content_new_str = $content['code'] ?? 'Không rõ';
                    }
                } elseif ($data['action'] == 'edit') {
                    $content_new_str = buildContentString($content[0] ?? [], $maps);
                    $content_old_str = buildContentString($content[1] ?? [], $maps);
                } else { // 'add'
                    $content_new_str = buildContentString($content, $maps);
                }

                $resultData[] = [
                    "user" => '<img src="/' . ($setting['upload']['images']['avatar']['url'] ?? '') . ($data['user_avatar'] ?? 'default.png') . '" class="avatar-sm rounded-circle me-2">' . ($data['user_name'] ?? 'N/A'),
                    "action" => $action_map[$data['action']] ?? ucfirst($data['action']),
                    "dispatch" => $data['dispatch'] == 'ingredient' ? 'Nguyên liệu' : 'Sản phẩm',
                    "content_new" => $content_new_str,
                    "content_old" => $content_old_str,
                    "date" => $jatbi->datetime($data['date']),
                    "date_sort" => $data['date']
                ];
            }

            echo json_encode([
                "draw" => (int) $draw,
                "recordsTotal" => (int) $recordsTotal,
                "recordsFiltered" => (int) $recordsFiltered,
                "data" => $resultData
            ]);
        }
    })->setPermissions(['ingredient']);

    // $app->router('/logs_warehouses/products', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $setting) {

    //     $vars['title'] = $jatbi->lang("Nhật ký Sản phẩm");
    //     $vars['type'] = 'products';

    //     if ($app->method() === 'GET') {

    //         $accounts_db = $app->select("accounts", ["id(value)", "name(text)"], ["deleted" => 0, "status" => 'A']);
    //         $vars['accounts'] = array_merge([['value' => '', 'text' => $jatbi->lang('Tất cả')]], $accounts_db);

    //         echo $app->render($template . '/warehouses/logs-products.html', $vars);

    //     } elseif ($app->method() === 'POST') {

    //         $app->header(['Content-Type' => 'application/json; charset=utf-8']);
    //         $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
    //         $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
    //         $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
    //         $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
    //         $orderName = isset($_POST['order'][0]['column']) ? $_POST['columns'][$_POST['order'][0]['column']]['name'] : 'logs.id';
    //         $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

    //         $filter_user = $_POST['user'] ?? '';
    //         $filter_date = $_POST['date'] ?? '';

    //         $type = $vars['type'] ?? 'products';

    //         if (empty($filter_date)) {
    //             $date_from = date('Y-m-d 00:00:00', strtotime($setting['site_start'] ?? '1970-01-01'));
    //             $date_to = date('Y-m-d 23:59:59');
    //         } else {
    //             $dates = explode(' - ', $filter_date);
    //             if (count($dates) === 2) {
    //                 $date_from = date('Y-m-d 00:00:00', strtotime(str_replace('/', '-', trim($dates[0]))));
    //                 $date_to = date('Y-m-d 23:59:59', strtotime(str_replace('/', '-', trim($dates[1]))));
    //             } else {
    //                 $date_from = date('Y-m-d 00:00:00', strtotime($setting['site_start'] ?? '1970-01-01'));
    //                 $date_to = date('Y-m-d 23:59:59');
    //             }
    //         }


    //         $where = [
    //             "AND" => [
    //                 "logs.deleted" => 0,
    //                 "logs.dispatch" => $type,
    //                 "logs.action" => ['add', 'edit', 'delete'],
    //                 "logs.date[<>]" => [$date_from, $date_to]
    //             ]
    //         ];

    //         if (!empty($searchValue)) {
    //             $where['AND']['OR'] = [
    //                 'logs.action[~]' => $searchValue,
    //             ];
    //         }

    //         if (!empty($filter_user)) {
    //             $where['AND']['logs.user'] = $filter_user;
    //         }

    //         $joins = ["[<]accounts" => ["user" => "id"]];
    //         $recordsFiltered = $app->count("logs", $joins, "logs.id", $where);


    //         $baseWhere = [
    //             "AND" => [
    //                 "logs.deleted" => 0,
    //                 "logs.dispatch" => $type,
    //                 "logs.action" => ['add', 'edit', 'delete']
    //             ]
    //         ];
    //         $recordsTotal = $app->count("logs", $joins, "logs.id", $baseWhere);

    //         $where["ORDER"] = [$orderName => strtoupper($orderDir)];
    //         $where["LIMIT"] = [$start, $length];
    //         $columns = ["logs.id", "logs.action", "logs.content", "logs.date", "logs.dispatch", "accounts.name(user_name)", "accounts.avatar(user_avatar)"];
    //         $datas = $app->select("logs", $joins, $columns, $where);


    //         $unit_map = [];
    //         $group_map = [];
    //         $category_map = [];
    //         $related_ids = ['units' => [], 'groups' => [], 'categorys' => []];
    //         foreach ($datas as $log) {
    //             $content = json_decode($log['content'], true);
    //             if (json_last_error() !== JSON_ERROR_NONE) {
    //                 $content = @unserialize($log['content']);
    //             }

    //             if (is_array($content)) {
    //                 if ($log['action'] == 'edit') {
    //                     $first_arr = $content[0] ?? $content;
    //                     $second_arr = $content[1] ?? [];
    //                     if (isset($first_arr['units']))
    //                         $related_ids['units'][] = $first_arr['units'];
    //                     if (isset($second_arr['units']))
    //                         $related_ids['units'][] = $second_arr['units'];
    //                     if (isset($first_arr['group']))
    //                         $related_ids['groups'][] = $first_arr['group'];
    //                     if (isset($second_arr['group']))
    //                         $related_ids['groups'][] = $second_arr['group'];
    //                     if (isset($first_arr['categorys']))
    //                         $related_ids['categorys'][] = $first_arr['categorys'];
    //                     if (isset($second_arr['categorys']))
    //                         $related_ids['categorys'][] = $second_arr['categorys'];
    //                 } else {
    //                     if (isset($content['units']))
    //                         $related_ids['units'][] = $content['units'];
    //                     if (isset($content['group']))
    //                         $related_ids['groups'][] = $content['group'];
    //                     if (isset($content['categorys']))
    //                         $related_ids['categorys'][] = $content['categorys'];
    //                 }
    //             }
    //         }
    //         if (!empty($related_ids['units'])) {
    //             $unit_map = array_column($app->select('units', ['id', 'name'], ['id' => array_unique($related_ids['units'])]), 'name', 'id');
    //         }
    //         if (!empty($related_ids['groups'])) {
    //             $group_map = array_column($app->select('products_group', ['id', 'name'], ['id' => array_unique($related_ids['groups'])]), 'name', 'id');
    //         }
    //         if (!empty($related_ids['categorys'])) {
    //             $category_map = array_column($app->select('categorys', ['id', 'name'], ['id' => array_unique($related_ids['categorys'])]), 'name', 'id');
    //         }

    //         $action_map = [
    //             'add' => 'Thêm',
    //             'edit' => 'Sửa',
    //             'delete' => 'Xóa'
    //         ];

    //         $resultData = [];
    //         foreach ($datas as $data) {
    //             $content = json_decode($data['content'], true);
    //             if (json_last_error() !== JSON_ERROR_NONE) {
    //                 $content = @unserialize($data['content']);
    //             }

    //             $content_new_str = '';
    //             $content_old_str = '';

    //             if (is_array($content)) {
    //                 if ($data['action'] == 'delete') {
    //                     if (isset($content[0]) && is_array($content[0])) {
    //                         $content_new_str = implode(', ', array_column($content, 'code'));
    //                     } else {
    //                         $content_new_str = $content['code'] ?? '';
    //                     }
    //                 } elseif ($data['action'] == 'edit') {
    //                     $content_new_data = $content[0] ?? [];
    //                     $content_old_data = $content[1] ?? [];
    //                 } else { // add
    //                     $content_new_data = $content;
    //                     $content_old_data = [];
    //                 }

    //                 if (isset($content_new_data) && !empty($content_new_data)) {
    //                     $price_new = isset($content_new_data['price']) ? (float) $content_new_data['price'] : 0;
    //                     $content_new_str = 'Sản phẩm: ' . ($content_new_data['code'] ?? '') .
    //                         ', tên sản phẩm: ' . ($content_new_data['name'] ?? '') .
    //                         ', nhóm sản phẩm: ' . ($group_map[$content_new_data['group']] ?? '') .
    //                         ', danh mục: ' . ($category_map[$content_new_data['categorys']] ?? '') .
    //                         ', đơn vị: ' . ($unit_map[$content_new_data['units']] ?? '') .
    //                         ', số tiền: ' . number_format($price_new) .
    //                         ', thuế VAT: ' . ($content_new_data['vat'] ?? '') .
    //                         ', nội dung: ' . ($content_new_data['notes'] ?? '');
    //                 }
    //                 if (isset($content_old_data) && !empty($content_old_data)) {
    //                     $price_old = isset($content_old_data['price']) ? (float) $content_old_data['price'] : 0;
    //                     $content_old_str = 'Sản phẩm: ' . ($content_old_data['code'] ?? '') .
    //                         ', tên sản phẩm: ' . ($content_old_data['name'] ?? '') .
    //                         ', nhóm sản phẩm: ' . ($group_map[$content_old_data['group']] ?? '') .
    //                         ', danh mục: ' . ($category_map[$content_old_data['categorys']] ?? '') .
    //                         ', đơn vị: ' . ($unit_map[$content_old_data['units']] ?? '') .
    //                         ', số tiền: ' . number_format($price_old) .
    //                         ', thuế VAT: ' . ($content_old_data['vat'] ?? '') .
    //                         ', nội dung: ' . ($content_old_data['notes'] ?? '');
    //                 }
    //             }
    //             $resultData[] = [
    //                 "user" => '<img src="/' . ($setting['upload']['images']['avatar']['url'] ?? '') . ($data['user_avatar'] ?? 'default.png') . '" class="avatar-sm rounded-circle me-2">' . ($data['user_name'] ?? 'N/A'),
    //                 "action" => $action_map[$data['action']] ?? ucfirst($data['action']),

    //                 "dispatch" => $data['dispatch'] == 'ingredient' ? 'Nguyên liệu' : 'Sản phẩm',
    //                 "content_new" => $content_new_str,
    //                 "content_old" => $content_old_str,
    //                 "date" => $jatbi->datetime($data['date']),
    //             ];
    //         }
    //         echo json_encode([
    //             "draw" => $draw,
    //             "recordsTotal" => $recordsTotal,
    //             "recordsFiltered" => $recordsFiltered,
    //             "data" => $resultData
    //         ]);
    //     }

    // })->setPermissions(['products']);







    $app->router('/logs_warehouses/products', ['GET', 'POST'], function ($vars) use ($app, $jatbi,$template, $setting) {

        $vars['title'] = $jatbi->lang("Nhật ký Sản phẩm");
        $vars['type'] = 'products';

        if ($app->method() === 'GET') {
            $accounts_db = $app->select("accounts", ["id(value)", "name(text)"], ["deleted" => 0, "status" => 'A']);
            $vars['accounts'] = array_merge([['value' => '', 'text' => $jatbi->lang('Tất cả')]], $accounts_db);
            echo $app->render($template . '/warehouses/logs-products.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);
            $draw = $_POST['draw'] ?? 0;
            $start = $_POST['start'] ?? 0;
            $length = $_POST['length'] ?? 10;
            $searchValue = $_POST['search']['value'] ?? '';
            $orderName = isset($_POST['order'][0]['column']) ? $_POST['columns'][$_POST['order'][0]['column']]['name'] : 'logs.id';
            $orderDir = $_POST['order'][0]['dir'] ?? 'DESC';

            $filter_user = $_POST['user'] ?? '';
            $filter_date = $_POST['date'] ?? '';
            $type = $vars['type']; // Lấy 'products' từ biến đã định nghĩa

            if (empty($filter_date)) {
                $date_from = date('Y-m-d 00:00:00', strtotime($setting['site_start'] ?? '1970-01-01'));
                $date_to = date('Y-m-d 23:59:59');
            } else {
                $dates = explode(' - ', $filter_date);
                $date_from = date('Y-m-d 00:00:00', strtotime(str_replace('/', '-', trim($dates[0]))));
                $date_to = date('Y-m-d 23:59:59', strtotime(str_replace('/', '-', trim($dates[1]))));
            }

            $where = ["AND" => ["logs.deleted" => 0, "logs.dispatch" => $type, "logs.action" => ['add', 'edit', 'deleted'], "logs.date[<>]" => [$date_from, $date_to]]];
            if (!empty($filter_user))
                $where['AND']['logs.user'] = $filter_user;

            $joins = ["[<]accounts" => ["user" => "id"]];

            $recordsTotal = $app->count("logs", $joins, "logs.id", $where);

            if (!empty($searchValue)) {
                $where['AND']['OR'] = ['logs.action[~]' => $searchValue, 'logs.content[~]' => $searchValue];
                $recordsFiltered = $app->count("logs", $joins, "logs.id", $where);
            } else {
                $recordsFiltered = $recordsTotal;
            }

            $where["ORDER"] = [$orderName => strtoupper($orderDir)];
            $where["LIMIT"] = [$start, $length];
            $columns = ["logs.id", "logs.action", "logs.content", "logs.date", "logs.dispatch", "accounts.name(user_name)", "accounts.avatar(user_avatar)"];
            $datas = $app->select("logs", $joins, $columns, $where);

            $related_ids = ['units' => [], 'groups' => [], 'categorys' => []];

            foreach ($datas as &$log) {
                $content = json_decode($log['content'], true);
                if (json_last_error() !== JSON_ERROR_NONE)
                    $content = @unserialize($log['content']);
                $log['decoded_content'] = is_array($content) ? $content : [];

                $content_arrays = [];
                if ($log['action'] == 'edit') {
                    if (isset($log['decoded_content'][0]))
                        $content_arrays[] = $log['decoded_content'][0];
                    if (isset($log['decoded_content'][1]))
                        $content_arrays[] = $log['decoded_content'][1];
                } else {
                    $content_arrays[] = $log['decoded_content'];
                }

                foreach ($content_arrays as $c) {
                    if (isset($c['units']))
                        $related_ids['units'][$c['units']] = true;
                    if (isset($c['group']))
                        $related_ids['groups'][$c['group']] = true;
                    if (isset($c['categorys']))
                        $related_ids['categorys'][$c['categorys']] = true;
                }
            }
            unset($log);

            $unit_map = !empty($related_ids['units']) ? array_column($app->select('units', ['id', 'name'], ['id' => array_keys($related_ids['units'])]), 'name', 'id') : [];
            $group_map = !empty($related_ids['groups']) ? array_column($app->select('products_group', ['id', 'name'], ['id' => array_keys($related_ids['groups'])]), 'name', 'id') : [];
            $category_map = !empty($related_ids['categorys']) ? array_column($app->select('categorys', ['id', 'name'], ['id' => array_keys($related_ids['categorys'])]), 'name', 'id') : [];

            function buildContentString($data, $maps)
            {
                if (empty($data) || !is_array($data))
                    return '';
                $price = isset($data['price']) ? (float) $data['price'] : 0;
                return 'Sản phẩm: ' . ($data['code'] ?? '') .
                    ', tên: ' . ($data['name'] ?? $data['name_ingredient'] ?? '') .
                    ', nhóm: ' . (isset($data['group']) ? ($maps['group_map'][$data['group']] ?? '') : '') .
                    ', danh mục: ' . (isset($data['categorys']) ? ($maps['category_map'][$data['categorys']] ?? '') : '') .
                    ', đơn vị: ' . (isset($data['units']) ? ($maps['unit_map'][$data['units']] ?? '') : '') .
                    ', số tiền: ' . number_format($price) .
                    ', VAT: ' . ($data['vat'] ?? '') .
                    ', nội dung: ' . ($data['notes'] ?? '');
            }

            $action_map = ['add' => 'Thêm', 'edit' => 'Sửa', 'deleted' => 'Xóa'];
            $resultData = [];
            $maps = ['unit_map' => $unit_map, 'group_map' => $group_map, 'category_map' => $category_map];

            foreach ($datas as $data) {
                $content = $data['decoded_content'];
                $content_new_str = '';
                $content_old_str = '';

                if ($data['action'] == 'deleted') {
                    // SỬA LẠI TẠI ĐÂY: Đồng bộ logic với ingredient và lấy đúng cột 'name'
                    if (!empty($content) && is_array(reset($content))) {
                        $content_new_str = implode(', ', array_column($content, 'name'));
                    } else {
                        $content_new_str = $content['name'] ?? 'Không rõ';
                    }
                } elseif ($data['action'] == 'edit') {
                    $content_new_str = buildContentString($content[0] ?? [], $maps);
                    $content_old_str = buildContentString($content[1] ?? [], $maps);
                } else { // 'add'
                    $content_new_str = buildContentString($content, $maps);
                }

                $resultData[] = [
                    "user" => '<img src="/' . ($setting['upload']['images']['avatar']['url'] ?? '') . ($data['user_avatar'] ?? 'default.png') . '" class="avatar-sm rounded-circle me-2">' . ($data['user_name'] ?? 'N/A'),
                    "action" => $action_map[$data['action']] ?? ucfirst($data['action']),
                    "dispatch" => $data['dispatch'] == 'ingredient' ? 'Nguyên liệu' : 'Sản phẩm',
                    "content_new" => $content_new_str,
                    "content_old" => $content_old_str,
                    "date" => $jatbi->datetime($data['date']),
                    "date_sort" => $data['date']
                ];
            }

            echo json_encode([
                "draw" => (int) $draw,
                "recordsTotal" => (int) $recordsTotal,
                "recordsFiltered" => (int) $recordsFiltered,
                "data" => $resultData
            ]);
        }
    })->setPermissions(['products']);

    $app->router('/ingredient-history-views/{id}', ['GET'], function ($vars) use ($app, $jatbi, $template) {

        $id = (int) ($vars['id'] ?? 0);
        if ($id === 0) {
            $app->error(404, "Không tìm thấy ID phiếu kho.");
            return;
        }


        $data = $app->get("warehouses", [
            "[>]accounts" => ["user" => "id"]
        ], [
            "warehouses.id",
            "warehouses.code",
            "warehouses.date",
            "warehouses.date_poster",
            "warehouses.content",
            "warehouses.type",
            "accounts.name(user_name)"
        ], [
            "warehouses.id" => $id
        ]);

        if (!$data) {
            $app->error(404, "Không tìm thấy phiếu kho với ID: " . $id);
            return;
        }


        $details = $app->select("warehouses_logs", [
            "[>]ingredient" => ["ingredient" => "id"],
            "[>]vendors" => ["ingredient.vendor" => "id"],
            "[>]units" => ["ingredient.units" => "id"],
        ], [
            "warehouses_logs.amount",
            "warehouses_logs.price",
            "ingredient.code(ingredient_code)",
            "vendors.name(vendor_name)",
            "units.name(unit_name)"
        ], [
            "warehouses_logs.warehouses" => $data['id'],
            "warehouses_logs.deleted" => 0
        ]);


        $total_amount = 0;
        $total_price = 0;
        $grand_total = 0;
        foreach ($details as $item) {
            $total_amount += $item['amount'];
            $total_price += $item['price'];
            $grand_total += $item['amount'] * $item['price'];
        }

        $vars['title'] = $jatbi->lang('Chi tiết phiếu') . ' #' . $data['code'] . $data['id'];



        $vars['data'] = $data;
        $vars['details'] = $details;
        $vars['total_amount'] = $total_amount;
        $vars['total_price'] = $total_price;
        $vars['grand_total'] = $grand_total;


        echo $app->render($template . '/warehouses/ingredient-history-views.html', $vars, $jatbi->ajax());
    });

    $app->router('/products-delete-move', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template, $stores) {
        $vars['title'] = $jatbi->lang("Đề xuất hủy hóa đơn");

        if ($app->method() === 'GET') {

            array_unshift($stores, [
                'value' => '',
                'text' => $jatbi->lang('Tất cả')
            ]);
            $vars['stores'] = $stores;
            $vars['accounts'] = array_merge(
                [['value' => '', 'text' => $jatbi->lang('Tất cả Tài khoản')]],
                $app->select("accounts", ["id(value)", "name(text)"], ["deleted" => 0, "status" => 'A'])
            );
            echo $app->render($template . '/warehouses/products-delete-move.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);


            $draw = intval($_POST['draw'] ?? 0);
            $start = intval($_POST['start'] ?? 0);
            $length = intval($_POST['length'] ?? 10);
            $searchValue = $_POST['search']['value'] ?? '';
            $orderName = $_POST['columns'][intval($_POST['order'][0]['column'] ?? 0)]['name'] ?? 'process_deleted.id';
            $orderDir = $_POST['order'][0]['dir'] ?? 'DESC';

            $filter_date = $_POST['date'] ?? '';
            $filter_stores = $_POST['stores'] ?? '';
            $filter_user = $_POST['user'] ?? '';
            $filter_status = $_POST['status'] ?? '';



            $where = ["AND" => ["process_deleted.deleted" => 0]];

            if (!empty($searchValue))
                $where['AND']['OR'] = ["p.code[~]" => $searchValue, "p.name[~]" => $searchValue];
            if (!empty($filter_stores))
                $where['AND']['process_deleted.stores'] = $filter_stores;
            if (!empty($filter_status))
                $where['AND']['process_deleted.process'] = $filter_status;
            if (!empty($filter_user))
                $where['AND']['process_deleted.user'] = $filter_user;
            if (!empty($filter_date)) {
                $dates = explode(' - ', $filter_date);
                if (count($dates) === 2) {
                    $date_from = date('Y-m-d 00:00:00', strtotime(str_replace('/', '-', trim($dates[0]))));
                    $date_to = date('Y-m-d 23:59:59', strtotime(str_replace('/', '-', trim($dates[1]))));
                } else {

                    $date_from = date('Y-m-d 00:00:00');
                    $date_to = date('Y-m-d 23:59:59');
                }
            } else {

                $date_from = date('Y-m-d 00:00:00');
                $date_to = date('Y-m-d 23:59:59');
            }
            $where["AND"]["process_deleted.date[<>]"] = [$date_from, $date_to];

            $joins = [
                "[<]products(p)" => ["products" => "id"],
                "[<]stores(s)" => ["stores" => "id"],
                "[<]accounts(a)" => ["user" => "id"],
            ];


            $count = $app->count("process_deleted", $joins, "process_deleted.id", $where);
            $total_sum = $app->sum("process_deleted", "amount", $where);

            // var_dump($total_sum);

            $where["ORDER"] = [$orderName => strtoupper($orderDir)];
            $where["LIMIT"] = [$start, $length];


            $columns = ["process_deleted.id", "process_deleted.amount", "process_deleted.date", "process_deleted.process", "p.code(product_code)", "p.name(product_name)", "s.name(store_name)", "a.name(user_name)"];
            $datas = $app->select("process_deleted", $joins, $columns, $where);

            $process_map = [
                1 => ['name' => $jatbi->lang('Chờ duyệt xóa'), 'color' => 'warning'],
                2 => ['name' => $jatbi->lang('Đã xác nhận xóa'), 'color' => 'success'],
                3 => ['name' => $jatbi->lang('Không duyệt xóa'), 'color' => 'danger']
            ];

            $resultData = [];
            $sum = 0;
            foreach ($datas as $data) {
                $status = $process_map[$data['process']] ?? ['name' => 'Không rõ', 'color' => 'secondary'];
                $sum += $data['amount'];
                $resultData[] = [
                    "id" => '<a class="info" data-action="modal" data-url="/warehouses/products-delete-views/' . $data['id'] . '">#' . $data['id'] . '</a>',
                    "product" => $data['product_code'] . ' - ' . $data['product_name'],
                    "amount" => number_format($data['amount'], 1),
                    "store" => $data['store_name'],
                    "date" => $jatbi->datetime($data['date']),
                    "user" => $data['user_name'],
                    "status" => '<button data-action="modal" class="btn btn-sm btn-' . $status['color'] . '" data-url="/warehouses/products-process/' . $data['id'] . '">' . $status['name'] . '</button>',
                    "action" => '<button data-action="modal" data-url="/warehouses/products-delete-views/' . $data['id'] . '"" class="btn btn-eclo-light btn-sm border-0 py-1 px-2 rounded-3" aria-label="' . $jatbi->lang('Xem') . '"><i class="ti ti-eye"></i></button>',
                ];
            }

            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => $count,
                "recordsFiltered" => $count,
                "data" => $resultData,
                "footerData" => [
                    "sum" => number_format($sum ?? 0),
                    "sum_amount" => $total_sum ?? 0
                ]
            ]);
        }
    })->setPermissions(['products']);

    $app->router('/products-delete-views/{id}', 'GET', function ($vars) use ($app, $jatbi, $template) {
        $id = $vars['id'] ?? 0;
        $data = $app->get("process_deleted", [
            "[<]products" => ["products" => "id"],
            "[<]accounts(user_propose)" => ["user" => "id"],
            "[<]units" => ["products.units" => "id"],
            "[<]warehouses" => ["warehouses" => "id"],
            "[<]accounts(user_approve)" => ["warehouses.user" => "id"],
        ], [
            "process_deleted.id",
            "process_deleted.date",
            "process_deleted.amount",
            "process_deleted.price",
            "process_deleted.notes",
            "process_deleted.process",
            "products.code(product_code)",
            "products.name(product_name)",
            "units.name(unit_name)",
            "user_propose.name(proposer_name)",
            "user_approve.name(approver_name)",
        ], ["process_deleted.id" => $id]);

        if (!$data) {
            header("HTTP/1.0 404 Not Found");
            exit;
        }

        $vars['data'] = $data;
        $vars['title'] = $jatbi->lang('Chi tiết đề xuất xóa') . ' #' . $data['id'];

        echo $app->render($template . '/warehouses/products-delete-views.html', $vars, $jatbi->ajax());
    })->setPermissions(['products']);

    $app->router('/products-process/{id}', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $id = $vars['id'] ?? 0;


        $data = $app->get("process_deleted", [
            "[<]products" => ["products" => "id"],
            "[<]units" => ["products.units" => "id"],
            "[<]accounts(user_propose)" => ["user" => "id"],
            "[<]stores" => ["stores" => "id"],
            "[<]branch" => ["branch" => "id"],
            "[<]warehouses" => ["warehouses" => "id"],
            "[<]accounts(user_approve)" => ["warehouses.user" => "id"],
        ], [

            "process_deleted.id",
            "process_deleted.date",
            "process_deleted.amount",
            "process_deleted.price",
            "process_deleted.notes",
            "process_deleted.process",
            "process_deleted.stores",
            "process_deleted.branch",
            "process_deleted.products",
            "process_deleted.warehouses",
            "process_deleted.type(warehouses_type)",
            "process_deleted.crafting",
            "products.code(product_code)",
            "products.name(product_name)",
            "units.name(unit_name)",
            "user_propose.name(proposer_name)",
            "user_approve.name(approver_name)",
            "stores.name(store_name)",
            "branch.name(branch_name)",
        ], ["process_deleted.id" => $id]);

        if (!$data) {
            echo $app->render($template . '/error.html', ['content' => 'Không tìm thấy yêu cầu'], $jatbi->ajax());
            return;
        }

        $vars['data'] = $data;
        $vars['title'] = $jatbi->lang("Xử lý yêu cầu hủy") . ' #' . $data['id'];

        if ($app->method() === 'GET') {
            echo $app->render($template . '/warehouses/products-process.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {

            $app->header([
                'Content-Type' => 'application/json',
            ]);
            $post = array_map([$app, 'xss'], $_POST);

            if (empty($post['process'])) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Vui lòng chọn trạng thái xử lý")]);
                return;
            }

            $update = ["process" => $post['process'], "notes" => $post['notes']];
            $app->update("process_deleted", $update, ["id" => $id]);
            $jatbi->logs('process_deleted', 'process', $update);


            if ($post['process'] == 2) {
                $warehouses_insert = [
                    "type" => "error",
                    "data" => "products",
                    "content" => "Duyệt xóa sản phẩm: #" . $data['product_code'],
                    "stores" => $data['stores'],
                    "branch" => $data['branch'],
                    "user" => $app->getSession("accounts")['id'],
                    "date" => date("Y-m-d H:i:s"),
                    "date_poster" => date("Y-m-d H:i:s"),
                    "active" => $jatbi->active(30),
                ];
                $app->insert("warehouses", $warehouses_insert);
                $orderId = $app->id();

                $details_insert = [
                    "warehouses" => $orderId,
                    "data" => "products",
                    "type" => "error",
                    "products" => $data['products'],
                    "amount" => $data['amount'],
                    "amount_total" => $data['amount'],
                    "price" => $data['price'],
                    "notes" => $post['notes'],
                    "date" => date("Y-m-d H:i:s"),
                    "user" => $app->getSession("accounts")['id'],
                    "stores" => $data['stores'],
                    "branch" => $data['branch'],
                ];
                $app->insert("warehouses_details", $details_insert);

                $app->update("products", ["amount[-]" => $data['amount']], ["id" => $data['products']]);
            } elseif ($post['process'] == 3) {
                $warehouses_insert = [
                    "type" => "import",
                    "data" => "products",
                    "content" => "Không duyệt xóa, trả về kho: #" . $data['product_code'],
                    "stores" => $data['stores'],
                    "branch" => $data['branch'],
                    "user" => $app->getSession("accounts")['id'],
                    "date" => date("Y-m-d H:i:s"),
                    "date_poster" => date("Y-m-d H:i:s"),
                    "active" => $jatbi->active(30),
                ];
                $app->insert("warehouses", $warehouses_insert);
                $orderId = $app->id();

                $details_insert = [
                    "warehouses" => $orderId,
                    "data" => "products",
                    "type" => "import",
                    "products" => $data['products'],
                    "amount" => $data['amount'],
                    "amount_total" => $data['amount'],
                    "price" => $data['price'],
                    "notes" => $post['notes'],
                    "date" => date("Y-m-d H:i:s"),
                    "user" => $app->getSession("accounts")['id'],
                    "stores" => $data['stores'],
                    "branch" => $data['branch'],
                ];
                $app->insert("warehouses_details", $details_insert);

                $app->update("products", ["amount[+]" => $data['amount']], ["id" => $data['products']]);
            }

            echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
        }
    })->setPermissions(['products']);

    $app->router('/ingredient-import-history', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {

        if ($app->method() === 'GET') {
            $jatbi->permission('ingredient');

            $vars['title'] = $jatbi->lang("Lịch sử nhập hàng");


            $vars['accounts'] = array_merge(
                [['value' => '', 'text' => $jatbi->lang('Tất cả tài khoản')]],
                $app->select('accounts', ['id(value)', 'name(text)'], ['deleted' => 0])
                // $app->select('accounts', ['id(value)', 'name(text)'], ['deleted' => 0, 'status' => 'A', 'ORDER' => ['name' => 'ASC']])
            );

            $vars['date_from'] = date('Y-m-d 00:00:00');
            $vars['date_to'] = date('Y-m-d 23:59:59');

            echo $app->render($template . '/warehouses/ingredient-import-history.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);

            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $orderName = isset($_POST['order'][0]['column']) ? $_POST['columns'][$_POST['order'][0]['column']]['name'] : 'warehouses.id';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            $searchValue = isset($_POST['search']['value']) ? $app->xss($_POST['search']['value']) : '';
            $userFilter = isset($_POST['user']) ? $app->xss($_POST['user']) : '';
            $date_string = isset($_POST['date']) ? $app->xss($_POST['date']) : '';


            if ($date_string) {
                $date = explode(' - ', $date_string);
                $date_from = date('Y-m-d 00:00:00', strtotime(str_replace('/', '-', trim($date[0]))));
                $date_to = date('Y-m-d 23:59:59', strtotime(str_replace('/', '-', trim($date[1]))));
            } else {
                $date_from = date('Y-m-d 00:00:00');
                $date_to = date('Y-m-d 23:59:59');
            }


            $joins = [
                "[>]accounts" => ["user" => "id"]
            ];

            $where = [
                "AND" => [
                    "warehouses.deleted" => 0,
                    "warehouses.data" => 'ingredient',
                    "warehouses.type" => 'import',
                    "warehouses.date[<>]" => [$date_from, $date_to],

                ]
            ];


            if (!empty($searchValue)) {
                $where['AND']['OR'] = [
                    'warehouses.id[~]' => $searchValue,
                    'warehouses.code[~]' => $searchValue,
                    'warehouses.content[~]' => $searchValue,
                ];
            }
            if (!empty($userFilter)) {
                $where['AND']['warehouses.user'] = $userFilter;
            }


            $count = $app->count("warehouses", $joins, "warehouses.id", $where);

            $where['LIMIT'] = [$start, $length];
            $where['ORDER'] = [$orderName => strtoupper($orderDir)];


            $datas = [];
            $columns = [
                "warehouses.id",
                "warehouses.code",
                "warehouses.content",
                "warehouses.date_poster",
                "warehouses.crafting",
                "accounts.name(user_name)"
            ];

            $app->select("warehouses", $joins, $columns, $where, function ($data) use (&$datas, $jatbi, $app) {
                $datas[] = [
                    "id" => '<a class="btn dropdown-item" data-action="modal" data-url="/warehouses/ingredient-history-views/' . $data['id'] . '">#' . $data['code'] . $data['id'] . '</a>',
                    "content" => $data['crafting']
                        ? '<a class="btn dropdown-item" data-action="modal" data-url="/crafting/crafting-history-views/' . $data['crafting'] . '/">' . htmlspecialchars($data['content']) . '</a>'
                        : htmlspecialchars($data['content']),
                    "date_poster" => $jatbi->datetime($data['date_poster']),
                    "user" => htmlspecialchars($data['user_name'] ?? ''),
                    "action" => (string) ($app->component("action", [
                        "button" => [
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Xem"),
                                'permission' => ['ingredient'],
                                'action' => ['data-url' => '/warehouses/ingredient-history-views/' . ($data['id'] ?? ''), 'data-action' => 'modal']
                            ],
                        ]
                    ]))
                ];
            });

            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => $count,
                "recordsFiltered" => $count,
                "data" => $datas,
            ]);
        }
    })->setPermissions(['ingredient']);

    $app->router('/ingredient-export-history', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {

        if ($app->method() === 'GET') {


            $vars['title'] = $jatbi->lang("Lịch sử xuất hàng");


            $vars['accounts'] = array_merge(
                [['value' => '', 'text' => $jatbi->lang('Tất cả tài khoản')]],
                $app->select('accounts', ['id(value)', 'name(text)'], ['deleted' => 0, 'status' => 'A', 'ORDER' => ['name' => 'ASC']])
            );


            $vars['date_from'] = date('Y-m-d 00:00:00');
            $vars['date_to'] = date('Y-m-d 23:59:59');

            echo $app->render($template . '/warehouses/ingredient-export-history.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);


            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $orderName = isset($_POST['order'][0]['column']) ? $_POST['columns'][$_POST['order'][0]['column']]['name'] : 'warehouses.id';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            $searchValue = isset($_POST['search']['value']) ? $app->xss($_POST['search']['value']) : '';
            $userFilter = isset($_POST['user']) ? $app->xss($_POST['user']) : '';
            $date_string = isset($_POST['date']) ? $app->xss($_POST['date']) : '';


            if ($date_string) {
                $date = explode(' - ', $date_string);
                $date_from = date('Y-m-d 00:00:00', strtotime(str_replace('/', '-', trim($date[0]))));
                $date_to = date('Y-m-d 23:59:59', strtotime(str_replace('/', '-', trim($date[1]))));
            } else {
                $date_from = date('Y-m-d 00:00:00');
                $date_to = date('Y-m-d 23:59:59');
            }

            $joins = [
                "[>]accounts" => ["user" => "id"]
            ];

            $where = [
                "AND" => [
                    "warehouses.deleted" => 0,
                    "warehouses.data" => 'ingredient',
                    "warehouses.type" => ['export', 'move_products'],
                    "warehouses.date[<>]" => [$date_from, $date_to]
                ]
            ];


            if (!empty($searchValue)) {
                $where['AND']['OR'] = [
                    'warehouses.id[~]' => $searchValue,
                    'warehouses.code[~]' => $searchValue,
                    'warehouses.content[~]' => $searchValue,
                ];
            }
            if (!empty($userFilter)) {
                $where['AND']['warehouses.user'] = $userFilter;
            }


            $count = $app->count("warehouses", $joins, "warehouses.id", $where);


            $where['LIMIT'] = [$start, $length];
            $where['ORDER'] = [$orderName => strtoupper($orderDir)];

            $datas = [];
            $columns = [
                "warehouses.id",
                "warehouses.code",
                "warehouses.content",
                "warehouses.date_poster",
                "warehouses.crafting",
                "accounts.name(user_name)"
            ];

            $app->select("warehouses", $joins, $columns, $where, function ($data) use (&$datas, $jatbi, $app) {
                $datas[] = [
                    "id" => '<a class="btn dropdown-item" data-action="modal" data-url="/warehouses/ingredient-history-views/' . $data['id'] . '">#' . $data['code'] . $data['id'] . '</a>',
                    "content" => $data['crafting']
                        ? '<a class="btn dropdown-item" data-action="modal" data-url="/crafting/crafting-history-views/' . $data['crafting'] . '/">' . htmlspecialchars($data['content']) . '</a>'
                        : htmlspecialchars($data['content']),
                    "date_poster" => $jatbi->datetime($data['date_poster']),
                    "user" => htmlspecialchars($data['user_name'] ?? ''),
                    "action" => (string) ($app->component("action", [
                        "button" => [
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Xem"),
                                'permission' => ['ingredient'],
                                'action' => ['data-url' => '/warehouses/ingredient-history-views/' . ($data['id'] ?? ''), 'data-action' => 'modal']
                            ],
                        ]
                    ]))
                ];
            });


            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => $count,
                "recordsFiltered" => $count,
                "data" => $datas,
            ]);
        }
    })->setPermissions(['ingredient']);

    $app->router('/ingredient-cancel-history', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {

        if ($app->method() === 'GET') {


            $vars['title'] = $jatbi->lang("Lịch sử hủy hàng");


            $vars['accounts'] = array_merge(
                [['value' => '', 'text' => $jatbi->lang('Tất cả tài khoản')]],
                $app->select('accounts', ['id(value)', 'name(text)'], ['deleted' => 0, 'status' => 'A', 'ORDER' => ['name' => 'ASC']])
            );


            $vars['date_from'] = date('Y-m-d 00:00:00');
            $vars['date_to'] = date('Y-m-d 23:59:59');

            echo $app->render($template . '/warehouses/ingredient-cancel-history.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);


            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $orderName = isset($_POST['order'][0]['column']) ? $_POST['columns'][$_POST['order'][0]['column']]['name'] : 'warehouses.id';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            $searchValue = isset($_POST['search']['value']) ? $app->xss($_POST['search']['value']) : '';
            $userFilter = isset($_POST['user']) ? $app->xss($_POST['user']) : '';
            $date_string = isset($_POST['date']) ? $app->xss($_POST['date']) : '';


            if ($date_string) {
                $date = explode(' - ', $date_string);
                $date_from = date('Y-m-d 00:00:00', strtotime(str_replace('/', '-', trim($date[0]))));
                $date_to = date('Y-m-d 23:59:59', strtotime(str_replace('/', '-', trim($date[1]))));
            } else {
                $date_from = date('Y-m-d 00:00:00');
                $date_to = date('Y-m-d 23:59:59');
            }

            $joins = [
                "[>]accounts" => ["user" => "id"]
            ];

            $where = [
                "AND" => [
                    "warehouses.deleted" => 0,
                    "warehouses.data" => 'ingredient',
                    "warehouses.type" => 'cancel',
                    "warehouses.date[<>]" => [$date_from, $date_to]
                ]
            ];


            if (!empty($searchValue)) {
                $where['AND']['OR'] = [
                    'warehouses.id[~]' => $searchValue,
                    'warehouses.code[~]' => $searchValue,
                    'warehouses.content[~]' => $searchValue,
                ];
            }
            if (!empty($userFilter)) {
                $where['AND']['warehouses.user'] = $userFilter;
            }


            $count = $app->count("warehouses", $joins, "warehouses.id", $where);


            $where['LIMIT'] = [$start, $length];
            $where['ORDER'] = [$orderName => strtoupper($orderDir)];


            $datas = [];
            $columns = [
                "warehouses.id",
                "warehouses.code",
                "warehouses.content",
                "warehouses.date_poster",
                "warehouses.crafting",
                "accounts.name(user_name)"
            ];


            $app->select("warehouses", $joins, $columns, $where, function ($data) use (&$datas, $jatbi, $app) {
                $datas[] = [
                    "id" => '<a class="btn dropdown-item" data-action="modal" data-url="/warehouses/ingredient-history-views/' . $data['id'] . '">#' . $data['code'] . $data['id'] . '</a>',
                    "content" => $data['crafting']
                        ? '<a class="btn dropdown-item" data-action="modal" data-url="/crafting/crafting-history-views/' . $data['crafting'] . '/">' . htmlspecialchars($data['content']) . '</a>'
                        : htmlspecialchars($data['content']),
                    "date_poster" => $jatbi->datetime($data['date_poster']),
                    "user" => htmlspecialchars($data['user_name'] ?? ''),
                    "action" => (string) ($app->component("action", [
                        "button" => [
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Xem"),
                                'permission' => ['ingredient-cancel'],
                                'action' => ['data-url' => '/warehouses/ingredient-history-views/' . ($data['id'] ?? ''), 'data-action' => 'modal']
                            ],
                        ]
                    ]))
                ];
            });


            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => $count,
                "recordsFiltered" => $count,
                "data" => $datas,
            ]);
        }
    })->setPermissions(['ingredient']);

    $app->router('/product-remove/{id}', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $id = $vars['id'] ?? 0;


        $product = $app->get("products", [
            "[<]units" => ["units" => "id"]
        ], [
            "products.id",
            "products.code",
            "products.name",
            "products.amount_error",
            "products.price",
            "products.stores",
            "products.branch",
            "products.vendor",
            "products.crafting",
            "products.notes",
            "products.categorys",
            "products.group",
            "products.units",
            "units.name(unit_name)"
        ], ["products.id" => $id]);

        if (!$product) {
            echo $app->render($template . '/error.html', ['content' => 'Sản phẩm không tồn tại'], $jatbi->ajax());
            return;
        }

        $vars['product'] = $product;
        $vars['title'] = $jatbi->lang("Chuyển về kho chế tác");

        if ($app->method() === 'GET') {
            echo $app->render($template . '/warehouses/products-remove.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);
            $post = array_map([$app, 'xss'], $_POST);
            $amount = str_replace(',', '', $post['amount'] ?? 0);


            if (empty($amount) || $amount <= 0) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Vui lòng nhập số lượng hợp lệ")]);
                return;
            }
            if ($amount > $product['amount_error']) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Số lượng chuyển không được lớn hơn số lượng lỗi")]);
                return;
            }

            $warehouse_id = $app->insert("warehouses", [
                "code" => "remove",
                "type" => "remove",
                "data" => "products",
                "stores" => $product['stores'],
                "branch" => $product['branch'],
                "content" => "chuyen-ve-kho-che-tac",
                "vendor" => $product['vendor'],
                "user" => $app->getSession("accounts")['id'],
                "date" => date("Y-m-d H:i:s"),
                "date_poster" => date("Y-m-d H:i:s"),
                "active" => $jatbi->active(30),
                "receive_status" => 1,
            ]);
            $warehouse_id = $app->id();


            $details_insert = [
                "warehouses" => $warehouse_id,
                "data" => "products",
                "type" => "remove",
                "products" => $product['id'],
                "amount" => $amount,
                "amount_total" => $amount,
                "price" => $product['price'],
                "notes" => $product['notes'],
                "date" => date("Y-m-d H:i:s"),
                "user" => $app->getSession("accounts")['id'],
                "stores" => $product['stores'],
                "branch" => $product['branch'],
                "crafting" => $product['crafting'],
            ];

            $app->insert("warehouses_details", $details_insert);

            $jatbi->logs('warehouses_details', 'add', $details_insert);



            $app->update("products", ["amount_error[-]" => $amount], ["id" => $product['id']]);

            $get_remove = $app->get("remove_products", ["id", "amount"], ["products" => $product["id"]]);
            if ($get_remove) {
                $app->update("remove_products", ["amount[+]" => $amount, "date" => date('Y-m-d H:i:s')], ["id" => $get_remove["id"]]);
            } else {
                $app->insert("remove_products", [
                    "products" => $product["id"],
                    "price" => $product["price"],
                    "amount" => $amount,
                    "unit" => $product["units"],
                    "crafting" => $product['crafting'],
                    "group_product" => $product['group'],
                    "category" => $product['categorys'],
                    "name_code" => $product['name'],
                    "user" => $app->getSession("accounts")['id'],
                    "date" => date('Y-m-d H:i:s'),
                ]);
            }

            $jatbi->logs('products', 'remove', ['product_id' => $product['id'], 'amount' => $amount]);
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Cập nhật thành công'), 'url' => 'auto']);
        }
    })->setPermissions(['list_products_errors']);


    $app->router('/product-cancel/{id}', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $id = $vars['id'] ?? 0;

        $product = $app->get("products", [
            "[<]units" => ["units" => "id"]
        ], [
            "products.id",
            "products.code",
            "products.name",
            "products.images",
            "products.amount_error",
            "products.price",
            "products.stores",
            "products.branch",
            "products.vendor",
            "products.crafting",
            "products.notes",
            "products.categorys",
            "products.group",
            "units.name(unit_name)"
        ], ["products.id" => $id]);

        if (!$product) {
            echo $app->render($template . '/error.html', ['content' => 'Sản phẩm không tồn tại'], $jatbi->ajax());
            return;
        }

        $vars['product'] = $product;
        $vars['title'] = $jatbi->lang("Hủy sản phẩm");

        if ($app->method() === 'GET') {
            echo $app->render($template . '/warehouses/products-remove.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);
            $post = array_map([$app, 'xss'], $_POST);
            $amount = str_replace(',', '', $post['amount'] ?? 0);


            if (empty($amount) || $amount <= 0) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Vui lòng nhập số lượng hợp lệ")]);
                return;
            }
            if ($amount > $product['amount_error']) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Số lượng hủy không được lớn hơn số lượng lỗi")]);
                return;
            }


            $warehouse_id = $app->insert("warehouses", [
                "code" => "delete",
                "type" => "delete",
                "data" => "products",
                "stores" => $product['stores'],
                "branch" => $product['branch'],
                "content" => "huy-san-pham",
                "vendor" => $product['vendor'],
                "user" => $app->getSession("accounts")['id'],
                "date" => date("Y-m-d H:i:s"),
                "date_poster" => date("Y-m-d H:i:s"),
                "active" => $jatbi->active(30),
                "receive_status" => 1,
            ]);

            $app->insert("process_deleted", [
                "warehouses" => $warehouse_id,
                "data" => "products",
                "type" => "delete",
                "products" => $product['id'],
                "amount" => $amount,
                "price" => $product['price'],
                "notes" => $product['notes'],
                "date" => date("Y-m-d H:i:s"),
                "user" => $app->getSession("accounts")['id'],
                "stores" => $product['stores'],
                "branch" => $product['branch'],
                "crafting" => $product['crafting'],
                "process" => 1,
            ]);


            $app->update("products", ["amount_error[-]" => $amount], ["id" => $product['id']]);


            $jatbi->logs('products', 'cancel_request', ['product_id' => $product['id'], 'amount' => $amount]);
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Tạo yêu cầu hủy thành công'), 'url' => 'auto']);
        }
    })->setPermissions(['products']);





    $app->router('/ingredient-cancel-history', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        // --- XỬ LÝ YÊU CẦU GET: HIỂN THỊ TRANG VÀ BỘ LỌC ---
        if ($app->method() === 'GET') {


            $vars['title'] = $jatbi->lang("Lịch sử hủy hàng");

            // Lấy danh sách tài khoản để hiển thị trong bộ lọc
            $vars['accounts'] = array_merge(
                [['value' => '', 'text' => $jatbi->lang('Tất cả tài khoản')]],
                $app->select('accounts', ['id(value)', 'name(text)'], ['deleted' => 0, 'status' => 'A', 'ORDER' => ['name' => 'ASC']])
            );

            // Thiết lập ngày mặc định cho bộ lọc (giống revenue và ingredient-import-history)
            $vars['date_from'] = date('Y-m-01 00:00:00'); // First day of the current month
            $vars['date_to'] = date('Y-m-d 23:59:59'); // Last day of the current month

            echo $app->render($template . '/warehouses/ingredient-cancel-history.html', $vars);
        }
        // --- XỬ LÝ YÊU CẦU POST: CUNG CẤP DỮ LIỆU JSON CHO DATATABLES ---
        elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);

            // --- 1. Đọc tham số từ DataTables và bộ lọc ---
            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $orderName = isset($_POST['order'][0]['column']) ? $_POST['columns'][$_POST['order'][0]['column']]['name'] : 'warehouses.id';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            $searchValue = isset($_POST['search']['value']) ? $app->xss($_POST['search']['value']) : '';
            $userFilter = isset($_POST['user']) ? $app->xss($_POST['user']) : '';
            $date_string = isset($_POST['date']) ? $app->xss($_POST['date']) : '';

            // --- 2. Xử lý bộ lọc ngày (giống revenue và ingredient-import-history) ---
            if ($date_string) {
                $date = explode(' - ', $date_string);
                $date_from = date('Y-m-d 00:00:00', strtotime(str_replace('/', '-', trim($date[0]))));
                $date_to = date('Y-m-d 23:59:59', strtotime(str_replace('/', '-', trim($date[1]))));
            } else {
                $date_from = date('Y-m-01 00:00:00'); // First day of the current month
                $date_to = date('Y-m-d 23:59:59'); // Last day of the current month
            }

            // --- 3. Xây dựng truy vấn với JOIN và WHERE ---
            $joins = [
                "[>]accounts" => ["user" => "id"]
            ];

            $where = [
                "AND" => [
                    "warehouses.deleted" => 0,
                    "warehouses.data" => 'ingredient',
                    "warehouses.type" => 'cancel',
                    "warehouses.date[<>]" => [$date_from, $date_to]
                ]
            ];

            // Áp dụng bộ lọc tùy chỉnh
            if (!empty($searchValue)) {
                $where['AND']['OR'] = [
                    'warehouses.id[~]' => $searchValue,
                    'warehouses.code[~]' => $searchValue,
                    'warehouses.content[~]' => $searchValue,
                ];
            }
            if (!empty($userFilter)) {
                $where['AND']['warehouses.user'] = $userFilter;
            }

            // --- 4. Đếm tổng số bản ghi ---
            $count = $app->count("warehouses", $joins, "warehouses.id", $where);

            // Thêm sắp xếp và phân trang vào điều kiện WHERE
            $where['LIMIT'] = [$start, $length];
            $where['ORDER'] = [$orderName => strtoupper($orderDir)];

            // --- 5. Lấy dữ liệu và định dạng bằng callback function ---
            $datas = [];
            $columns = [
                "warehouses.id",
                "warehouses.code",
                "warehouses.content",
                "warehouses.date_poster",
                "warehouses.crafting",
                "accounts.name(user_name)"
            ];

            // Sử dụng callback để xử lý từng dòng dữ liệu
            $app->select("warehouses", $joins, $columns, $where, function ($data) use (&$datas, $jatbi, $app) {
                $datas[] = [
                    "id" => '<a class="btn dropdown-item" data-action="modal" data-url="/warehouses/ingredient-history-views/' . $data['id'] . '">#' . $data['code'] . $data['id'] . '</a>',
                    "content" => $data['crafting']
                        ? '<a class="btn dropdown-item" data-action="modal" data-url="/crafting/crafting-history-views/' . $data['crafting'] . '/">' . htmlspecialchars($data['content']) . '</a>'
                        : htmlspecialchars($data['content']),
                    "date_poster" => $jatbi->datetime($data['date_poster']),
                    "user" => htmlspecialchars($data['user_name'] ?? ''),
                    "action" => (string) ($app->component("action", [
                        "button" => [
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Xem"),
                                'permission' => ['ingredient-cancel'],
                                'action' => ['data-url' => '/warehouses/ingredient-history-views/' . ($data['id'] ?? ''), 'data-action' => 'modal']
                            ],
                        ]
                    ]))
                ];
            });

            // --- 6. Trả về JSON cho DataTables ---
            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => $count,
                "recordsFiltered" => $count,
                "data" => $datas,
            ]);
        }
    })->setPermissions(['ingredient']);






    $app->router('/products-error-history/error', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template, $accStore, $stores) {

        if ($app->method() === 'GET') {


            $vars['title'] = $jatbi->lang("Lịch sử hàng lỗi");


            $vars['accounts'] = array_merge(
                [['value' => '', 'text' => $jatbi->lang('Tất cả tài khoản')]],
                $app->select('accounts', ['id(value)', 'name(text)'], ['deleted' => 0, 'status' => 'A', 'ORDER' => ['name' => 'ASC']])
            );


            array_unshift($stores, [
                'value' => '',
                'text' => $jatbi->lang('Tất cả')
            ]);
            $vars['stores'] = $stores;

            $vars['date_from'] = date('Y-m-01 00:00:00');
            $vars['date_to'] = date('Y-m-d 23:59:59');

            echo $app->render($template . '/warehouses/products-error-history.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);


            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $orderName = isset($_POST['order'][0]['column']) ? $_POST['columns'][$_POST['order'][0]['column']]['name'] : 'warehouses.id';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            $searchValue = isset($_POST['search']['value']) ? $app->xss($_POST['search']['value']) : '';
            $userFilter = isset($_POST['user']) ? $app->xss($_POST['user']) : '';
            $storeFilter = isset($_POST['stores']) ? $app->xss($_POST['stores']) : $accStore;
            $date_string = isset($_POST['date']) ? $app->xss($_POST['date']) : '';


            if ($date_string) {
                $date = explode(' - ', $date_string);
                $date_from = date('Y-m-d 00:00:00', strtotime(str_replace('/', '-', trim($date[0]))));
                $date_to = date('Y-m-d 23:59:59', strtotime(str_replace('/', '-', trim($date[1]))));
            } else {
                $date_from = date('Y-m-01 00:00:00');
                $date_to = date('Y-m-d 23:59:59');
            }


            $joins = [
                "[>]accounts" => ["user" => "id"],
                "[>]stores" => ["stores" => "id"]
            ];

            $where = [
                "AND" => [
                    "warehouses.deleted" => 0,
                    "warehouses.data" => 'products',
                    "warehouses.type" => 'error',
                    "warehouses.date[<>]" => [$date_from, $date_to]
                ]
            ];

            // Áp dụng bộ lọc tùy chỉnh
            if (!empty($searchValue)) {
                $where['AND']['OR'] = [
                    'warehouses.id[~]' => $searchValue,
                    'warehouses.code[~]' => $searchValue,
                    'warehouses.content[~]' => $searchValue,
                ];
            }
            if (!empty($userFilter)) {
                $where['AND']['warehouses.user'] = $userFilter;
            }
            if ($storeFilter !== '') {
                $where['AND']['warehouses.stores'] = $storeFilter;
            }


            $count = $app->count("warehouses", $joins, "warehouses.id", $where);


            $where['LIMIT'] = [$start, $length];
            $where['ORDER'] = [$orderName => strtoupper($orderDir)];


            $datas = [];
            $columns = [
                "warehouses.id",
                "warehouses.code",
                "warehouses.content",
                "warehouses.date_poster",
                "warehouses.stores",
                "warehouses.stores_receive",
                "warehouses.user",
                "accounts.name(user_name)",
                "stores.name(store_name)"
            ];


            $app->select("warehouses", $joins, $columns, $where, function ($data) use (&$datas, $jatbi, $app) {
                // Lấy thông tin sản phẩm từ warehouses_logs
                $product = $app->get("warehouses_logs", ["products"], ["warehouses" => $data['id']]);
                $product_info = $product ? $app->get("products", ["code", "name"], ["id" => $product['products']]) : ['code' => '', 'name' => ''];

                $store_display = $data['store_name'];
                if ($data['stores_receive']) {
                    $store_receive = $app->get("stores", ["name"], ["id" => $data['stores_receive']]);
                    $store_display .= ' -> ' . ($store_receive['name'] ?? '');
                }

                $datas[] = [
                    "id" => '<a class="btn dropdown-item" data-action="modal" data-url="/warehouses/products-error-history-views/' . $data['id'] . '">#' . $data['code'] . $data['id'] . '</a>',
                    "product" => $product_info['code'] . '-' . htmlspecialchars($product_info['name']),
                    "content" => htmlspecialchars($data['content']),
                    "date_poster" => $jatbi->datetime($data['date_poster']),
                    "store" => $store_display,
                    "user" => htmlspecialchars($data['user_name'] ?? ''),
                    "action" => (string) ($app->component("action", [
                        "button" => [
                            [
                                'type' => 'button',
                                'name' => $jatbi->lang("Xem"),
                                'permission' => ['products'],
                                'action' => ['data-url' => '/warehouses/products-error-history-views/' . ($data['id'] ?? ''), 'data-action' => 'modal']
                            ],
                        ]
                    ]))
                ];
            });


            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => $count,
                "recordsFiltered" => $count,
                "data" => $datas,
            ]);
        }
    })->setPermissions(['products']);



    $app->router('/products-error-history/remove', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template, $accStore, $stores) {
        // --- XỬ LÝ YÊU CẦU GET: HIỂN THỊ TRANG VÀ BỘ LỌC ---
        if ($app->method() === 'GET') {


            $vars['title'] = $jatbi->lang("Lịch sử chuyển kho chế tác");

            // Lấy danh sách tài khoản để hiển thị trong bộ lọc
            $vars['accounts'] = array_merge(
                [['value' => '', 'text' => $jatbi->lang('Tất cả tài khoản')]],
                $app->select('accounts', ['id(value)', 'name(text)'], ['deleted' => 0, 'status' => 'A', 'ORDER' => ['name' => 'ASC']])
            );

            // Lấy danh sách cửa hàng để hiển thị trong bộ lọc
            $vars['stores'] = array_merge(
                [['value' => '', 'text' => $jatbi->lang('Tất cả')]],
                $stores
            );

            // Lấy danh sách quầy hàng để hiển thị trong bộ lọc
            // $store_id = $accStore ?: ($app->xss($_GET['stores']) ?? '');
            // $vars['branches'] = array_merge(
            //     [['value' => '', 'text' => $jatbi->lang('Tất cả')]],
            //     $app->select('branch', ['id(value)', 'name(text)'], [
            //         'stores' => $store_id ?: null,
            //         'status' => 'A',
            //         'deleted' => 0,
            //         'ORDER' => ['name' => 'ASC']
            //     ])
            // );


            $vars['date_from'] = date('Y-m-01 00:00:00');
            $vars['date_to'] = date('Y-m-d 23:59:59');

            echo $app->render($template . '/warehouses/products-remove-history.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);


            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $orderName = isset($_POST['order'][0]['column']) ? $_POST['columns'][$_POST['order'][0]['column']]['name'] : 'warehouses.id';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            $searchValue = isset($_POST['search']['value']) ? $app->xss($_POST['search']['value']) : '';
            $userFilter = isset($_POST['user']) ? $app->xss($_POST['user']) : '';
            $storeFilter = isset($_POST['stores']) ? $app->xss($_POST['stores']) : $accStore;
            $branchFilter = isset($_POST['branch']) ? $app->xss($_POST['branch']) : '';
            $date_string = isset($_POST['date']) ? $app->xss($_POST['date']) : '';


            if ($date_string) {
                $date = explode(' - ', $date_string);
                $date_from = date('Y-m-d 00:00:00', strtotime(str_replace('/', '-', trim($date[0]))));
                $date_to = date('Y-m-d 23:59:59', strtotime(str_replace('/', '-', trim($date[1]))));
            } else {
                $date_from = date('Y-m-01 00:00:00');
                $date_to = date('Y-m-d 23:59:59');
            }


            $joins = [
                "[>]accounts" => ["user" => "id"],
                "[>]stores" => ["stores" => "id"]
                // "[>]branch" => ["branch" => "id"]
            ];

            $where = [
                "AND" => [
                    "warehouses.deleted" => 0,
                    "warehouses.data" => 'products',
                    "warehouses.type" => 'remove',
                    "warehouses.date[<>]" => [$date_from, $date_to]
                ]
            ];


            if (!empty($searchValue)) {
                $where['AND']['OR'] = [
                    'warehouses.id[~]' => $searchValue,
                    'warehouses.code[~]' => $searchValue,
                    'warehouses.content[~]' => $searchValue,
                ];
            }
            if (!empty($userFilter)) {
                $where['AND']['warehouses.user'] = $userFilter;
            }
            if ($storeFilter !== '') {
                $where['AND']['warehouses.stores'] = $storeFilter;
            }
            // if (!empty($branchFilter)) {
            //     $where['AND']['warehouses.branch'] = $branchFilter;
            // }

            // --- 4. Đếm tổng số bản ghi ---
            $count = $app->count("warehouses", $joins, "warehouses.id", $where);

            // Thêm sắp xếp và phân trang vào điều kiện WHERE
            $where['LIMIT'] = [$start, $length];
            $where['ORDER'] = [$orderName => strtoupper($orderDir)];

            // --- 5. Lấy dữ liệu và định dạng bằng callback function ---
            $datas = [];
            $columns = [
                "warehouses.id",
                "warehouses.code",
                "warehouses.content",
                "warehouses.date_poster",
                "warehouses.stores",
                "warehouses.stores_receive",
                "warehouses.user",
                // "warehouses.branch",
                "accounts.name(user_name)",
                "stores.name(store_name)",
                // "branch.name(branch_name)"
            ];

            // Sử dụng callback để xử lý từng dòng dữ liệu
            $app->select("warehouses", $joins, $columns, $where, function ($data) use (&$datas, $jatbi, $app) {
                // Lấy thông tin sản phẩm từ warehouses_logs
                $product = $app->get("warehouses_logs", ["products"], ["warehouses" => $data['id']]);
                $product_info = $product ? $app->get("products", ["code", "name"], ["id" => $product['products']]) : ['code' => '', 'name' => ''];

                $store_display = $data['store_name'];
                if ($data['stores_receive']) {
                    $store_receive = $app->get("stores", ["name"], ["id" => $data['stores_receive']]);
                    $store_display .= ' -> ' . ($store_receive['name'] ?? '');
                }

                $datas[] = [
                    "id" => '<a class="btn dropdown-item" data-action="modal" data-url="/warehouses/products-error-history-views/' . $data['id'] . '">#' . $data['code'] . $data['id'] . '</a>',
                    "product" => $product_info['code'] . '-' . htmlspecialchars($product_info['name']),
                    "content" => htmlspecialchars($data['content']),
                    "date_poster" => $jatbi->datetime($data['date_poster']),
                    "store" => $store_display,
                    "branch" => htmlspecialchars($data['branch_name'] ?? ''),
                    "user" => htmlspecialchars($data['user_name'] ?? ''),
                    "action" => '<button data-action="modal" data-url="/warehouses/products-error-history-views/' . $data['id'] . '" class="btn btn-eclo-light btn-sm border-0 py-1 px-2 rounded-3" aria-label="' . $jatbi->lang('Xem') . '"><i class="ti ti-eye"></i></button>',
                ];
            });

            // --- 6. Trả về JSON cho DataTables ---
            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => $count,
                "recordsFiltered" => $count,
                "data" => $datas,
            ]);
        }
    })->setPermissions(['products']);



    $app->router('/products-error-history/delete', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template, $accStore, $stores) {

        if ($app->method() === 'GET') {


            $vars['title'] = $jatbi->lang("Lịch sử hủy hàng");


            $vars['accounts'] = array_merge(
                [['value' => '', 'text' => $jatbi->lang('Tất cả tài khoản')]],
                $app->select('accounts', ['id(value)', 'name(text)'], ['deleted' => 0, 'status' => 'A', 'ORDER' => ['name' => 'ASC']])
            );


            $vars['stores'] = array_merge(
                [['value' => '', 'text' => $jatbi->lang('Tất cả')]],
                $stores
            );

            // Lấy danh sách quầy hàng để hiển thị trong bộ lọc
            // $store_id = $accStore ?: ($app->xss($_GET['stores']) ?? '');
            // $vars['branches'] = array_merge(
            //     [['value' => '', 'text' => $jatbi->lang('Tất cả')]],
            //     $app->select('branch', ['id(value)', 'name(text)'], [
            //         'stores' => $store_id ?: null,
            //         'status' => 'A',
            //         'deleted' => 0,
            //         'ORDER' => ['name' => 'ASC']
            //     ])
            // );

            echo $app->render($template . '/warehouses/products-delete-history.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);


            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $orderName = isset($_POST['order'][0]['column']) ? $_POST['columns'][$_POST['order'][0]['column']]['name'] : 'warehouses.id';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            $searchValue = isset($_POST['search']['value']) ? $app->xss($_POST['search']['value']) : '';
            $userFilter = isset($_POST['user']) ? $app->xss($_POST['user']) : '';
            $storeFilter = isset($_POST['stores']) ? $app->xss($_POST['stores']) : $accStore;
            // $branchFilter = isset($_POST['branch']) ? $app->xss($_POST['branch']) : '';
            $date_string = isset($_POST['date']) ? $app->xss($_POST['date']) : '';


            if ($date_string) {
                $date = explode(' - ', $date_string);
                $date_from = date('Y-m-d 00:00:00', strtotime(str_replace('/', '-', trim($date[0]))));
                $date_to = date('Y-m-d 23:59:59', strtotime(str_replace('/', '-', trim($date[1]))));
            } else {
                $date_from = date('Y-m-01 00:00:00');
                $date_to = date('Y-m-d 23:59:59');
            }

            // --- 3. Xây dựng truy vấn với JOIN và WHERE ---
            $joins = [
                "[>]accounts" => ["user" => "id"],
                "[>]stores" => ["stores" => "id"]
                // "[>]branch" => ["branch" => "id"]
            ];

            $where = [
                "AND" => [
                    "warehouses.deleted" => 0,
                    "warehouses.data" => 'products',
                    "warehouses.type" => 'delete',
                    "warehouses.date[<>]" => [$date_from, $date_to]
                ]
            ];

            // Áp dụng bộ lọc tùy chỉnh
            if (!empty($searchValue)) {
                $where['AND']['OR'] = [
                    'warehouses.id[~]' => $searchValue,
                    'warehouses.code[~]' => $searchValue,
                    'warehouses.content[~]' => $searchValue,
                ];
            }
            if (!empty($userFilter)) {
                $where['AND']['warehouses.user'] = $userFilter;
            }
            if ($storeFilter !== '') {
                $where['AND']['warehouses.stores'] = $storeFilter;
            }
            // if (!empty($branchFilter)) {
            //     $where['AND']['warehouses.branch'] = $branchFilter;
            // }

            // --- 4. Đếm tổng số bản ghi ---
            $count = $app->count("warehouses", $joins, "warehouses.id", $where);

            // Thêm sắp xếp và phân trang vào điều kiện WHERE
            $where['LIMIT'] = [$start, $length];
            $where['ORDER'] = [$orderName => strtoupper($orderDir)];

            // --- 5. Lấy dữ liệu và định dạng bằng callback function ---
            $datas = [];
            $columns = [
                "warehouses.id",
                "warehouses.code",
                "warehouses.content",
                "warehouses.date_poster",
                "warehouses.stores",
                "warehouses.stores_receive",
                "warehouses.user",
                // "warehouses.branch",
                "accounts.name(user_name)",
                "stores.name(store_name)",
                // "branch.name(branch_name)"
            ];

            // Sử dụng callback để xử lý từng dòng dữ liệu
            $app->select("warehouses", $joins, $columns, $where, function ($data) use (&$datas, $jatbi, $app) {
                // Lấy thông tin sản phẩm từ warehouses_logs
                $product = $app->get("warehouses_logs", ["products"], ["warehouses" => $data['id']]);
                $product_info = $product ? $app->get("products", ["code", "name"], ["id" => $product['products']]) : ['code' => '', 'name' => ''];

                $store_display = $data['store_name'];
                if ($data['stores_receive']) {
                    $store_receive = $app->get("stores", ["name"], ["id" => $data['stores_receive']]);
                    $store_display .= ' -> ' . ($store_receive['name'] ?? '');
                }

                $datas[] = [
                    "id" => '<a class="btn dropdown-item" data-action="modal" data-url="/warehouses/products-error-history-views/' . $data['id'] . '">#' . $data['code'] . $data['id'] . '</a>',
                    "product" => $product_info['code'] . '-' . htmlspecialchars($product_info['name']),
                    "content" => htmlspecialchars($data['content']),
                    "date_poster" => $jatbi->datetime($data['date_poster']),
                    "store" => $store_display,
                    // "branch" => htmlspecialchars($data['branch_name'] ?? ''),
                    "user" => htmlspecialchars($data['user_name'] ?? ''),
                    "action" => '<button data-action="modal" data-url="/warehouses/products-error-history-views/' . $data['id'] . '" class="btn btn-eclo-light btn-sm border-0 py-1 px-2 rounded-3" aria-label="' . $jatbi->lang('Xem') . '"><i class="ti ti-eye"></i></button>',
                ];
            });

            // --- 6. Trả về JSON cho DataTables ---
            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => $count,
                "recordsFiltered" => $count,
                "data" => $datas,
            ]);
        }
    })->setPermissions(['products']);



    $app->router('/products-error-history-views/{id}', ['GET'], function ($vars) use ($app, $jatbi, $template) {

        $warehouse_id = $app->xss($vars['id']);
        $vars['data'] = $app->get("warehouses", [
            "id",
            "type",
            "date",
            "code",
            "user",
            "content",
            "purchase"
        ], ["id" => $warehouse_id]);

        if (!$vars['data']) {
            header("HTTP/1.0 404 Not Found");
            die();
        }

        $vars['details'] = $app->select("warehouses_details", [
            "id",
            "products",
            "amount",
            "notes"
        ], ["warehouses" => $warehouse_id]);

        $vars['total_amount'] = 0;
        $vars['total_price'] = 0;
        $vars['total_purchase_price'] = 0;
        $vars['total_purchase_amount'] = 0;
        $vars['total'] = 0;

        foreach ($vars['details'] as &$detail) {
            $product = $app->get("products", [
                "id",
                "code",
                "name",
                "units",
                "price"
            ], ["id" => $detail['products']]);
            $detail['product'] = $product ?: ['code' => '', 'name' => '', 'units' => '', 'price' => 0];

            $detail['unit_name'] = $product['units'] ? $app->get("units", "name", ["id" => $product['units']]) : '';

            if ($vars['data']['purchase']) {
                $purchase_id = $vars['data']['purchase'];
                $purchase_price = $app->get("purchase_products", "price", ["purchase" => $purchase_id, "products" => $detail['products']]);
                $detail['price'] = $purchase_price ?: 0;
                $detail['total_price'] = $detail['amount'] * $detail['price'];
                $vars['total_purchase_price'] += $detail['price'];
                $vars['total_purchase_amount'] += $detail['total_price'];
                $vars['total'] += $detail['total_price'];
            } else {
                $detail['price'] = $product['price'] ?: 0;
                $detail['total_price'] = $detail['amount'] * $detail['price'];
                $vars['total_price'] += $detail['price'];
                $vars['total'] += $detail['total_price'];
            }

            $vars['total_amount'] += $detail['amount'];
        }

        $vars['user_name'] = $vars['data']['user'] ? $app->get("accounts", "name", ["id" => $vars['data']['user']]) : '';

        echo $app->render($template . '/warehouses/products-error-history-views.html', $vars, $jatbi->ajax());
    })->setPermissions(['products']);

    $app->router('/ingredient-import', ['GET'], function ($vars) use ($app, $jatbi, $template) {


        $jatbi->permission('ingredient');

        $vars['title'] = $jatbi->lang("Nhập kho nguyên liệu");
        $vars['is_crafting_import'] = false;

        // Lấy danh sách nguyên liệu đã thêm vào phiếu từ session
        $vars['SelectIngredients'] = $_SESSION['ingredient_import']['ingredients'] ?? [];

        echo $app->render($template . '/warehouses/ingredient-import.html', $vars);
    })->setPermissions(['ingredient']);

    $app->router('/products-error/{id}', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $id = $vars['id'] ?? 0;

        // TỐI ƯU: Lấy dữ liệu sản phẩm và tên đơn vị bằng JOIN
        $product = $app->get("products", ["[<]units" => ["units" => "id"]], [
            "products.id",
            "products.code",
            "products.name",
            "products.images",
            "products.amount",
            "products.price",
            "products.stores",
            "products.branch",
            "products.vendor",
            "products.crafting",
            "units.name(unit_name)"
        ], ["products.id" => $id]);

        if (!$product) {
            echo $app->render($template . '/error.html', ['content' => 'Sản phẩm không tồn tại']);
            return;
        }

        $vars['product'] = $product;
        $vars['title'] = $jatbi->lang("Báo lỗi sản phẩm");

        if ($app->method() === 'GET') {
            echo $app->render($template . '/warehouses/products-error.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);
            $post = array_map([$app, 'xss'], $_POST);
            $amount = str_replace(',', '', $post['amount'] ?? 0);

            // --- VALIDATION ---
            if (empty($amount) || $amount <= 0) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Vui lòng nhập số lượng hợp lệ")]);
                return;
            }
            if ($amount > $product['amount']) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Số lượng lỗi không được lớn hơn số lượng tồn kho")]);
                return;
            }

            // --- BẮT ĐẦU LOGIC XỬ LÝ KHO ---
            $app->insert("warehouses", [
                "code" => "error",
                "type" => "error",
                "data" => "products",
                "stores" => $product['stores'],
                "branch" => $product['branch'],
                "content" => $post['content'],
                "vendor" => $product['vendor'],
                "user" => $app->getSession("accounts")['id'] ?? 0,
                "date" => date("Y-m-d H:i:s"),
                "date_poster" => date("Y-m-d H:i:s"),
                "active" => $jatbi->active(30),
                "receive_status" => 1
            ]);
            $warehouse_id = $app->id();

            $app->insert("warehouses_details", [
                "warehouses" => $warehouse_id,
                "data" => "products",
                "type" => "error",
                "products" => $id,
                "amount" => $amount,
                "amount_total" => $amount,
                "price" => $product['price'],
                "notes" => $post['content'],
                "date" => date("Y-m-d H:i:s"),
                "user" => $app->getSession("accounts")['id'] ?? 0,
                "stores" => $product['stores'],
                "branch" => $product['branch'],
                "crafting" => $product['crafting']
            ]);
            $details_id = $app->id();

            $app->insert("warehouses_logs", [
                "warehouses" => $warehouse_id,
                "details" => $details_id,
                "data" => "products",
                "type" => "error",
                "products" => $id,
                "amount" => $amount,
                "price" => $product['price'],
                "total" => $amount * ($product['price'] ?? 0),
                "notes" => $post['content'],
                "date" => date("Y-m-d H:i:s"),
                "user" => $app->getSession("accounts")['id'] ?? 0,
                "stores" => $product['stores'],
                "branch" => $product['branch']
            ]);

            // Cập nhật lại tồn kho sản phẩm
            $app->update("products", [
                "amount[-]" => $amount,
                "amount_error[+]" => $amount
            ], ["id" => $id]);

            $jatbi->logs('products', 'error_report', ['product_id' => $id, 'amount' => $amount]);
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Báo lỗi thành công'), 'url' => 'auto']);
        }
    })->setPermissions(['products']);

    $app->router('/products-import-crafting', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Nhập hàng từ chế tác");

        if ($app->method() === 'GET') {
            $vars['accounts'] = $app->select("accounts", ["id(value)", "name(text)"], ["deleted" => 0]);
            echo $app->render($template . '/warehouses/products-import-crafting.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);

            $draw = intval($_POST['draw'] ?? 0);
            $start = intval($_POST['start'] ?? 0);
            $length = intval($_POST['length'] ?? 10);
            $searchValue = $_POST['search']['value'] ?? '';

            $where = [
                "AND" => [
                    "w.deleted" => 0,
                    "w.data" => 'pairing',
                    "w.type" => 'export',
                    "w.export_status" => 1,
                ]
            ];
            if (!empty($searchValue))
                $where['AND']['w.id[~]'] = $searchValue;

            $joins = ["[<]stores(s)" => ["stores" => "id"], "[<]branch(b)" => ["branch" => "id"], "[<]accounts(a)" => ["user" => "id"]];

            $count = $app->count("warehouses(w)", $joins, "w.id", $where);

            $where["ORDER"] = ['w.id' => 'DESC'];
            $where["LIMIT"] = [$start, $length];

            $columns = ["w.id", "w.code", "w.content", "w.date_poster", "s.name(store_name)", "b.name(branch_name)", "a.name(user_name)"];
            $datas = $app->select("warehouses(w)", $joins, $columns, $where);

            $resultData = [];
            foreach ($datas as $data) {
                $resultData[] = [
                    "code" => '<a data-action="modal" data-url="/crafting/pairing-export-views/' . $data['id'] . '/">#' . $data['code'] . $data['id'] . '</a>',
                    "store" => $data['store_name'],
                    "branch" => $data['branch_name'],
                    "content" => $data['content'],
                    "date" => $jatbi->datetime($data['date_poster']),
                    "user" => $data['user_name'],
                    "action" => '<a class="btn btn-primary btn-sm pjax-load" href="/warehouses/products-import/crafting/' . $data['id'] . '">' . $jatbi->lang('Nhập hàng') . '</a>',
                    "views" => '<button data-action="modal" data-url="/crafting/pairing-export-views/' . $data['id'] . '" class="btn btn-eclo-light btn-sm border-0 py-1 px-2 rounded-3" aria-label="' . $jatbi->lang('Xem') . '"><i class="ti ti-eye"></i></button>',
                ];
            }
            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => $count,
                "recordsFiltered" => $count,
                "data" => $resultData
            ]);
        }
    })->setPermissions(['products.import']);

    // $app->router('/products-import/crafting/{id}', 'GET', function ($vars) use ($app, $jatbi, $setting) {
    //     $action = 'import';
    //     $id = $vars['id'] ?? 0;

    //     $warehouse_data = $app->get("warehouses", ["id", "code", "stores", "branch"], ["id" => $id, "data" => 'pairing', "type" => 'export']);
    //     if (!$warehouse_data) {
    //         echo $app->render($template . '/error.html', ['content' => 'Phiếu xuất kho không hợp lệ']);
    //         return;
    //     }

    //     $sessionData = &$_SESSION['products'][$action];

    //     echo "<h3>BƯỚC 1: TRƯỚC KHI KIỂM TRA</h3>";
    //     echo "<p>ID trên URL (\$id): <strong>" . $id . "</strong></p>";
    //     echo "<p>crafting ID trong Session (\$sessionData['crafting']): <strong>" . ($sessionData['crafting'] ?? 'Chưa có') . "</strong></p>";
    //     echo "<pre>Toàn bộ Session ban đầu:";
    //     var_dump($sessionData);
    //     echo "</pre>";


    //     if (($sessionData['crafting'] ?? null) != $id) {
    //         unset($sessionData);
    //     }


    //     echo "<hr>";
    //     echo "<h3>BƯỚC 2: SAU KHI KIỂM TRA</h3>";
    //     echo "<pre>Toàn bộ Session cuối cùng:";
    //     var_dump($sessionData);
    //     echo "</pre>";
    //     exit; 

    //     if (empty($sessionData['crafting'])) {
    //         $sessionData['crafting'] = $id;
    //         $sessionData['date'] = date("Y-m-d H:i:s");
    //         $sessionData['content'] = "Nhập hàng từ phiếu xuất kho chế tác #" . $warehouse_data['code'];
    //         $sessionData['stores'] = $warehouse_data['stores'];
    //         $sessionData['branch'] = $warehouse_data['branch'];

    //         $details = $app->select(
    //             "warehouses_details",
    //             ["[<]crafting" => ["crafting" => "id"]],
    //             [
    //                 "warehouses_details.crafting",
    //                 "warehouses_details.amount",
    //                 "warehouses_details.price",
    //                 "warehouses_details.cost",
    //                 "crafting.name",
    //                 "crafting.code",
    //                 "crafting.categorys",
    //                 "crafting.group",
    //                 "crafting.default_code",
    //                 "crafting.units",
    //                 "crafting.notes(crafting_notes)",
    //                 "crafting.content(crafting_content)"
    //             ],
    //             ["warehouses_details.warehouses" => $id]
    //         );

    //         $sessionData['products'] = $details;
    //     }

    //     $vars['data'] = $sessionData;
    //     $vars['title'] = $jatbi->lang("Nhập kho thành phẩm");

    //     $unit_ids = array_column($sessionData['products'] ?? [], 'units');
    //     $vars['units_map'] = $unit_ids ? array_column($app->select("units", ["id", "name"], ["id" => $unit_ids]), 'name', 'id') : [];

    //     echo $app->render($template . '/warehouses/products-import-form.html', $vars);
    // })->setPermissions(['products.import']);


    $app->router('/products-import/crafting/{id}', 'GET', function ($vars) use ($app, $template) {
        $action = "import";
        $data = $app->get("warehouses", ["id", "stores", "code", "branch", "type", "date"], ["data" => 'pairing', "type" => 'export', "id" => $app->xss($vars['id']), "deleted" => 0]);
        if ($data > 1) {
            if ($_SESSION['products'][$action]['crafting'] != $vars['id']) {
                unset($_SESSION['products'][$action]);
                // echo ($_SESSION['products'][$action]);
            }
            if (empty($_SESSION['products'][$action]['crafting'])) {
                $_SESSION['products'][$action]['crafting'] = $data['id'];
                $details = $app->select("warehouses_details", "*", ["warehouses" => $data['id']]);
                foreach ($details as $key => $value) {
                    $getcrafting = $app->get("crafting", "*", ["id" => $value['crafting']]);
                    $_SESSION['products'][$action]['products'][] = [
                        "crafting" => $value['crafting'],
                        "amount" => $value['amount'],
                        "price" => $value['price'],
                        "cost" => $value['cost'],
                        "name" => $getcrafting['name'],
                        "vendor" => $getcrafting['vendor'] ?? '',
                        "code" => $getcrafting['code'],
                        "categorys" => $getcrafting['categorys'],
                        "group" => $getcrafting['group'],
                        "default_code" => $getcrafting['default_code'],
                        "units" => $getcrafting['units'],
                        "notes" => $getcrafting['notes'],
                        "content" => $getcrafting['content'],
                    ];
                }
                if (empty($_SESSION['products'][$action]['stores'])) {
                    $_SESSION['products'][$action]['stores'] = $app->get("stores", "*", ["id" => $data['stores']]);
                }
                if (empty($_SESSION['products'][$action]['branch'])) {
                    $_SESSION['products'][$action]['branch'] = $app->get("branch", "*", ["id" => $data['branch']]);
                }
                if (empty($_SESSION['products'][$action]['date'])) {
                    $_SESSION['products'][$action]['date'] = date("Y-m-d H:i:s");
                }
                $_SESSION['products'][$action]['content'] = "nhập hàng từ phiếu xuất kho chế tác #" . $data['code'] . $data['id'];
            }
            $data = [
                "date" => $_SESSION['products'][$action]['date'],
                "content" => $_SESSION['products'][$action]['content'],
                "stores" => $_SESSION['products'][$action]['stores'],
                "branch" => $_SESSION['products'][$action]['branch'],
                "products" => $_SESSION['products'][$action]['products'],
            ];
            $vars['data'] = $data;
            echo $app->render($template . '/warehouses/products-import-form.html', $vars);
        }
    })->setPermissions(['products.import']);


    $app->router('/products-update/import/{field}', 'POST', function ($vars) use ($app, $jatbi, $setting) {
        $app->header(['Content-Type' => 'application/json; charset=utf-8']);

        $action = 'import';
        $field = $vars['field'];
        if ($field == 'date' || $field == 'content') {
            $_SESSION['products'][$action][$field] = $app->xss($_POST['value']);
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
        } elseif ($field == 'cancel') {
            unset($_SESSION['products'][$action]);
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
        } elseif ($field == 'completed') {
            $total_products = 0;
            $error_warehouses = 'false';
            $error = [];
            $data = $_SESSION['products'][$action];
            $warehouses = $app->get("warehouses", ["id", "export_status"], ["id" => $data['crafting'], "deleted" => 0]);
            foreach ($data['products'] as $value) {
                $total_products += $value['amount'] * $value['price'];
                if ($value['amount'] == '' || $value['amount'] == 0 || $value['amount'] < 0) {
                    $error_warehouses = 'true';
                }
            }
            if ($data['content'] == '' || count($data['products']) == 0) {
                $error = ["status" => 'error', 'content' => $jatbi->lang("Lỗi trống")];
            } elseif ($error_warehouses == 'true') {
                $error = ['status' => 'error', 'content' => $jatbi->lang("Vui lòng nhập số lượng")];
            } elseif ($warehouses['export_status'] == 2) {
                $error = ['status' => 'error', 'content' => $jatbi->lang("Phiếu này đã được nhập")];
            }
            if (count($error) == 0) {
                $insert = [
                    "code" => 'PN',
                    "type" => 'import',
                    "data" => 'products',
                    "stores" => $data['stores']['id'],
                    "branch" => $data['branch']['id'],
                    "content" => $data['content'],
                    "vendor" => isset($data['vendor']) ? ($data['vendor']['id'] ?? null) : null,
                    "user" => $app->getSession("accounts")['id'] ?? 0,
                    "date" => $data['date'],
                    "active" => $jatbi->active(30),
                    "date_poster" => date("Y-m-d H:i:s"),
                    "crafting" => $data['crafting'],
                    "move" => $data['move'] ?? null,
                ];
                $app->insert("warehouses", $insert);
                $orderId = $app->id();
                $insert_products_logs = [];
                $pro_logs = [];
                $crafting_details_logs = [];
                foreach ($data['products'] as $key => $value) {
                    $getProducts = $app->get("crafting", ["id"], ["id" => $value['crafting']]);
                    $Selectdetails[$value['id'] ?? $key] = $app->select("crafting_details", "*", ["crafting" => $getProducts['id'], "deleted" => 0]);
                    $storeget = $data['stores'];
                    $branchget = $data['branch'];
                    $code = $storeget['code'] . $branchget['code'] . $value['code'];
                    $checkcode = $app->get("products", ["id", "code", "amount"], ["code" => $code, "deleted" => 0, "price" => $app->xss(str_replace([','], '', $value['price']))]);
                    if ($code == $checkcode['code']) {
                        $app->update("products", ["amount" => $checkcode['amount'] + $value['amount'], "crafting" => $data['crafting']], ["id" => $checkcode['id']]);
                        $app->update("products_details", ["deleted" => 1], ["products" => $checkcode['id']]);
                        $IDPro = $checkcode['id'];
                        foreach ($Selectdetails[$value['id'] ?? $key] as $detail) {
                            $crafting_details = [
                                "products" => $IDPro,
                                "ingredient" => $detail['ingredient'],
                                "code" => $detail['code'],
                                "type" => $detail['type'],
                                "group" => $detail['group'],
                                "categorys" => $detail['categorys'],
                                "pearl" => $detail['pearl'],
                                "sizes" => $detail['sizes'],
                                "colors" => $detail['colors'],
                                "units" => $detail['units'],
                                "price" => $detail['price'],
                                "cost" => $detail['cost'],
                                "amount" => $detail['amount'],
                                "total" => $detail['total'],
                                "user" => $app->getSession("accounts")['id'] ?? 0,
                                "date" => date("Y-m-d H:i:s"),
                            ];
                            $app->insert("products_details", $crafting_details);
                            $crafting_details_logs[] = $crafting_details;
                        }
                    } else {
                        $insert_products = [
                            "type" => 1,
                            "main" => 0,
                            "code" => $code,
                            "name" => $app->xss($value['name']),
                            "categorys" => $app->xss($value['categorys']),
                            "vat" => $setting['site_vat'],
                            "vat_type" => 1,
                            "amount" => $value['amount'],
                            "content" => $value['content'],
                            "vendor" => $app->xss($value['vendor']),
                            "group" => $app->xss($value['group']),
                            "price" => $app->xss(str_replace([','], '', $value['price'])),
                            "units" => $app->xss($value['units']),
                            "notes" => $app->xss($value['notes']),
                            "active" => $jatbi->active(32),
                            // "images" => $handle->file_dst_name == '' ? 'no-images.jpg' : $handle->file_dst_name,
                            "date" => date('Y-m-d H:i:s'),
                            "status" => 'A',
                            "user" => $app->getSession("accounts")['id'] ?? 0,
                            "new" => 1,
                            "stores" => $insert['stores'],
                            "branch" => $insert['branch'],
                            "code_products" => $value['code'],
                            "tkno" => $value['no'],
                            "tkco" => $value['co'],
                            "default_code" => $value['default_code'],
                            "crafting" => $data['crafting']
                        ];
                        $app->insert("products", $insert_products);
                        $IDPro = $app->id();
                        foreach ($Selectdetails[$value['id'] ?? $key] as $detail) {
                            $crafting_details = [
                                "products" => $IDPro,
                                "ingredient" => $detail['ingredient'],
                                "code" => $detail['code'],
                                "type" => $detail['type'],
                                "group" => $detail['group'],
                                "categorys" => $detail['categorys'],
                                "pearl" => $detail['pearl'],
                                "sizes" => $detail['sizes'],
                                "colors" => $detail['colors'],
                                "units" => $detail['units'],
                                "price" => $detail['price'],
                                "cost" => $detail['cost'],
                                "amount" => $detail['amount'],
                                "total" => $detail['total'],
                                "user" => $app->getSession("accounts")['id'] ?? 0,
                                "date" => $insert_products['date'],
                            ];
                            $app->insert("products_details", $crafting_details);
                            $crafting_details_logs[] = $crafting_details;
                        }
                        $insert_products_logs[] = $insert_products;  // Di chuyển vào đây để tránh undefined
                    }
                    $pro = [
                        "warehouses" => $orderId,
                        "data" => $insert['data'],
                        "type" => $insert['type'],
                        "vendor" => $value['vendor'],
                        "products" => $IDPro,
                        "amount_buy" => $value['amount_buy'] ?? 0,
                        "amount" => $value['amount'],
                        "amount_total" => $value['amount'],
                        "price" => $value['price'],
                        "cost" => $value['cost'],
                        "notes" => $value['notes'],
                        "date" => $insert['date_poster'],
                        "user" => $app->getSession("accounts")['id'] ?? 0,
                        "stores" => $insert['stores'],
                        "branch" => $insert['branch'],
                        "crafting" => $app->get("warehouses_details", "crafting", ["warehouses" => $app->get("warehouses", "crafting", ["id" => $orderId])]),
                    ];
                    $app->insert("warehouses_details", $pro);
                    $GetID = $app->id();
                    $warehouses_logs = [
                        "type" => $insert['type'],
                        "data" => $insert['data'],
                        "warehouses" => $orderId,
                        "details" => $GetID,
                        "products" => $IDPro,
                        "price" => $value['price'],
                        "amount" => $app->xss($value['amount']),
                        "total" => $value['amount'] * $value['price'],
                        "notes" => $value['notes'],
                        "duration" => $value['duration'] ?? null,
                        "date" => $insert['date_poster'],
                        "user" => $app->getSession("accounts")['id'] ?? 0,
                        "stores" => $insert['stores'],
                        "branch" => $insert['branch'],
                    ];
                    $app->insert("warehouses_logs", $warehouses_logs);
                    $pro_logs[] = $pro;
                    $app->update("products", ["crafting" => $pro["crafting"]], ["id" => $IDPro]);
                }
                $jatbi->logs('warehouses', $action, [$insert, $insert_products_logs, $pro_logs, $crafting_details_logs, $_SESSION['products'][$action]]);
                $app->update("warehouses", ["export_status" => 2, "export_date" => $insert['date_poster']], ["id" => $data['crafting']]);
                unset($_SESSION['products'][$action]);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $error['content']]);
            }
        }
    });


    $app->router("/warehouse_error", 'GET', function ($vars) use ($app, $jatbi, $template, $stores, $accStore) {
        $vars['title'] = $jatbi->lang("Sản phẩm lỗi");
        $action = "error";


        if (empty($_SESSION['warehouses'][$action]['type'])) {
            $_SESSION['warehouses'][$action]['type'] = $action;
        }
        if (empty($_SESSION['warehouses'][$action]['data'])) {
            $_SESSION['warehouses'][$action]['data'] = 'products';
        }
        if (count($stores) == 1 && empty($_SESSION['warehouses'][$action]['stores'])) {
            $store_id = $app->get("stores", "id", ["id" => $accStore, "status" => 'A', "deleted" => 0, "ORDER" => ["id" => "ASC"]]);
            $_SESSION['warehouses'][$action]['stores'] = ["id" => $store_id];
        }
        if (empty($_SESSION['warehouses'][$action]['date'])) {
            $_SESSION['warehouses'][$action]['date'] = date("Y-m-d");
        }

        $data = [
            "type" => $_SESSION['warehouses'][$action]['type'] ?? $action,
            "data" => $_SESSION['warehouses'][$action]['data'] ?? 'products',
            "stores" => $_SESSION['warehouses'][$action]['stores'] ?? [],
            "date" => $_SESSION['warehouses'][$action]['date'] ?? date("Y-m-d"),
            "content" => $_SESSION['warehouses'][$action]['content'] ?? '',
        ];
        $vars['data'] = $data;
        $vars['action'] = $action;


        $selected_products_session = $_SESSION['warehouses'][$action]['products'] ?? [];
        $product_ids = array_column($selected_products_session, 'products');
        $products_data = [];

        if (!empty($product_ids)) {
            $products_info = $app->select("products", [
                "[>]units" => ["units" => "id"],
                "[>]stores" => ["stores" => "id"],
                "[>]branch" => ["branch" => "id"],
            ], [
                "products.id",
                "products.code",
                "products.name",
                "products.price",
                "units.name(unit_name)",
                "stores.name(store_name)",
                "branch.name(branch_name)"
            ], ["products.id" => $product_ids]);

            $products_info_map = array_column($products_info, null, 'id');

            foreach ($selected_products_session as $key => $item) {
                $product_id = $item['products'];
                if (isset($products_info_map[$product_id])) {
                    $product_detail = $products_info_map[$product_id];
                    $amount = (float) str_replace(',', '', $item['amount']);
                    $price = (float) str_replace(',', '', $product_detail['price']);

                    $products_data[$key] = [
                        'key' => $key,
                        'product_id' => $product_id,
                        'code' => $product_detail['code'],
                        'name' => $product_detail['name'],
                        'price' => $price,
                        'amount' => $amount,
                        'total_price' => $amount * $price,
                        'unit' => $product_detail['unit_name'],
                        'notes' => $item['notes'],
                        'store' => $product_detail['store_name'],
                        'branch' => $product_detail['branch_name'],
                    ];
                }
            }
        }

        $vars['SelectProducts'] = $products_data;
        // var_dump($vars['SelectProducts']);

        $store_options = [];

        if (!empty($stores) && is_array($stores)) {
            foreach ($stores as $store) {
                if (isset($store['value']) && isset($store['text'])) {
                    $store_options[] = ['value' => $store['value'], 'text' => $store['text']];
                }
            }
        }

        $vars['store_options'] = $store_options;

        echo $app->render($template . '/warehouses/error.html', $vars);
    });

    $app->router('/warehouses-update-error/error/{field}/{action}/{id}', 'POST', function ($vars) use ($app, $jatbi) {

        $action = 'error';
        $id = $vars['id'];
        $app->header(['Content-Type' => 'application/json; charset=utf-8']);

        if ($vars['field'] == 'stores') {
            $data = $app->get("stores", ["id", "name"], ["id" => $app->xss($_POST['value'])]);
            if ($data > 1) {
                $_SESSION['warehouses'][$action]['stores'] = [
                    "id" => $data['id'],
                    "name" => $data['name'],
                ];
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Cập nhật thất bại")]);
            }
        } elseif ($vars['field'] == 'products') {
            if ($vars['action'] == 'add') {
                $data = $app->get("products", ["id", "price", "vendor", "images", "code", "name", "categorys", "default_code", "units", "notes", "branch", "amount"], ["id" => $app->xss($id)]);
                if ($data > 1) {
                    $warehouses = $data['amount'];
                    if ($data['amount'] <= 0 && $action == 'error') {
                        $error = $jatbi->lang("Số lượng không đủ");
                    }
                    if (empty($error)) {
                        if (!isset($_SESSION['warehouses'][$action]['products'][$data['id']])) {
                            $_SESSION['warehouses'][$action]['products'][$data['id']] = [
                                "products" => $data['id'],
                                "amount_buy" => 0,
                                "amount" => 1,
                                "price" => $data['price'],
                                "vendor" => $data['vendor'],
                                "images" => $data['images'],
                                "code" => $data['code'],
                                "name" => $data['name'],
                                "categorys" => $data['categorys'],
                                "default_code" => $data['default_code'],
                                "units" => $data['units'],
                                "notes" => $data['notes'],
                                "warehouses" => $warehouses,
                                "branch" => $data['branch']
                            ];
                            echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
                        } else {
                            $getPro = $_SESSION['warehouses'][$action]['products'][$data['id']];
                            $value = $getPro['amount'] + 1;
                            if ($value > $data['amount'] && $action == 'error') {
                                $_SESSION['warehouses'][$action]['products'][$data['id']]['amount'] = $data['amount'];
                                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Số lượng không đủ")]);
                            } else {
                                $_SESSION['warehouses'][$action]['products'][$data['id']]['amount'] = $value;
                                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
                            }
                        }
                    } else {
                        echo json_encode(['status' => 'error', 'content' => $error]);
                    }
                } else {
                    echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Cập nhật thất bại")]);
                }
            } elseif ($vars['action'] == 'deleted') {
                unset($_SESSION['warehouses'][$action]['products'][$id]);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
            } elseif ($vars['action'] == 'price') {
                $_SESSION['warehouses'][$action]['products'][$id][$vars['action']] = $app->xss(str_replace([','], '', $_POST['value']));
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
            } elseif ($vars['action'] == 'amount') {
                $value = $app->xss(str_replace([','], '', $_POST['value']));
                if ($value < 0) {
                    echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Số lượng không âm")]);
                } else {
                    $getAmount = $app->get("products", ["id", "amount"], ["id" => $_SESSION["warehouses"][$action]['products'][$id]['products']]);
                    if ($value > $getAmount['amount']) {
                        $_SESSION["warehouses"][$action]['products'][$id][$vars['action']] = $getAmount['amount'];
                        echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Số lượng không đủ")]);
                    } else {
                        $_SESSION["warehouses"][$action]['products'][$id][$vars['action']] = $value;
                        echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
                    }
                }
            } else {
                $_SESSION['warehouses'][$action]['products'][$id][$vars['action']] = $app->xss($_POST['value']);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
            }
        } elseif ($vars['field'] == 'date' || $vars['field'] == 'content') {

            $_SESSION['warehouses'][$action][$vars['field']] = $app->xss($_POST['value']);
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
        } elseif ($vars['field'] == 'cancel') {
            unset($_SESSION['warehouses'][$action]);
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
        } elseif ($vars['field'] == 'completed') {
            $datas = $_SESSION['warehouses'][$action];
            $total_products = 0;
            foreach ($datas['products'] as $value_amount) {
                $total_products += $value_amount['amount'] * $value_amount['price'];
                if ($value_amount['amount'] == '' || $value_amount['amount'] == 0 || $value_amount['amount'] < 0) {
                    $error_warehouses = 'true';
                }
            }

            if ($datas['stores']['id'] == '' || $datas['content'] == '' || count($datas['products']) == 0) {
                $error = ["status" => 'error', 'content' => $jatbi->lang("Lỗi trống")];
            } elseif ($error_warehouses == 'true') {
                $error = ['status' => 'error', 'content' => $jatbi->lang("Vui lòng nhập số lượng")];
            }

            if (count($error) == 0) {
                $insert = [
                    "code" => 'error',
                    "type" => $datas['type'],
                    "data" => $datas['data'],
                    "stores" => $datas['stores']['id'],
                    "content" => $datas['content'],
                    "user" => $app->getSession("accounts")['id'] ?? 0,
                    "date" => $datas['date'],
                    "active" => $jatbi->active(30),
                    "date_poster" => date("Y-m-d H:i:s"),
                    "receive_status" => 1,
                ];
                $app->insert("warehouses", $insert);
                $orderId = $app->id();

                foreach ($datas['products'] as $key => $value) {
                    $getProducts = $app->get("products", ["id", "amount", "amount_error"], ["id" => $value['products'], "deleted" => 0]);
                    $app->update("products", ["amount" => $getProducts['amount'] - $value['amount'], "amount_error" => $getProducts['amount_error'] + $value['amount']], ["id" => $getProducts['id']]);

                    $pro = [
                        "warehouses" => $orderId,
                        "data" => $insert['data'],
                        "type" => $insert['type'],
                        "products" => $value['products'],
                        "amount" => str_replace([','], '', $value['amount']),
                        "amount_total" => str_replace([','], '', $value['amount']),
                        "price" => $value['price'],
                        "notes" => $value['notes'],
                        "date" => date("Y-m-d H:i:s"),
                        "user" => $app->getSession("accounts")['id'] ?? 0,
                        "stores" => $insert['stores'],
                        "branch" => $value['branch'],
                    ];
                    $app->insert("warehouses_details", $pro);
                    $GetID = $app->id();

                    $warehouses_logs = [
                        "type" => $insert['type'],
                        "data" => $insert['data'],
                        "warehouses" => $orderId,
                        "details" => $GetID,
                        "products" => $value['products'],
                        "price" => $value['price'],
                        "amount" => str_replace([','], '', $value['amount']),
                        "total" => $value['amount'] * $value['price'],
                        "notes" => $value['notes'],
                        "date" => date('Y-m-d H:i:s'),
                        "user" => $app->getSession("accounts")['id'] ?? 0,
                        "stores" => $insert['stores'],
                        "branch" => $value['branch'],
                    ];
                    $app->insert("warehouses_logs", $warehouses_logs);
                    $pro_logs[] = $pro;
                }
                $jatbi->logs('warehouses', $action, [$insert, $pro_logs, $_SESSION['warehouses'][$action]]);
                unset($_SESSION['warehouses'][$action]);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode($error);
            }
        }
    });



    $app->router('/ingredient-import', ['GET'], function ($vars) use ($app, $jatbi, $template) {

        $jatbi->permission('ingredient');
        $_SESSION['ingredient_import_context'] = 'default';

        $vars['title'] = $jatbi->lang("Nhập kho nguyên liệu");

        // Lấy danh sách nguyên liệu đã thêm vào phiếu từ session
        $vars['SelectIngredients'] = $_SESSION['ingredient_import']['ingredients'] ?? [];

        echo $app->render($template . '/warehouses/ingredient-import.html', $vars);
    })->setPermissions(['ingredient']);





    $app->router('/ingredient-import-crafting', ['GET', 'POST'], function ($vars) use ($app, $jatbi,$template, $setting) {
        // ---- PHẦN XỬ LÝ CHO PHƯƠNG THỨC GET (HIỂN THỊ TRANG) ----
        if ($app->method() === 'GET') {
            $jatbi->permission('ingredient-import');
            $vars['title'] = $jatbi->lang("Danh sách nhập hàng");

            // Lấy danh sách tài khoản để đưa vào bộ lọc
            $accounts = $app->select("accounts", [
                "id(value)",
                "name(text)"
            ], [
                "deleted" => 0,
                "ORDER" => ["name" => "ASC"]
            ]);

            // Thêm tùy chọn "Tất cả" vào đầu danh sách
            array_unshift($accounts, [
                'value' => '',
                'text' => $jatbi->lang('Tất cả')
            ]);
            $vars['accounts'] = $accounts;

            // Render ra file view HTML
            echo $app->render($template . '/warehouses/ingredient-import-crafting.html', $vars);

            // ---- PHẦN XỬ LÝ CHO PHƯƠNG THỨC POST (CUNG CẤP DỮ LIỆU JSON CHO DATATABLES) ----
        } elseif ($app->method() === 'POST') {
            $jatbi->permission('ingredient-import');
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);

            // --- 1. Đọc các tham số từ DataTables gửi lên ---
            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
            $orderName = isset($_POST['order'][0]['column']) ? $_POST['columns'][$_POST['order'][0]['column']]['name'] : 'warehouses.id';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            // Lấy giá trị từ các bộ lọc tùy chỉnh
            $userFilter = isset($_POST['user']) ? $_POST['user'] : '';

            $dateFilter = isset($_POST['date']) ? $_POST['date'] : '';
            // --- 2. Xây dựng truy vấn với JOIN ---
            $joins = [
                "[>]accounts" => ["user" => "id"],
            ];

            // Xây dựng điều kiện WHERE cơ bản
            $where = [
                "AND" => [
                    "warehouses.deleted" => 0,
                    "warehouses.data" => 'crafting',
                    "warehouses.type" => 'export',
                    "warehouses.export_status" => 1,
                ],
            ];

            // Áp dụng bộ lọc tìm kiếm chung
            if (!empty($searchValue)) {
                $where['AND']['OR'] = [
                    'warehouses.code[~]' => $searchValue,
                    'warehouses.content[~]' => $searchValue,
                    'accounts.name[~]' => $searchValue,
                ];
            }

            // Áp dụng bộ lọc theo tài khoản
            if (!empty($userFilter)) {
                $where['AND']['warehouses.user'] = $userFilter;
            }

            if (!empty($dateFilter)) {
                $date_parts = explode(' - ', $dateFilter);
                if (count($date_parts) == 2) {
                    $date_from = date('Y-m-d 00:00:00', strtotime(str_replace('/', '-', trim($date_parts[0]))));
                    $date_to = date('Y-m-d 23:59:59', strtotime(str_replace('/', '-', trim($date_parts[1]))));
                    // Thêm điều kiện vào $where
                    $where['AND']['warehouses.date_poster[<>]'] = [$date_from, $date_to];
                }
            }

            // --- 3. Đếm tổng số bản ghi thỏa mãn điều kiện ---
            $recordsFiltered = $app->count("warehouses", $joins, "warehouses.id", $where);
            $recordsTotal = $app->count("warehouses", ["deleted" => 0, "data" => 'crafting', "type" => 'export', "export_status" => 1]);


            // Thêm điều kiện sắp xếp và phân trang
            $where['ORDER'] = [$orderName => strtoupper($orderDir)];
            $where['LIMIT'] = [$start, $length];

            // --- 4. Lấy dữ liệu chính ---
            $datasFormatted = [];
            $columns = [
                "warehouses.id",
                "warehouses.code",
                "warehouses.content",
                "warehouses.date_poster",
                "accounts.name(user_name)"
            ];

            $app->select("warehouses", $joins, $columns, $where, function ($data) use (&$datasFormatted, $jatbi, $app, $setting) {



                // Định dạng dữ liệu trả về cho mỗi hàng
                $datasFormatted[] = [
                    "code" => '<a class="fw-bold modal-url" data-url="/crafting/crafting-history-views' . $data['id'] . '">#' . $data['code'] . $data['id'] . '</a>',
                    "content" => $data['content'],
                    "date_poster" => date($setting['site_datetime'] ?? 'd/m/Y H:i:s', strtotime($data['date_poster'])),
                    "user" => $data['user_name'],
                    "action" => '<a class="btn btn-primary btn-sm pjax-load" href="/warehouses/ingredient-import/crafting/' . $data['id'] . '">' . $jatbi->lang('Nhập hàng') . '</a>',
                    "views" => '<button data-action="modal" data-url="/admin/logs-views/' . $data['id'] . '" class="btn btn-eclo-light btn-sm border-0 py-1 px-2 rounded-3" aria-label="' . $jatbi->lang('Xem') . '"><i class="ti ti-eye"></i></button>',

                ];
            });

            // --- 5. Trả về kết quả dưới dạng JSON cho DataTables ---
            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => $recordsTotal,
                "recordsFiltered" => $recordsFiltered,
                "data" => $datasFormatted,
            ]);
        }
    })->setPermissions(['ingredient-import']);

    // /**
    //  * Route mới: Nhập kho từ phiếu chế tác
    //  * URL: /ingredient-import-crafting/{id}
    //  * Nhiệm vụ: Lấy dữ liệu từ phiếu xuất kho chế tác và hiển thị trên template nhập kho chung.
    //  */
    // $app->router('/ingredient-import/crafting/{id}', ['GET'], function ($vars) use ($app, $jatbi, $setting) {
    //     $jatbi->permission('ingredient');

    //     $id = (int) ($vars['id'] ?? 0);


    //     // Xóa session cũ để bắt đầu phiếu mới từ dữ liệu chế tác
    //     unset($_SESSION['ingredient_import']);

    //     // Lấy thông tin phiếu xuất kho chế tác từ DB (logic từ code cũ)
    //     $data = $app->get("warehouses", ["id", "code"], ["data" => 'crafting', "type" => 'export', "id" => $id, "deleted" => 0]);

    //     if ($data) {
    //         $vars['title'] = $jatbi->lang("Nhập kho từ phiếu chế tác #" . $data['code'] . $data['id']);
    //         // Cờ này báo cho template biết đây là phiếu nhập từ chế tác
    //         $vars['is_crafting_import'] = true;

    //         // Khởi tạo và điền dữ liệu vào session
    //         $_SESSION['ingredient_import'] = [
    //             'ingredients' => [],
    //             'content' => "Nhập hàng từ phiếu xuất kho chế tác #" . $data['code'] . $data['id'],
    //             'crafting_id' => $data['id'], // Lưu ID phiếu gốc để xử lý khi hoàn tất
    //             'date' => date("Y-m-d H:i:s")
    //         ];

    //         // Lấy chi tiết các nguyên liệu từ phiếu xuất kho
    //         $details = $app->select("warehouses_details", ["ingredient", "amount", "price"], ["warehouses" => $data['id']]);

    //         foreach ($details as $value) {
    //             $getPro = $app->get("ingredient", ["id", "code", "name_ingredient", "units", "notes", "amount as stock_quantity"], ["id" => $value['ingredient']]);
    //             if ($getPro) {
    //                 $unit_name = $app->get("units", "name", ["id" => $getPro['units']]) ?? 'N/A';
    //                 $_SESSION['ingredient_import']['ingredients'][$getPro['id']] = [
    //                     "ingredient_id" => $getPro['id'],
    //                     "code" => $getPro['code'],
    //                     "name" => $getPro['name_ingredient'],
    //                     "selling_price" => $value['price'] ?? 0,
    //                     "stock_quantity" => $getPro['stock_quantity'] ?? 0,
    //                     "amount" => $value['amount'],
    //                     "price" => $value['price'] ?? 0,
    //                     "unit_name" => $unit_name,
    //                     "notes" => $getPro['notes'] ?? '',
    //                 ];
    //             }
    //         }
    //     } else {
    //         // Nếu không tìm thấy phiếu, hiển thị thông báo
    //         $vars['title'] = $jatbi->lang("Không tìm thấy phiếu");
    //         $vars['is_crafting_import'] = true; // Vẫn là context crafting
    //         $vars['error_message'] = "Không tìm thấy phiếu chế tác hợp lệ với ID này.";
    //     }

    //     // CUỐI CÙNG, RENDER RA CÙNG TEMPLATE VỚI ROUTE TRÊN
    //     echo $app->render($template . '/warehouses/ingredient-import-from-crafting.html', $vars);

    // })->setPermissions(['ingredient']);


    $app->router('/ingredient-import/crafting/{id}', ['GET'], function ($vars) use ($app, $jatbi, $template) {
        $id = (int) ($vars['id'] ?? 0);

        unset($_SESSION['ingredient_import_crafting']);

        $data = $app->get("warehouses", ["id", "code"], ["data" => 'crafting', "type" => 'export', "id" => $id, "deleted" => 0]);

        if ($data) {
            $vars['title'] = $jatbi->lang("Nhập kho từ phiếu chế tác #" . $data['code'] . $data['id']);

            $_SESSION['ingredient_import_crafting'] = [
                'ingredients' => [],
                'content' => "Nhập hàng từ phiếu xuất kho chế tác #" . $data['code'] . $data['id'],
                'crafting_id' => $data['id'],
                'date' => date("Y-m-d H:i:s")
            ];

            $details = $app->select("warehouses_details", ["ingredient", "amount", "price"], ["warehouses" => $data['id']]);

            foreach ($details as $value) {
                $getPro = $app->get("ingredient", ["id", "code", "name_ingredient", "units", "notes"], ["id" => $value['ingredient']]);
                if ($getPro) {
                    $unit_name = $app->get("units", "name", ["id" => $getPro['units']]) ?? 'N/A';


                    $stock_quantity = $app->sum("warehouses_details", "amount_total", [
                        "data" => "ingredient",
                        "type" => "import",
                        "deleted" => 0,
                        "ingredient" => $value['ingredient']
                    ]);

                    $_SESSION['ingredient_import_crafting']['ingredients'][$getPro['id']] = [
                        "ingredient_id" => $getPro['id'],
                        "code" => $getPro['code'],
                        "name" => $getPro['name_ingredient'],
                        "selling_price" => $value['price'] ?? 0,

                        "stock_quantity" => $stock_quantity,
                        "amount" => $value['amount'],
                        "price" => $value['price'] ?? 0,
                        "unit_name" => $unit_name,
                        "notes" => $getPro['notes'] ?? '',
                    ];
                }
            }
        } else {
            $vars['title'] = $jatbi->lang("Không tìm thấy phiếu");
        }

        echo $app->render($template . '/warehouses/ingredient-import-from-crafting.html', $vars);
    })->setPermissions(['ingredient']);


    $app->router("/warehouses-import", 'GET', function ($vars) use ($app, $jatbi, $template, $stores, $accStore) {
        $vars['title'] = $jatbi->lang("Nhập hàng");
        $action = "import";
        $type = $vars['type'] ?? '';
        $id = $vars['id'] ?? 0;

        if (isset($_SESSION['warehouses'][$action]['import_type']) && $_SESSION['warehouses'][$action]['import_type'] !== $type) {
            unset($_SESSION['warehouses'][$action]);
        }
        $_SESSION['warehouses'][$action]['import'] = $type;
        if (empty($_SESSION['warehouses'][$action]['type'])) {
            $_SESSION['warehouses'][$action]['type'] = $action;
        }
        if (empty($_SESSION['warehouses'][$action]['data'])) {
            $_SESSION['warehouses'][$action]['data'] = 'products';
        }
        if (empty($_SESSION['warehouses'][$action]['date'])) {
            $_SESSION['warehouses'][$action]['date'] = date("Y-m-d");
        }
        if (count($stores) == 1 && empty($_SESSION['warehouses'][$action]['stores'])) {
            $_SESSION['warehouses'][$action]['stores'] = $app->get("stores", "*", ["id" => array_values($accStore)[0] ?? 0, "status" => 'A', "deleted" => 0]);
        }

        $vars['data'] = $_SESSION['warehouses'][$action] ?? [];
        $vars['action'] = $action;
        $vars['type'] = $type;

        $session_products = $_SESSION['warehouses'][$action]['products'] ?? [];
        $enriched_products = [];
        if (!empty($session_products)) {
            $product_ids = array_column($session_products, 'products');

            $products_info = $app->select("products", [
                "[>]vendors" => ["vendor" => "id"],
                "[>]units" => ["units" => "id"],
                "[>]stores" => ["stores" => "id"],
                "[>]branch" => ["branch" => "id"]
            ], [
                "products.id",
                "products.code",
                "products.name",
                "products.vendor(vendor_id)",
                "vendors.name(vendor_name)",
                "units.name(unit_name)",
                "stores.name(store_name)",
                "branch.name(branch_name)"
            ], ["products.id" => $product_ids]);

            $products_map = array_column($products_info, null, 'id');

            foreach ($session_products as $key => $item) {
                $p_id = $item['products'] ?? 0;
                if (isset($products_map[$p_id])) {
                    $product_detail = $products_map[$p_id];
                    $price = (float) str_replace(',', '', $item['price'] ?? 0);
                    $amount = (float) str_replace(',', '', $item['amount'] ?? 0);

                    $enriched_products[$key] = array_merge($item, [
                        'key' => $key,
                        'code' => $product_detail['code'],
                        'name' => $product_detail['name'],
                        'vendor_name' => $product_detail['vendor_name'],
                        'unit' => $product_detail['unit_name'],
                        'store' => $product_detail['store_name'],
                        'branch' => $product_detail['branch_name'],
                        'total_price' => $price * $amount,
                    ]);
                }
            }
        }
        $vars['SelectProducts'] = $enriched_products;

        $store_options = [];
        if (!empty($stores)) {
            foreach ($stores as $store) {
                if (isset($store['value']) && isset($store['text'])) {
                    $store_options[] = ['value' => $store['value'], 'text' => $store['text']];
                }
            }
        }
        $vars['store_options'] = $store_options;

        $selected_store_id = $vars['data']['stores']['id'] ?? 0;
        $vars['branch_options'] = $app->select("branch", ["id(value)", "name(text)"], ["deleted" => 0, "stores" => $selected_store_id, "status" => 'A']);

        echo $app->render($template . '/warehouses/import.html', $vars);
    })->setPermissions(['warehouses-import']);


    // $app->router("/warehouses-import/{type}/{id}", 'GET', function ($vars) use ($app, $jatbi, $setting, $stores, $accStore) {
    //     $vars['title'] = $jatbi->lang("Nhập hàng");
    //     $action = "import";
    //     $type = $vars['type'] ?? '';
    //     $id = $vars['id'] ?? 0;

    //     if (isset($_SESSION['warehouses'][$action]['import_type']) && $_SESSION['warehouses'][$action]['import_type'] !== $type) {
    //         unset($_SESSION['warehouses'][$action]);
    //     }
    //     $_SESSION['warehouses'][$action]['import_type'] = $type;

    //     if ($type === 'purchase' && empty($_SESSION['warehouses'][$action]['products'])) {
    //         $purchase = $app->get("purchase", ['id', 'code', 'vendor'], ["id" => $id, "deleted" => 0, "status" => 3]);
    //         if ($purchase) {
    //             // Sửa lại dòng này
    //             $purchase_products = $app->select("purchase_products", ['products', 'amount', 'price', 'vendor', 'duration'], ["purchase" => $purchase['id'], "deleted" => 0]);
    //             // Và thêm 'duration' vào đây
    //             foreach ($purchase_products as $p) {
    //                 $_SESSION['warehouses'][$action]['products'][$p['products']] = ["products" => $p['products'], "amount" => $p['amount'], "price" => $p['price'], 'duration' => $p['duration']];
    //             }
    //             $_SESSION['warehouses'][$action]['source_id'] = $purchase['id'];
    //             $_SESSION['warehouses'][$action]['content'] = "Nhập hàng từ phiếu mua #" . $purchase['code'];
    //             $_SESSION['warehouses'][$action]['vendor'] = $app->get("vendors", "*", ["id" => $purchase['vendor']]);
    //         }
    //     } elseif ($type === 'move' && empty($_SESSION['warehouses'][$action]['products'])) {
    //         $move = $app->get("warehouses", '*', ["id" => $id, "deleted" => 0, "receive_status" => [1, 3], "stores_receive" => $accStore]);
    //         if ($move) {
    //             // Sửa lại dòng này
    //             $move_products = $app->select("warehouses_details", ['id', 'products', 'amount', 'price', 'duration'], ["warehouses" => $move['id'], "deleted" => 0]);
    //             // Và thêm 'duration' vào đây
    //             foreach ($move_products as $p) {
    //                 $_SESSION['warehouses'][$action]['products'][$p['products']] = ["products" => $p['products'], "amount" => $p['amount'], "price" => $p['price'], "details_id" => $p['id'], 'duration' => $p['duration']];
    //             }
    //             $_SESSION['warehouses'][$action]['source_id'] = $move['id'];
    //             $_SESSION['warehouses'][$action]['stores'] = $app->get("stores", "*", ["id" => $move['stores_receive']]);
    //             $_SESSION['warehouses'][$action]['branch'] = $app->get("branch", "*", ["id" => $move['branch_receive']]);
    //             $_SESSION['warehouses'][$action]['content'] = "Nhập hàng từ phiếu chuyển #" . ($move['code'] ?? $move['id']);
    //         }
    //     }

    //     if (empty($_SESSION['warehouses'][$action]['date'])) {
    //         $_SESSION['warehouses'][$action]['date'] = date("Y-m-d");
    //     }
    //     if (count($stores) == 1 && empty($_SESSION['warehouses'][$action]['stores'])) {
    //         $_SESSION['warehouses'][$action]['stores'] = $app->get("stores", "*", ["id" => array_values($accStore)[0] ?? 0, "status" => 'A', "deleted" => 0]);
    //     }

    //     $vars['data'] = $_SESSION['warehouses'][$action] ?? [];
    //     $vars['action'] = $action;
    //     $vars['type'] = $type;

    //     $session_products = $_SESSION['warehouses'][$action]['products'] ?? [];
    //     $enriched_products = [];
    //     if (!empty($session_products)) {
    //         $product_ids = array_column($session_products, 'products');

    //         $products_info = $app->select("products", [
    //             "[>]vendors" => ["vendor" => "id"],
    //             "[>]units" => ["units" => "id"],
    //             "[>]stores" => ["stores" => "id"],
    //             "[>]branch" => ["branch" => "id"]
    //         ], [
    //             "products.id",
    //             "products.code",
    //             "products.name",
    //             "products.vendor(vendor_id)",
    //             "vendors.name(vendor_name)",
    //             "units.name(unit_name)",
    //             "stores.name(store_name)",
    //             "branch.name(branch_name)"
    //         ], ["products.id" => $product_ids]);

    //         $products_map = array_column($products_info, null, 'id');

    //         foreach ($session_products as $key => $item) {
    //             $p_id = $item['products'];
    //             if (isset($products_map[$p_id])) {
    //                 $product_detail = $products_map[$p_id];
    //                 $price = (float) str_replace(',', '', $item['price'] ?? 0);
    //                 $amount = (float) str_replace(',', '', $item['amount'] ?? 0);

    //                 $enriched_products[$key] = array_merge($item, [
    //                     'key' => $key,
    //                     'code' => $product_detail['code'],
    //                     'name' => $product_detail['name'],
    //                     'vendor_name' => $product_detail['vendor_name'],
    //                     'unit' => $product_detail['unit_name'],
    //                     'store' => $product_detail['store_name'],
    //                     'branch' => $product_detail['branch_name'],
    //                     'total_price' => $price * $amount,
    //                 ]);
    //             }
    //         }
    //     }
    //     $vars['SelectProducts'] = $enriched_products;

    //     $store_options = [];
    //     if (!empty($stores)) {
    //         foreach ($stores as $store) {
    //             if (isset($store['value']) && isset($store['text'])) {
    //                 $store_options[] = ['value' => $store['value'], 'text' => $store['text']];
    //             }
    //         }
    //     }
    //     $vars['store_options'] = $store_options;

    //     $selected_store_id = $vars['data']['stores']['id'] ?? 0;
    //     $vars['branch_options'] = $app->select("branch", ["id(value)", "name(text)"], ["deleted" => 0, "stores" => $selected_store_id, "status" => 'A']);

    //     echo $app->render($template . '/warehouses/import.html', $vars);
    // })->setPermissions(['warehouses-import']);


    $app->router('/warehouses-update/{action}/{product}/{do}/{id}', 'POST', function ($vars) use ($app, $jatbi, $setting, $stores, $accStore) {
        $app->header(['Content-Type' => 'application/json; charset=utf-8']);
        $action = $vars['action'];
        $router['3'] = $vars['product'];
        $router['4'] = $vars['do'];
        $router['5'] = $vars['id'];

        $error = [];
        $total_products = 0;
        $error_warehouses = false;
        $kiemtra = false;
        $sanpham_kiemtra = [];
        $kiemtracuahang = false;
        $cancel_amount = 0;
        $AmountMinus = false;
        $pro_logs = [];
        if ($router['3'] == 'stores') {
            $data = $app->get("stores", ["id", "name"], ["id" => $app->xss($_POST['value'])]);
            if ($data > 1) {
                $_SESSION['warehouses'][$action]['stores'] = [
                    "id" => $data['id'],
                    "name" => $data['name'],
                ];
                if ($action == 'cancel') {
                    $datas = $app->get("products", ["id", "units"], ["id" => $_SESSION['warehouses'][$action]['products']]);
                    unset($_SESSION['warehouses'][$action]['warehouses']);
                    $warehouses = $app->select("warehouses_details", ["id", "products", "vendor", "amount_total", "price", 'stores'], [
                        "type" => 'import',
                        "products" => $datas['id'],
                        "stores" => $_SESSION['warehouses'][$action]['stores'],
                        "deleted" => 0,
                        "amount_total[>]" => 0
                    ]);
                    foreach ($warehouses as $key => $value) {
                        $_SESSION['warehouses'][$action]['warehouses'][$value['id']] = [
                            "id" => $value['id'],
                            "products" => $value['products'],
                            "vendor" => $value['vendor'],
                            "duration" => $value['duration'],
                            "amount_total" => $value['amount_total'],
                            "amount" => '',
                            "price" => $value['price'],
                            "units" => $datas['units'],
                            "date" => $value['date'],
                            "stores" => $value['stores'],
                        ];
                    }
                }
                if ($action == 'move') {
                    unset($_SESSION['warehouses'][$action]['products']);
                    unset($_SESSION['warehouses'][$action]['branch']);
                }
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Cập nhật thất bại")]);
            }
        } elseif ($router['3'] == 'check-update') {
            $_SESSION['warehouses'][$action]['products'][$router['4']]['status'] = $_POST['value'];
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
        } elseif ($router['3'] == 'branch') {
            $data = $app->get("branch", ["id", "name"], ["id" => $app->xss($_POST['value'])]);
            if ($data > 1) {
                $_SESSION['warehouses'][$action]['branch'] = [
                    "id" => $data['id'],
                    "name" => $data['name'],
                ];
                if ($action == 'move') {
                    unset($_SESSION['warehouses'][$action]['products']);
                }
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Cập nhật thất bại")]);
            }
        } elseif ($router['3'] == 'branch_receive') {
            $data = $app->get("branch", ["id", "name"], ["id" => $app->xss($_POST['value'])]);
            if ($data > 1) {
                $_SESSION['warehouses'][$action]['branch_receive'] = [
                    "id" => $data['id'],
                    "name" => $data['name'],
                ];
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Cập nhật thất bại")]);
            }
        } elseif ($router['3'] == 'warehouses') {
            $value = $app->xss($_POST['value']);
            if ($value < 0) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Không thể cập nhật số lượng âm")]);
            } else {
                if ($_SESSION['warehouses'][$action]['warehouses'][$router['5']]['amount_total'] >= $value) {
                    $_SESSION['warehouses'][$action]['warehouses'][$router['5']][$router['4']] = $value;
                    echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
                } else {
                    echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Số lượng hủy lớn hơn tồn")]);
                }
            }
        } elseif ($router['3'] == 'stores_receive') {
            $data = $app->get("stores", ["id", "name"], ["id" => $app->xss($_POST['value'])]);
            if ($data > 1) {
                $_SESSION['warehouses'][$action]['stores_receive'] = [
                    "id" => $data['id'],
                    "name" => $data['name'],
                ];
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Cập nhật thất bại")]);
            }
        } elseif ($router['3'] == 'data') {
            unset($_SESSION['warehouses'][$action]['products']);
            $_SESSION['warehouses'][$action]['data'] = $app->xss($_POST['value']);
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
        } elseif ($router['3'] == 'products') {
            if ($router['4'] == 'add') {
                $data = $app->get("products", ["id", "images", "code", "name", "categorys", "default_code", "units", "notes", "crafting", "amount"], ["id" => $app->xss($router['5'])]);
                if ($data > 1) {
                    if ($data['amount'] <= 0 && $action == 'move') {
                        $error = $jatbi->lang("Số lượng không đủ");
                    }
                    if (empty($error)) {
                        if (!isset($_SESSION['warehouses'][$action]['products'][$data['id']])) {
                            $_SESSION['warehouses'][$action]['products'][$data['id']] = [
                                "products" => $data['id'],
                                "amount_buy" => 0,
                                "amount" => 1,
                                "price" => 0,
                                "images" => $data['images'],
                                "code" => $data['code'],
                                "name" => $data['name'],
                                "categorys" => $data['categorys'],
                                "default_code" => $data['default_code'],
                                "units" => $data['units'],
                                "notes" => $data['notes'],
                                "crafting" => $data['crafting'],
                                "warehouses" => $data['amount'],
                            ];
                            echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
                        } else {
                            $getPro = $_SESSION['warehouses'][$action]['products'][$data['id']];
                            $value = $getPro['amount'] + 1;
                            if ($value > $data['amount'] && $action == 'move') {
                                $_SESSION['warehouses'][$action]['products'][$data['id']]['amount'] = $data['amount'];
                                echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Số lượng không đủ")]);
                            } else {
                                $_SESSION['warehouses'][$action]['products'][$data['id']]['amount'] = $value;
                                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
                            }
                        }
                    } else {
                        echo json_encode(['status' => 'error', 'content' => $error,]);
                    }
                } else {
                    echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Cập nhật thất bại")]);
                }
            } elseif ($router['4'] == 'deleted') {
                unset($_SESSION['warehouses'][$action]['products'][$router['5']]);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
            } elseif ($router['4'] == 'price') {
                $_SESSION['warehouses'][$action]['products'][$router['5']][$router['4']] = $app->xss(str_replace([','], '', $_POST['value']));
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
            } elseif ($router['4'] == 'amount') {
                $value = $app->xss(str_replace([','], '', $router['5']));
                if ($value < 0) {
                    echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Số lượng không âm")]);
                } else {
                    $getAmount = $app->get("products", ["id", "amount"], ["id" => $_SESSION["warehouses"][$action]['products'][$router['5']]['products']]);
                    if ($value > $getAmount['amount'] && $action == 'move' || $value > $getAmount['amount'] && $action == 'cancel') {
                        $_SESSION["warehouses"][$action]['products'][$router['5']][$router['4']] = $getAmount['amount'];
                        echo json_encode(['status' => 'error', 'content' => $jatbi->lang("Số lượng không đủ")]);
                    } else {
                        $_SESSION["warehouses"][$action]['products'][$router['5']][$router['4']] = $app->xss(str_replace([','], '', $_POST['value']));
                        echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
                    }
                }
            } else {
                $_SESSION['warehouses'][$action]['products'][$router['5']][$router['4']] = $app->xss($_POST['value']);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
            }
        } elseif ($router['3'] == 'date' || $router['3'] == 'content') {
            $_SESSION['warehouses'][$action][$router['3']] = $app->xss($_POST['value']);
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
        } elseif ($router['3'] == 'cancel') {
            unset($_SESSION['warehouses'][$action]);
            echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
        } elseif ($router['3'] == 'completed') {
            $datas = $_SESSION['warehouses'][$action];
            $warehouses1 = $app->get("warehouses", "receive_status", ["id" => $datas['move'] ?? 0, "deleted" => 0]);
            $kiemtra = 'false';
            $sanpham_kiemtra = [];
            $kiemtracuahang = 'false';

            foreach ($datas['products'] as $value_amount) {
                $name_po = $app->get("products", ["amount", "code", "stores", "branch"], ["id" => $value_amount['products']]);
                $total_products += $value_amount['amount'] * $value_amount['price'];
                if ($value_amount['amount'] == '' || $value_amount['amount'] == 0 || $value_amount['amount'] < 0) {
                    $error_warehouses = 'true';
                }
                if ($action == 'move') {
                    if ($name_po['amount'] <= 0) {
                        $kiemtra = 'true';
                        $sanpham_kiemtra[] = $name_po['code'];
                    }
                }
                if ($action == 'import') {
                    if ($name_po['stores'] != $datas['stores']['id'] || $name_po['branch'] != ($datas['branch']['id'] ?? 0)) {
                        $kiemtracuahang = 'true';
                    }
                }
            }
            if ($action == 'move' && $kiemtra == 'true') {
                $error = [
                    'status' => 'error',
                    'content' => 'Những mã hiện hết số lượng, hãy kiểm tra lại: ' . implode(", ", $sanpham_kiemtra)
                ];
            }
            if ($action == 'import' && $kiemtracuahang == 'true' && (empty($datas['move'] ?? null) && empty($datas['purchase'] ?? null))) {
                $error = [
                    'status' => 'error',
                    'content' => 'Cửa hàng hoặc quầy hàng chọn khác với Cửa hàng hoặc quầy hàng của sản phẩm'
                ];
            }
            if ($action == 'cancel') {
                foreach ($datas['warehouses'] as $AmounCancel) {
                    $cancel_amount += $AmounCancel['amount'];
                    if ($AmounCancel['amount'] < 0) {
                        $AmountMinus = "true";
                    }
                }
            }
            if ($cancel_amount <= 0 && $action == 'cancel') {
                $error = ["status" => 'error', 'content' => $jatbi->lang("Phải có số lượng hủy")];
            } elseif ($AmountMinus == "true" && $action == 'cancel') {
                $error = ["status" => 'error', 'content' => $jatbi->lang("Số lượng không âm")];
            }
            if ($router['4'] == 'moves') {
                if ($datas['stores_receive']['id'] == '' || $datas['branch_receive']['id'] == '') {
                    $error = ["status" => 'error', 'content' => $jatbi->lang("Lỗi trống")];
                }
            }
            if (
                empty($datas['stores']['id']) ||
                empty($datas['branch']['id']) ||
                empty($datas['type']) ||
                empty($datas['content']) ||
                count($datas['products'] ?? []) == 0 ||
                (count($datas['warehouses'] ?? []) == 0 && $action == 'cancel')
            ) {
                $error = ["status" => 'error', 'content' => "Lỗi trống"];
            } elseif ($warehouses1 == 2) {
                $error = ['status' => 'error', 'content' => $jatbi->lang("Phiếu này đã được nhập")];
            }
            if (count($error) == 0) {
                $insert = [
                    "code" => $datas['type'] == 'import' ? 'PN' : 'PX',
                    "type" => $datas['type'],
                    "data" => $datas['data'],
                    "stores" => $datas['type'] == 'import' ? $datas['stores']['id'] : $_SESSION['warehouses']['move']['stores']['id'],
                    "branch" => $datas['branch']['id'],
                    "stores_receive" => $datas['stores_receive']['id'] ?? null,
                    "branch_receive" => $datas['branch_receive']['id'] ?? null,
                    "content" => $datas['content'],
                    "vendor" => $datas['vendor']['id'] ?? null,
                    "user" => $app->getSession("accounts")['id'] ?? 0,
                    "date" => $datas['date'],
                    "active" => $jatbi->active(30),
                    "date_poster" => date("Y-m-d H:i:s"),
                    "purchase" => $datas['purchase'] ?? "",
                    "move" => $datas['move'] ?? "",
                    "receive_status" => 1,
                ];
                $app->insert("warehouses", $insert);
                $orderId = $app->id();
                if (isset($datas['purchase'])) {
                    $app->update("purchase", ["status" => 5], ["id" => $datas['purchase']]);
                    $app->insert("purchase_logs", [
                        "purchase" => $datas['purchase'],
                        "user" => $app->getSession("accounts")['id'] ?? 0,
                        "content" => $app->xss('Nhập hàng'),
                        "status" => 5,
                        "date" => date('Y-m-d H:i:s'),
                    ]);
                }
                if (isset($datas['move'])) {
                    foreach ($datas['products'] as $key => $value_move) {
                        if ($value_move['amount'] < $value_move['amount_old']) {
                            $move_error[] = $value_move['amount_old'] - $value_move['amount'];
                        }
                        $app->update("warehouses_details", ["amount_move" => $value_move['amount']], ["id" => $value_move['details']]);
                    }
                    if (count($move_error) > 0) {
                        $receive_status = 3;
                    } else {
                        $receive_status = 2;
                    }
                    $app->update("warehouses", [
                        "receive_status" => $receive_status,
                        "receive_date" => date("Y-m-d H:i:s"),
                    ], ["id" => $datas['move']]);
                }
                foreach ($datas['products'] as $key1 => $value) {
                    $getProducts = $app->get("products", ["id", "code_products", "code", "name", "categorys", "group", "vendor", "price", "cost", "units", "tkco", "tkno", "default_code", "amount"], ["id" => $value['products'], "deleted" => 0]);
                    $crafting = $app->get("crafting", "*", ["id" => ($value['crafting'] ?? null)]);
                    if ($insert['type'] == "move") {
                        $storeget = $app->get("stores", "*", ["id" => $insert['stores_receive']]);
                        $branchget = $app->get("branch", "*", ["id" => $insert['branch_receive']]);
                        if ($getProducts['code_products'] == NULL) {
                            $code = $getProducts['code'];
                        } else {
                            if ($insert['stores_receive'] == $insert['stores']) {
                                $code = $branchget['code'] . $getProducts['code_products'];
                            } else {
                                $code = $storeget['code'] . $branchget['code'] . $getProducts['code_products'];
                            }
                        }
                        $checkcode = $app->get("products", ["id", "code"], ["code" => $code, "deleted" => 0, 'status' => 'A', "stores" => $insert['stores_receive'], "branch" => $insert['branch_receive']]);
                        if ($code == $checkcode['code'] && $getProducts['code_products'] != NULL) {
                            $tam = [
                                "products" => $checkcode['id'],
                                "value" => $value['products'],
                                "warehouses" => $orderId,
                                "date" => date('Y-m-d H:i:s'),
                            ];
                            $app->insert("tam", $tam);
                        } else {
                            $insert_products = [
                                "type" => $app->get("products", "type", ["id" => $value['products']]),
                                "main" => 0,
                                "code" => $code,
                                "name" => $getProducts['name'],
                                "categorys" => $getProducts['categorys'],
                                "vat" => $setting['site_vat'],
                                "vat_type" => 1,
                                "amount" => 0,
                                "content" => $value['content'],
                                "vendor" => $getProducts['vendor'],
                                "group" => $getProducts['group'],
                                "price" => $app->xss(str_replace([','], '', $getProducts['price'])),
                                "cost" => $app->xss(str_replace([','], '', $getProducts['cost'])),
                                "units" => $getProducts['units'],
                                "notes" => $app->xss($crafting['notes']),
                                "active" => $jatbi->active(32),
                                // "images"		=> $handle->file_dst_name==''?'no-images.jpg':$handle->file_dst_name,
                                "date" => date('Y-m-d H:i:s'),
                                "status" => 'A',
                                "user" => $app->getSession("accounts")['id'] ?? 0,
                                "new" => 1,
                                "crafting" => $value['crafting'],
                                "stores" => $insert['stores_receive'],
                                "branch" => $insert['branch_receive'],
                                "code_products" => $getProducts['code_products'] == NULL ? NULL : $getProducts['code_products'],
                                "tkno" => $getProducts['tkco'],
                                "tkco" => $getProducts['tkno'],
                                "default_code" => $getProducts['default_code'],
                            ];
                            $app->insert("products", $insert_products);
                            $Gid = $app->id();
                            $getDetails = $app->select("products_details", ["id", "ingredient", "code", "type", "group", "categorys", "pearl", "sizes", "colors", "units", "price", "cost", "amount", "total"], ["products" => $value['products'], "deleted" => 0]);
                            foreach ($getDetails as $detail) {
                                $products_details = [
                                    "products" => $Gid,
                                    "ingredient" => $detail['ingredient'],
                                    "code" => $detail['code'],
                                    "type" => $detail['type'],
                                    "group" => $detail['group'],
                                    "categorys" => $detail['categorys'],
                                    "pearl" => $detail['pearl'],
                                    "sizes" => $detail['sizes'],
                                    "colors" => $detail['colors'],
                                    "units" => $detail['units'],
                                    "price" => $detail['price'],
                                    "cost" => $detail['cost'],
                                    "amount" => $detail['amount'],
                                    "total" => $detail['total'],
                                    "user" => $app->getSession("accounts")['id'] ?? 0,
                                    "date" => date("Y-m-d H:i:s"),
                                ];
                                $app->insert("products_details", $products_details);
                            }
                            $tam = [
                                "products" => $Gid,
                                "value" => $value['products'],
                                "warehouses" => $orderId,
                                "date" => date('Y-m-d H:i:s'),
                            ];
                            $app->insert("tam", $tam);
                        }
                    }
                    if ($action == 'import') {
                        if (!empty($datas['move'])) {
                            $checktam = $app->get("tam", "products", ["value" => $value['products'], "warehouses" => $app->get("warehouses", "move", ["id" => $orderId])]);
                            $pro = [
                                "warehouses" => $orderId,
                                "data" => $insert['data'],
                                "type" => $insert['type'],
                                "products" => $value['status'] == 0 ? $checktam : $value['products'],
                                "amount_buy" => $value['amount_buy'],
                                "amount" => $value['amount'],
                                "amount_total" => $value['amount'],
                                "price" => $getProducts['price'],
                                "notes" => $value['notes'],
                                "date" => date("Y-m-d H:i:s"),
                                "user" => $app->getSession("accounts")['id'] ?? 0,
                                "stores" => $value['status'] == 0 ? $insert['stores'] : $value['stores'],
                                "branch" => $value['status'] == 0 ? $insert['branch'] : $value['branch'],
                            ];
                            $app->insert("warehouses_details", $pro);
                            $GetID = $app->id();
                            $warehouses_logs = [
                                "type" => $insert['type'],
                                "data" => $insert['data'],
                                "warehouses" => $orderId,
                                "details" => $GetID,
                                "products" => $pro['products'],
                                "price" => $pro['price'],
                                "amount" => str_replace([','], '', $value['amount']),
                                "total" => $value['amount'] * $value['price'],
                                "notes" => $value['notes'],
                                "date" => date('Y-m-d H:i:s'),
                                "user" => $app->getSession("accounts")['id'] ?? 0,
                                "stores" => $value['status'] == 0 ? $insert['stores'] : $value['stores'],
                            ];
                            $app->insert("warehouses_logs", $warehouses_logs);
                            $sanpham = $app->get("products", ["id", "amount"], ["id" => $pro['products']]);
                            $app->update("products", ["amount" => $sanpham['amount'] + $value['amount']], ["id" => $sanpham['id']]);
                            $pro_logs[] = $pro;
                        } else {
                            $app->update("products", ["amount" => $getProducts['amount'] + str_replace([','], '', $value['amount'])], ["id" => $getProducts['id']]);
                            $pro = [
                                "warehouses" => $orderId,
                                "data" => $insert['data'],
                                "type" => $insert['type'],
                                "vendor" => $value['vendor'] ?? "",
                                "products" => $value['products'],
                                "amount_buy" => $value['amount_buy'],
                                "amount" => str_replace([','], '', $value['amount']),
                                "amount_total" => str_replace([','], '', $value['amount']),
                                "price" => $value['price'],
                                "notes" => $value['notes'],
                                "date" => date(format: "Y-m-d H:i:s"),
                                "user" => $app->getSession("accounts")['id'] ?? 0,
                                "stores" => (isset($value['status']) && $value['status'] == 0)
                                    ? ($insert['stores'] ?? null)
                                    : ($value['stores'] ?? null),

                                "branch" => (isset($value['status']) && $value['status'] == 0)
                                    ? ($insert['branch'] ?? null)
                                    : ($value['branch'] ?? null),
                            ];
                            $app->insert("warehouses_details", $pro);
                            $GetID = $app->id();
                            $warehouses_logs = [
                                "type" => $insert['type'],
                                "data" => $insert['data'],
                                "warehouses" => $orderId,
                                "details" => $GetID,
                                "products" => $value['products'],
                                "price" => $value['price'],
                                "amount" => str_replace([','], '', $value['amount']),
                                "total" => $value['amount'] * $value['price'],
                                "notes" => $value['notes'],
                                "date" => date('Y-m-d H:i:s'),
                                "user" => $app->getSession("accounts")['id'] ?? 0,
                                "stores" => (isset($value['status']) && $value['status'] == 0)
                                    ? $insert['stores']
                                    : ($value['stores'] ?? null),
                            ];
                            $app->insert("warehouses_logs", $warehouses_logs);
                            $pro_logs[] = $pro;
                        }
                    }
                    if ($action == 'move') {
                        if ($value['amount'] > 0) {
                            $app->update("products", ["amount" => $getProducts['amount'] - str_replace([','], '', $value['amount'])], ["id" => $getProducts['id']]);
                            $getAmounts = $app->select("warehouses_details", ["id", "amount_total", "amount_move", "price"], ["products" => $value['products'], "stores" => $insert['stores'], "type" => "import", "deleted" => 0, "amount_total[>]" => 0]);
                            $numWarehouse = 0;
                            $buyAmount = $value['amount'];
                            foreach ($getAmounts as $keyAmount => $getAmount) {
                                if ($keyAmount <= $numWarehouse) {
                                    $conlai = $getAmount['amount_total'] - $buyAmount;
                                    if ($buyAmount > $getAmount['amount_total']) {
                                        $getbuy = $getAmount['amount_total'];
                                    } else {
                                        $getbuy = $buyAmount;
                                    }
                                    $getwarehousess[$value['products']][] = [
                                        "id" => $getAmount['id'],
                                        "amount_move" => $getAmount['amount_move'],
                                        "amount_total" => $getAmount['amount_total'],
                                        "price" => $getAmount['price'],
                                        "duration" => $getAmount['duration'],
                                        "amount" => $getbuy,
                                    ];
                                    if ($conlai < 0) {
                                        $numWarehouse = $numWarehouse + 1;
                                        $buyAmount = -$conlai;
                                    }
                                }
                            }
                            foreach ($getwarehousess[$value['products']] as $getwarehouses) {
                                $update_ware = [
                                    "amount_move" => $getwarehouses['amount_move'] + $getwarehouses['amount'],
                                    "amount_total" => $getwarehouses['amount_total'] - $getwarehouses['amount'],
                                ];
                                $app->update("warehouses_details", $update_ware, ["id" => $getwarehouses['id']]);
                            }
                            $pro = [
                                "warehouses" => $orderId,
                                "data" => $insert['data'],
                                "type" => $insert['type'],
                                "vendor" => $value['vendor'],
                                "products" => $value['products'],
                                "amount_buy" => $value['amount_buy'],
                                "amount" => $value['amount'],
                                "amount_total" => $value['amount'],
                                "price" => $getProducts['price'],
                                "notes" => $value['notes'],
                                "date" => date("Y-m-d H:i:s"),
                                "user" => $app->getSession("accounts")['id'] ?? 0,
                                "stores" => $insert['stores'],
                                "branch" => $insert['branch'],
                            ];
                            $app->insert("warehouses_details", $pro);
                            $get_details = $app->id();
                            $warehouses_logs = [
                                "data" => $insert['data'],
                                "type" => $insert['type'],
                                "warehouses" => $orderId,
                                "details" => $get_details,
                                "products" => $value['products'],
                                "price" => $getProducts['price'],
                                "amount" => $value['amount'],
                                "total" => $value['amount'] * $getProducts['price'],
                                "notes" => $insert['notes'],
                                "date" => date('Y-m-d H:i:s'),
                                "user" => $app->getSession("accounts")['id'] ?? 0,
                                "stores" => $insert['stores'],
                                "stores_receive" => $insert['stores_receive'],
                                "branch" => $insert['branch'],
                                "branch_receive" => $insert['branch_receive'],
                            ];
                            $app->insert("warehouses_logs", $warehouses_logs);
                        }
                    }
                }
                if ($insert['data'] != '') {
                    $app->insert("purchase_logs", [
                        "purchase" => $insert['data'],
                        "user" => $app->getSession("accounts")['id'] ?? 0,
                        "content" => $app->xss('Nhập hàng'),
                        "status" => $app->xss(5),
                        "date" => date('Y-m-d H:i:s'),
                    ]);
                    $app->update("purchase", ["status" => 5], ["id" => $insert['data']]);
                }
                $amount = 0;
                if ($action == 'cancel') {
                    foreach ($datas['warehouses'] as $key => $value) {
                        if ($value['amount'] > 0) {
                            $getwarehouses = $app->get("warehouses_details", ["id", "amount_cancel", "amount_total", "vendor", "products", "price"], ["id" => $value['id'], "deleted" => 0, "amount_total[>]" => 0]);
                            $update = [
                                "amount_cancel" => $getwarehouses['amount_cancel'] + $value['amount'],
                                "amount_total" => $getwarehouses['amount_total'] - $value['amount'],
                            ];
                            $app->update("warehouses_details", $update, ["id" => $getwarehouses['id']]);
                            $total_amount = $value['amount_total'] - $value['amount'];
                            $pro = [
                                "warehouses" => $orderId,
                                "data" => $insert['data'],
                                "type" => $insert['type'],
                                "vendor" => $getwarehouses['vendor'],
                                "products" => $getwarehouses['products'],
                                "amount_buy" => $value['amount_buy'],
                                "amount" => $value['amount'],
                                "amount_total" => $value['amount'],
                                "price" => $getwarehouses['price'],
                                "notes" => $value['notes'],
                                "date" => date("Y-m-d H:i:s"),
                                "user" => $app->getSession("accounts")['id'] ?? 0,
                                "stores" => $insert['stores'],
                                "branch" => $insert['branch'],
                            ];
                            $app->insert("warehouses_details", $pro);
                            $warehouses_logs = [
                                "type" => $insert['type'],
                                "data" => $insert['data'],
                                "warehouses" => $orderId,
                                "details" => $getwarehouses['id'],
                                "products" => $getwarehouses['products'],
                                "amount" => $app->xss($value['amount']),
                                "price" => $value['price'],
                                "total" => $value['amount'] * $value['price'],
                                "date" => date('Y-m-d H:i:s'),
                                "user" => $app->getSession("accounts")['id'] ?? 0,
                                "stores" => $insert['stores'],
                                "branch" => $insert['branch'],
                            ];
                            $app->insert("warehouses_logs", $warehouses_logs);
                            $pro_logs[] = $pro;
                            $amount += $value['amount'];
                        }
                    }
                    $getProducts = $app->get("products", ["id", "amount"], ["id" => $datas['products']]);
                    $app->update("products", ["amount" => $getProducts['amount'] - $amount], ["id" => $getProducts['id']]);
                }
                $jatbi->logs('warehouses', $action, [$insert, $pro_logs, $_SESSION['warehouses'][$action]['products'], $_SESSION['warehouses'][$action]]);
                if ($insert['type'] == 'import') {
                    $jatbi->notification($app->getSession("accounts")['id'] ?? 0, '', 'products', 'Nhập hàng', 'Tài khoản ' . ($app->getSession("accounts")['name'] ?? '') . ' Đã nhập hàng phiếu #PN' . $orderId, '/warehouses/warehouses-history-views/' . $orderId . '/', 'modal-url');
                } elseif ($insert['type'] == 'move') {
                    $jatbi->notification($app->getSession("accounts")['id'] ?? 0, '', 'products', 'Xuất hàng', 'Tài khoản ' . ($app->getSession("accounts")['name'] ?? '') . ' Đã xuất hàng phiếu #PX' . $orderId, '/warehouses/warehouses-history-views/' . $orderId . '/', 'modal-url');
                }
                unset($_SESSION['warehouses'][$action]);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang("Cập nhật thành công")]);
            } else {
                echo json_encode(['status' => 'error', 'content' => $error['content']]);
            }
        }
    });




    $app->router('/products-import-crafting', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Nhập hàng từ chế tác");

        if ($app->method() === 'GET') {
            $vars['accounts'] = $app->select("accounts", ["id(value)", "name(text)"], ["deleted" => 0]);
            echo $app->render($template . '/warehouses/products-import-crafting.html', $vars);
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);

            $draw = intval($_POST['draw'] ?? 0);
            $start = intval($_POST['start'] ?? 0);
            $length = intval($_POST['length'] ?? 10);
            $searchValue = $_POST['search']['value'] ?? '';

            $where = [
                "AND" => [
                    "w.deleted" => 0,
                    "w.data" => 'pairing',
                    "w.type" => 'export',
                    "w.export_status" => 1,
                ]
            ];
            if (!empty($searchValue))
                $where['AND']['w.id[~]'] = $searchValue;

            $joins = ["[<]stores(s)" => ["stores" => "id"], "[<]branch(b)" => ["branch" => "id"], "[<]accounts(a)" => ["user" => "id"]];

            $count = $app->count("warehouses(w)", $joins, "w.id", $where);

            $where["ORDER"] = ['w.id' => 'DESC'];
            $where["LIMIT"] = [$start, $length];

            $columns = ["w.id", "w.code", "w.content", "w.date_poster", "s.name(store_name)", "b.name(branch_name)", "a.name(user_name)"];
            $datas = $app->select("warehouses(w)", $joins, $columns, $where);

            $resultData = [];
            foreach ($datas as $data) {
                $resultData[] = [
                    "code" => '<a data-action="modal" data-url="/crafting/pairing-export-views/' . $data['id'] . '/">#' . $data['code'] . $data['id'] . '</a>',
                    "store" => $data['store_name'],
                    "branch" => $data['branch_name'],
                    "content" => $data['content'],
                    "date" => $jatbi->datetime($data['date_poster']),
                    "user" => $data['user_name'],
                    "action" => '<a class="btn btn-primary btn-sm pjax-load" href="/warehouses/products-import/crafting/' . $data['id'] . '/">' . $jatbi->lang('Nhập hàng') . '</a>',
                    "views" => '<button data-action="modal" data-url="/admin/logs-views/' . $data['id'] . '" class="btn btn-eclo-light btn-sm border-0 py-1 px-2 rounded-3" aria-label="' . $jatbi->lang('Xem') . '"><i class="ti ti-eye"></i></button>',
                ];
            }

            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => $count,
                "recordsFiltered" => $count,
                "data" => $resultData
            ]);
        }
    })->setPermissions(['products.import']);


    $app->router('/ingredient-import', ['GET'], function ($vars) use ($app, $jatbi, $template) {

        $jatbi->permission('ingredient');
        $_SESSION['ingredient_import_context'] = 'default';

        $vars['title'] = $jatbi->lang("Nhập kho nguyên liệu");

        // Lấy danh sách nguyên liệu đã thêm vào phiếu từ session
        $vars['SelectIngredients'] = $_SESSION['ingredient_import']['ingredients'] ?? [];

        echo $app->render($template . '/warehouses/ingredient-import.html', $vars);
    })->setPermissions(['ingredient']);





    $app->router('/ingredient-import-crafting', ['GET', 'POST'], function ($vars) use ($app, $jatbi,$template ,$setting) {
        // ---- PHẦN XỬ LÝ CHO PHƯƠNG THỨC GET (HIỂN THỊ TRANG) ----
        if ($app->method() === 'GET') {
            $jatbi->permission('ingredient-import');
            $vars['title'] = $jatbi->lang("Danh sách nhập hàng");

            // Lấy danh sách tài khoản để đưa vào bộ lọc
            $accounts = $app->select("accounts", [
                "id(value)",
                "name(text)"
            ], [
                "deleted" => 0,
                "ORDER" => ["name" => "ASC"]
            ]);

            // Thêm tùy chọn "Tất cả" vào đầu danh sách
            array_unshift($accounts, [
                'value' => '',
                'text' => $jatbi->lang('Tất cả')
            ]);
            $vars['accounts'] = $accounts;

            // Render ra file view HTML
            echo $app->render($template . '/warehouses/ingredient-import-crafting.html', $vars);

            // ---- PHẦN XỬ LÝ CHO PHƯƠNG THỨC POST (CUNG CẤP DỮ LIỆU JSON CHO DATATABLES) ----
        } elseif ($app->method() === 'POST') {
            $jatbi->permission('ingredient-import');
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);

            // --- 1. Đọc các tham số từ DataTables gửi lên ---
            $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
            $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
            $length = isset($_POST['length']) ? intval($_POST['length']) : 10;
            $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
            $orderName = isset($_POST['order'][0]['column']) ? $_POST['columns'][$_POST['order'][0]['column']]['name'] : 'warehouses.id';
            $orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'DESC';

            // Lấy giá trị từ các bộ lọc tùy chỉnh
            $userFilter = isset($_POST['user']) ? $_POST['user'] : '';

            $dateFilter = isset($_POST['date']) ? $_POST['date'] : '';
            // --- 2. Xây dựng truy vấn với JOIN ---
            $joins = [
                "[>]accounts" => ["user" => "id"],
            ];

            // Xây dựng điều kiện WHERE cơ bản
            $where = [
                "AND" => [
                    "warehouses.deleted" => 0,
                    "warehouses.data" => 'crafting',
                    "warehouses.type" => 'export',
                    "warehouses.export_status" => 1,
                ],
            ];

            // Áp dụng bộ lọc tìm kiếm chung
            if (!empty($searchValue)) {
                $where['AND']['OR'] = [
                    'warehouses.code[~]' => $searchValue,
                    'warehouses.content[~]' => $searchValue,
                    'accounts.name[~]' => $searchValue,
                ];
            }

            // Áp dụng bộ lọc theo tài khoản
            if (!empty($userFilter)) {
                $where['AND']['warehouses.user'] = $userFilter;
            }

            if (!empty($dateFilter)) {
                $date_parts = explode(' - ', $dateFilter);
                if (count($date_parts) == 2) {
                    $date_from = date('Y-m-d 00:00:00', strtotime(str_replace('/', '-', trim($date_parts[0]))));
                    $date_to = date('Y-m-d 23:59:59', strtotime(str_replace('/', '-', trim($date_parts[1]))));
                    // Thêm điều kiện vào $where
                    $where['AND']['warehouses.date_poster[<>]'] = [$date_from, $date_to];
                }
            }

            // --- 3. Đếm tổng số bản ghi thỏa mãn điều kiện ---
            $recordsFiltered = $app->count("warehouses", $joins, "warehouses.id", $where);
            $recordsTotal = $app->count("warehouses", ["deleted" => 0, "data" => 'crafting', "type" => 'export', "export_status" => 1]);


            // Thêm điều kiện sắp xếp và phân trang
            $where['ORDER'] = [$orderName => strtoupper($orderDir)];
            $where['LIMIT'] = [$start, $length];

            // --- 4. Lấy dữ liệu chính ---
            $datasFormatted = [];
            $columns = [
                "warehouses.id",
                "warehouses.code",
                "warehouses.content",
                "warehouses.date_poster",
                "accounts.name(user_name)"
            ];

            $app->select("warehouses", $joins, $columns, $where, function ($data) use (&$datasFormatted, $jatbi, $app, $setting) {



                // Định dạng dữ liệu trả về cho mỗi hàng
                $datasFormatted[] = [
                    "code" => '<a class="fw-bold modal-url" data-url="/crafting/crafting-history-views' . $data['id'] . '">#' . $data['code'] . $data['id'] . '</a>',
                    "content" => $data['content'],
                    "date_poster" => date($setting['site_datetime'] ?? 'd/m/Y H:i:s', strtotime($data['date_poster'])),
                    "user" => $data['user_name'],
                    "action" => '<a class="btn btn-primary btn-sm pjax-load" href="/warehouses/ingredient-import/crafting/' . $data['id'] . '">' . $jatbi->lang('Nhập hàng') . '</a>',
                    "views" => '<button data-action="modal" data-url="/admin/logs-views/' . $data['id'] . '" class="btn btn-eclo-light btn-sm border-0 py-1 px-2 rounded-3" aria-label="' . $jatbi->lang('Xem') . '"><i class="ti ti-eye"></i></button>',

                ];
            });

            // --- 5. Trả về kết quả dưới dạng JSON cho DataTables ---
            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => $recordsTotal,
                "recordsFiltered" => $recordsFiltered,
                "data" => $datasFormatted,
            ]);
        }
    })->setPermissions(['ingredient-import']);

    // /**
    //  * Route mới: Nhập kho từ phiếu chế tác
    //  * URL: /ingredient-import-crafting/{id}
    //  * Nhiệm vụ: Lấy dữ liệu từ phiếu xuất kho chế tác và hiển thị trên template nhập kho chung.
    //  */
    // $app->router('/ingredient-import/crafting/{id}', ['GET'], function ($vars) use ($app, $jatbi, $setting) {
    //     $jatbi->permission('ingredient');

    //     $id = (int) ($vars['id'] ?? 0);


    //     // Xóa session cũ để bắt đầu phiếu mới từ dữ liệu chế tác
    //     unset($_SESSION['ingredient_import']);

    //     // Lấy thông tin phiếu xuất kho chế tác từ DB (logic từ code cũ)
    //     $data = $app->get("warehouses", ["id", "code"], ["data" => 'crafting', "type" => 'export', "id" => $id, "deleted" => 0]);

    //     if ($data) {
    //         $vars['title'] = $jatbi->lang("Nhập kho từ phiếu chế tác #" . $data['code'] . $data['id']);
    //         // Cờ này báo cho template biết đây là phiếu nhập từ chế tác
    //         $vars['is_crafting_import'] = true;

    //         // Khởi tạo và điền dữ liệu vào session
    //         $_SESSION['ingredient_import'] = [
    //             'ingredients' => [],
    //             'content' => "Nhập hàng từ phiếu xuất kho chế tác #" . $data['code'] . $data['id'],
    //             'crafting_id' => $data['id'], // Lưu ID phiếu gốc để xử lý khi hoàn tất
    //             'date' => date("Y-m-d H:i:s")
    //         ];

    //         // Lấy chi tiết các nguyên liệu từ phiếu xuất kho
    //         $details = $app->select("warehouses_details", ["ingredient", "amount", "price"], ["warehouses" => $data['id']]);

    //         foreach ($details as $value) {
    //             $getPro = $app->get("ingredient", ["id", "code", "name_ingredient", "units", "notes", "amount as stock_quantity"], ["id" => $value['ingredient']]);
    //             if ($getPro) {
    //                 $unit_name = $app->get("units", "name", ["id" => $getPro['units']]) ?? 'N/A';
    //                 $_SESSION['ingredient_import']['ingredients'][$getPro['id']] = [
    //                     "ingredient_id" => $getPro['id'],
    //                     "code" => $getPro['code'],
    //                     "name" => $getPro['name_ingredient'],
    //                     "selling_price" => $value['price'] ?? 0,
    //                     "stock_quantity" => $getPro['stock_quantity'] ?? 0,
    //                     "amount" => $value['amount'],
    //                     "price" => $value['price'] ?? 0,
    //                     "unit_name" => $unit_name,
    //                     "notes" => $getPro['notes'] ?? '',
    //                 ];
    //             }
    //         }
    //     } else {
    //         // Nếu không tìm thấy phiếu, hiển thị thông báo
    //         $vars['title'] = $jatbi->lang("Không tìm thấy phiếu");
    //         $vars['is_crafting_import'] = true; // Vẫn là context crafting
    //         $vars['error_message'] = "Không tìm thấy phiếu chế tác hợp lệ với ID này.";
    //     }

    //     // CUỐI CÙNG, RENDER RA CÙNG TEMPLATE VỚI ROUTE TRÊN
    //     echo $app->render($template . '/warehouses/ingredient-import-from-crafting.html', $vars);

    // })->setPermissions(['ingredient']);


    $app->router('/ingredient-import/crafting/{id}', ['GET'], function ($vars) use ($app, $jatbi, $template) {
        $jatbi->permission('ingredient');
        $id = (int) ($vars['id'] ?? 0);

        unset($_SESSION['ingredient_import_crafting']);

        $data = $app->get("warehouses", ["id", "code"], ["data" => 'crafting', "type" => 'export', "id" => $id, "deleted" => 0]);

        if ($data) {
            $vars['title'] = $jatbi->lang("Nhập kho từ phiếu chế tác #" . $data['code'] . $data['id']);

            $_SESSION['ingredient_import_crafting'] = [
                'ingredients' => [],
                'content' => "Nhập hàng từ phiếu xuất kho chế tác #" . $data['code'] . $data['id'],
                'crafting_id' => $data['id'],
                'date' => date("Y-m-d H:i:s")
            ];

            $details = $app->select("warehouses_details", ["ingredient", "amount", "price"], ["warehouses" => $data['id']]);

            foreach ($details as $value) {
                $getPro = $app->get("ingredient", ["id", "code", "name_ingredient", "units", "notes"], ["id" => $value['ingredient']]);
                if ($getPro) {
                    $unit_name = $app->get("units", "name", ["id" => $getPro['units']]) ?? 'N/A';


                    $stock_quantity = $app->sum("warehouses_details", "amount_total", [
                        "data" => "ingredient",
                        "type" => "import",
                        "deleted" => 0,
                        "ingredient" => $value['ingredient']
                    ]);

                    $_SESSION['ingredient_import_crafting']['ingredients'][$getPro['id']] = [
                        "ingredient_id" => $getPro['id'],
                        "code" => $getPro['code'],
                        "name" => $getPro['name_ingredient'],
                        "selling_price" => $value['price'] ?? 0,

                        "stock_quantity" => $stock_quantity,
                        "amount" => $value['amount'],
                        "price" => $value['price'] ?? 0,
                        "unit_name" => $unit_name,
                        "notes" => $getPro['notes'] ?? '',
                    ];
                }
            }
        } else {
            $vars['title'] = $jatbi->lang("Không tìm thấy phiếu");
        }

        echo $app->render($template . '/warehouses/ingredient-import-from-crafting.html', $vars);
    })->setPermissions(['ingredient']);


    $app->router('/ingredient-move', ['GET'], function ($vars) use ($app, $jatbi, $template, $accStore, $stores) {


        $jatbi->permission('ingredient');
        $vars['title'] = $jatbi->lang("Chuyển qua kho thành phẩm");

        // Khởi tạo session nếu chưa có
        if (!isset($_SESSION['ingredient_move'])) {
            $_SESSION['ingredient_move'] = [
                'ingredients' => [],
                'content' => '',
                'store_id' => null,
                'branch_id' => null,
                'date' => date("Y-m-d"),
            ];
        }

        if (count($stores) == 1) {
            $_SESSION['ingredient_move']['store_id'] = ["id" => $app->get("stores", "id", ["id" => $accStore, "status" => 'A', "deleted" => 0, "ORDER" => ["id" => "ASC"]])];
        }


        $vars['move_data'] = $_SESSION['ingredient_move'];


        // $store_options = [];
        // if (!empty($stores)) {
        //     foreach ($stores as $store) {
        //         if (isset($store['value']) && isset($store['text'])) {
        //             $store_options[] = ['value' => $store['value'], 'text' => $store['text']];
        //         }
        //     }
        // }
        // $vars['store_options'] = $store_options;

        // $branchs = $app->select("branch", ["id(value)", "name (text)", "code"], ["deleted" => 0, "stores" => $_SESSION['ingredient_move']['store_id'], "status" => 'A']);
        // $vars['branch_options'] = $branchs;



        $store_options = [['value' => '', 'text' => $jatbi->lang("Chọn cửa hàng")]];
        if (!empty($stores)) {
            foreach ($stores as $store) {
                if (isset($store['value']) && isset($store['text'])) {
                    $store_options[] = [
                        'value' => $store['value'],
                        'text' => $store['text']
                    ];
                }
            }
        }
        $vars['store_options'] = $store_options;

        // branch
        $branchs = $app->select("branch", ["id(value)", "name (text)", "code"], [
            "deleted" => 0,
            "stores" => $_SESSION['ingredient_move']['store_id'],
            "status" => 'A'
        ]);
        $branch_options = [['value' => '', 'text' => $jatbi->lang("Chọn quầy hàng")]];
        if (!empty($branchs)) {
            foreach ($branchs as $branch) {
                $branch_options[] = $branch;
            }
        }
        $vars['branch_options'] = $branch_options;

        echo $app->render($template . '/warehouses/ingredient-move.html', $vars);
    })->setPermissions(['ingredient']);






    $app->router('/ingredient-export', ['GET'], function ($vars) use ($app, $jatbi, $template) {
        $jatbi->permission('ingredient-export');

        // Khởi tạo session cho phiếu xuất kho nếu chưa có
        if (!isset($_SESSION['ingredient_export'])) {
            $_SESSION['ingredient_export'] = [
                'ingredients' => [],
                'content' => '',
                'group_crafting' => '',
                'date' => date("Y-m-d"),
            ];
        }

        $vars['title'] = $jatbi->lang("Xuất kho nguyên liệu");
        $vars['export_data'] = $_SESSION['ingredient_export'];

        // Render file HTML
        echo $app->render($template . '/warehouses/ingredient-export.html', $vars);
    })->setPermissions(['ingredient']);





    $app->router('/ingredient-cancel/{id}', ['GET'], function ($vars) use ($app, $jatbi, $template) {
        $jatbi->permission('ingredient');
        $ingredient_id = (int) ($vars['id'] ?? 0);

        $ingredient_data = $app->get("ingredient", ["id", "code", "name_ingredient", "units"], ["id" => $ingredient_id, "deleted" => 0]);
        // if (!$ingredient_data) {
        //     // Xử lý khi không tìm thấy nguyên liệu
        //     header("Location: /warehouses/ingredient"); // Chuyển hướng về trang danh sách
        //     exit();
        // }

        // Khởi tạo session riêng cho chức năng hủy hàng
        $session_key = 'ingredient_cancel';
        if (!isset($_SESSION[$session_key]) || $_SESSION[$session_key]['ingredient_id'] != $ingredient_id) {
            unset($_SESSION[$session_key]); // Xóa session cũ nếu hủy nguyên liệu khác

            $_SESSION[$session_key] = [
                'ingredient_id' => $ingredient_data['id'],
                'ingredient_code' => $ingredient_data['code'],
                'content' => 'Hủy hàng cho nguyên liệu #' . $ingredient_data['code'],
                'date' => date("Y-m-d"),
                'batches' => [], // Mảng chứa các lô hàng cần hủy
            ];

            // Lấy tất cả các lô nhập kho còn tồn của nguyên liệu này
            $warehouses = $app->select("warehouses_details", "*", [
                "data" => 'ingredient',
                "type" => 'import',
                "ingredient" => $ingredient_id,
                "deleted" => 0,
                "amount_total[>]" => 0,
                "ORDER" => ["date" => "ASC"]
            ]);

            foreach ($warehouses as $batch) {
                $_SESSION[$session_key]['batches'][$batch['id']] = [
                    "batch_id" => $batch['id'],
                    "vendor_id" => $batch['vendor'],
                    "amount_total" => $batch['amount_total'],
                    "amount_cancel" => 0, // Số lượng hủy ban đầu là 0
                    "price" => $batch['price'],
                    "units_id" => $ingredient_data['units'],
                    "import_date" => $batch['date'],
                ];
            }
        }

        $vars['title'] = $jatbi->lang("Hủy hàng: ") . $ingredient_data['name_ingredient'] . ' (#' . $ingredient_data['code'] . ')';
        $vars['cancel_data'] = $_SESSION[$session_key];
        $vars['id'] = (int) ($vars['id'] ?? 0);

        echo $app->render($template . '/warehouses/ingredient-cancel.html', $vars);
    })->setPermissions(['ingredient']);

    // $app->router('/ingredient-import/purchase/{id}', ['GET'], function ($vars) use ($app, $jatbi, $setting) {
    //     $jatbi->permission('ingredient');
    //     $id = (int) ($vars['id'] ?? 0);

    //     // Lấy thông tin phiếu mua hàng
    //     $getPur = $app->get("purchase", ["id", "code", "vendor"], ["id" => $id, "deleted" => 0, "status" => 3]);
    //     if (!$getPur) {
    //         die("Không tìm thấy phiếu mua hàng hợp lệ (ID: $id, Status: 3)");
    //     }

    //     // Khởi tạo session với tên và cấu trúc mới
    //     unset($_SESSION['ingredient_purchase_import']);
    //     $_SESSION['ingredient_purchase_import'] = [
    //         'ingredients' => [],
    //         'purchase_id' => $getPur['id'],
    //         'content'     => "Nhập hàng từ phiếu mua #" . $getPur['code'],
    //         'vendor'      => $getPur['vendor'],
    //         'date'        => date("Y-m-d")
    //     ];

    //     $getPros = $app->select("purchase_products", "*", ["purchase" => $getPur['id'], "deleted" => 0]);
    //     foreach ($getPros as $value) {
    //         $getPro = $app->get("ingredient", "*", ["id" => $value['ingredient']]);
    //         if ($getPro) {
    //             $unit_name = $app->get("units", "name", ["id" => $getPro['units']]) ?? 'N/A';
    //             $stock_quantity = $app->sum("warehouses_details", "amount_total", ["ingredient" => $getPro['id']]);

    //             // Sử dụng ID nguyên liệu làm khóa
    //             $_SESSION['ingredient_purchase_import']['ingredients'][$getPro['id']] = [
    //                 "ingredient_id"  => $getPro['id'],
    //                 "code"           => $getPro['code'],
    //                 "name"           => $getPro['name_ingredient'],
    //                 "stock_quantity" => $stock_quantity ?? 0,
    //                 "amount"         => $value['amount'],
    //                 "price"          => $value['price'],
    //                 "unit_name"      => $unit_name,
    //                 "notes"          => $value['notes'] ?? ''
    //             ];
    //         }
    //     }

    //     // Chuẩn bị biến và hiển thị template
    //     $vars['title'] = $jatbi->lang("Nhập kho từ phiếu mua hàng");
    //     $vars['SelectIngredients'] = $_SESSION['ingredient_purchase_import']['ingredients'] ?? [];

    //     echo $app->render($template . '/warehouses/ingredient-import-purchase.html', $vars);

    // })->setPermissions(['ingredient']);


    $app->router('/ingredient-import/purchase/{id}', ['GET'], function ($vars) use ($app, $jatbi, $template) {
        $vars['title'] = $jatbi->lang("Nhập hàng");
        $action = "import";
        $vars['action'] = $action;
        $router['2'] = "purchase";
        $router['3'] = (int) ($vars['id'] ?? 0);
        if (($_SESSION['ingredient'][$action]['import'] ?? null) != $router['2']) {
            unset($_SESSION['ingredient'][$action]);
        }
        if (($_SESSION['ingredient'][$action]['purchase'] ?? null) != $router['3']) {
            unset($_SESSION['ingredient'][$action]);
        }
        if (!isset($_SESSION['ingredient'][$action]['purchase'])) {
            $_SESSION['ingredient'][$action]['import'] = "purchase";
            $getPur = $app->get("purchase", ["id", "code", "vendor"], ["id" => $app->xss($router['3']), "deleted" => 0, "status" => 3]);
            $getPros = $app->select("purchase_products", ["id", "ingredient", "amount", "price", "vendor"], ["purchase" => $getPur['id'] ?? "", "deleted" => 0]);
            foreach ($getPros as $key => $value) {
                $getPro = $app->get("ingredient", ["id", "code", "categorys", "units", "notes"], ["id" => $value['ingredient']]);
                $_SESSION['ingredient'][$action]['ingredient'][] = [
                    "ingredient" => $value['ingredient'],
                    "amount" => str_replace([','], '', $value['amount']),
                    "price" => $value['price'],
                    "cost" => $value['cost'] ?? 0,
                    "vendor" => $getPro['vendor'] ?? 0,
                    "code" => $getPro['code'],
                    "categorys" => $getPro['categorys'],
                    "units" => $getPro['units'],
                    "notes" => $getPro['notes'],
                    "warehouses" => $app->sum("warehouses_details", "amount_total", ["data" => "ingredient", "type" => "import", "deleted" => 0, "ingredient" => $value['ingredient']]),
                ];
            }
            $_SESSION['ingredient'][$action]['purchase'] = $getPur['id'] ?? 0;
            $_SESSION['ingredient'][$action]['content'] = "nhập hàng từ phiếu mua #" . ($getPur['code'] ?? "");
            $_SESSION['ingredient'][$action]['vendor'] = $getPur['vendor'] ?? 0;
            if (!isset($_SESSION['ingredient'][$action]['date'])) {
                $_SESSION['ingredient'][$action]['date'] = date("Y-m-d");
            }
            if (!isset($_SESSION['ingredient'][$action]['type'])) {
                $_SESSION['ingredient'][$action]['type'] = $action;
            }
        }
        $_SESSION['ingredient'][$action]['stores']['id'] = 0;
        $data = [
            "type" => $_SESSION['ingredient'][$action]['type'],
            "date" => $_SESSION['ingredient'][$action]['date'],
            "content" => $_SESSION['ingredient'][$action]['content'],
            "stores" => $_SESSION['ingredient'][$action]['stores']['id'],
            "vendor" => $_SESSION['ingredient'][$action]['vendor'],
            "purchase" => $_SESSION['ingredient'][$action]['purchase'],
            "ingredient" => $_SESSION['ingredient'][$action]['ingredient'] ?? [],
        ];
        $SelectProducts = $_SESSION['ingredient'][$action]['ingredient'] ?? [];
        $vars['data'] = $data;
        $vars['SelectProducts'] = $SelectProducts;
        echo $app->render($template . '/warehouses/ingredient-import-purchase.html', $vars);
    })->setPermissions(['ingredient-import']);

    $app->router("/ingredient-update/import/ingredient/{req}/{id}", ['POST'], function ($vars) use ($app, $jatbi, $setting, $accStore, $stores) {
        $app->header(['Content-Type' => 'application/json']);
        $action = "import";
        $router['5'] = $vars['id'] ?? 0;
        $router['4'] = $vars['req'] ?? "";
        if ($router['4'] == 'amount') {
            $value = $app->xss(str_replace([','], '', $_POST['value']));
            if ($value < 0) {
                echo json_encode(['status' => 'error', 'content' => "Số lượng không được âm"]);
            } else {
                $getAmount = $app->get("ingredient", ["id", "amount"], ["id" => $_SESSION['ingredient'][$action]['ingredient'][$router['5']]['ingredient']]);
                $warehouses = $getAmount['amount'];
                if ($value > $warehouses && $action == 'export') {
                    $_SESSION['ingredient'][$action]['ingredient'][$router['5']]['amount'] = $warehouses;
                    echo json_encode(['status' => 'error', 'content' => "Số lượng không đủ, hiện tại chỉ còn " . $warehouses . ""]);
                } else {
                    $_SESSION['ingredient'][$action]['ingredient'][$router['5']]['amount'] = $value;
                    echo json_encode(['status' => 'success', 'content' => "Cập nhật thành công"]);
                }
            }
        } else if ($router['4'] == 'price') {
            $_SESSION['ingredient'][$action]['ingredient'][$router['5']][$router['4']] = $app->xss(str_replace([','], '', $_POST['value']));
            echo json_encode(['status' => 'success', 'content' => "Cập nhật thành công"]);
        } else if ($router['4'] == 'add') {
            $data = $app->get("ingredient", ["id", "price", "cost", "code", "units", "notes", "amount"], ["id" => $app->xss($_POST['value'])]);
            if ($data > 1) {
                $warehouses = $data['amount'];
                $error = '';
                if ($action == 'export') {
                    if ($warehouses <= 0) {
                        $error = "Số lượng không đủ";
                    }
                }
                if (!isset($error)) {
                    if (!in_array($data['id'], $_SESSION['ingredient'][$action]['ingredient'][$data['id']] ?? [])) {
                        $_SESSION['ingredient'][$action]['ingredient'][$data['id']] = [
                            "ingredient" => $data['id'],
                            "amount_buy" => 0,
                            "amount" => 1,
                            "price" => $data['price'],
                            "cost" => $data['cost'],
                            "code" => $data['code'],
                            "units" => $data['units'],
                            "notes" => $data['notes'],
                            "warehouses" => $warehouses > 0 ? $warehouses : 0,
                        ];
                        echo json_encode(['status' => 'success', 'content' => "Cập nhật thành công"]);
                    } else {
                        $getPro = $_SESSION['ingredient'][$action]['ingredient'][$data['id']];
                        $value = $getPro['amount'] + 1;
                        if ($value > $warehouses && $action == 'export') {
                            $_SESSION['ingredient'][$action]['ingredient'][$data['id']]['amount'] = $warehouses;
                            echo json_encode(['status' => 'success', 'content' => "Cập nhật thành công"]);
                        } else {
                            $_SESSION['ingredient'][$action]['ingredient'][$data['id']]['amount'] = $value;
                            echo json_encode(['status' => 'success', 'content' => "Cập nhật thành công"]);
                        }
                    }
                } else {
                    echo json_encode(['status' => 'error', 'content' => $error,]);
                }
            } else {
                echo json_encode(['status' => 'error', 'content' => "Cập nhật thất bại, không tìm thấy nguyên liệu",]);
            }
        } else if ($router['4'] == 'deleted') {
            unset($_SESSION['ingredient'][$action]['ingredient'][$router['5']]);
            echo json_encode(['status' => 'success', 'content' => "Xóa thành công"]);
        } else {
            $_SESSION['ingredient'][$action]['ingredient'][$router['5']][$router['4']] = $app->xss($_POST['value']);
            echo json_encode(['status' => 'success', 'content' => "Cập nhật thành công"]);
        }
    });

    $app->router("/ingredient-update/import/content", ['POST'], function ($vars) use ($app, $jatbi, $setting, $accStore, $stores) {
        $app->header(['Content-Type' => 'application/json']);
        $action = "import";
        $router['3'] = "content";
        $_SESSION['ingredient'][$action][$router['3']] = $app->xss($_POST['value']);
        echo json_encode(['status' => 'success', 'content' => "Cập nhật thành công"]);
    });

    $app->router("/ingredient-update/import/cancel", ['POST'], function ($vars) use ($app, $jatbi, $setting, $accStore, $stores) {
        $app->header(['Content-Type' => 'application/json']);
        $action = "import";
        unset($_SESSION['ingredient'][$action]);
        echo json_encode(['status' => 'success', 'content' => "Cập nhật thành công"]);
    });

    $app->router("/ingredient-update/import/completed", ['POST'], function ($vars) use ($app, $jatbi, $setting, $accStore, $stores) {
        $app->header(['Content-Type' => 'application/json']);
        $action = "import";
        $data = $_SESSION['ingredient'][$action];
        $error = [];
        $total_products = 0;
        $cancel_amount = 0;
        $error_warehouses = '';
        $AmountMinus = '';
        $amount = 0;
        $warehouses = $app->get("warehouses", ["id", "export_status"], ["id" => $data['crafting'] ?? "", "deleted" => 0]);
        foreach ($data['ingredient'] as $value) {
            $total_products += $value['amount'] * $value['price'];
            if ($value['amount'] == '' || $value['amount'] == 0 || $value['amount'] < 0) {
                $error_warehouses = 'true';
            }
        }
        if ($action == 'cancel') {
            foreach ($_SESSION['ingredient'][$action]['warehouses'] as $AmounCancel) {
                $cancel_amount += $AmounCancel['amount'];
                if ($AmounCancel['amount'] < 0) {
                    $AmountMinus = "true";
                }
            }
        } elseif (isset($warehouses['export_status']) && $warehouses['export_status'] == 2) {
            $error = ['status' => 'error', 'content' => "Phiếu này đã được nhập"];
        }
        if ($data['content'] == '' || count($data['ingredient']) == 0) {
            $error = ["status" => 'error', 'content' => $jatbi->lang("Vui lòng không để trống"),];
        } elseif ($error_warehouses == 'true') {
            $error = ['status' => 'error', 'content' => "Vui lòng nhập số lượng"];
        } elseif ($cancel_amount <= 0 && $action == 'cancel') {
            $error = ["status" => 'error', 'content' => "Vui lòng nhập số lượng huỷ"];
        } elseif ($AmountMinus == "true" && $action == 'cancel') {
            $error = ["status" => 'error', 'content' => "Số lượng không được âm"];
        }
        if (count($error) == 0) {
            if ($data['type'] == 'export' || $data['type'] == 'cancel') {
                $code = 'PX';
            }
            if ($data['type'] == 'import') {
                $code = 'PN';
            }
            $insert = [
                "code" => $code,
                "type" => $action,
                "data" => 'ingredient',
                "content" => $data['content'],
                "user" => $app->getSession("accounts")['id'] ?? 0,
                "date" => $data['date'],
                "active" => $jatbi->active(30),
                "date_poster" => date("Y-m-d H:i:s"),
                "purchase" => $data['purchase'],
                "crafting" => $data['crafting'] ?? 0,
                "move" => $data['move'] ?? 0,
                "group_crafting" => $data['group_crafting'] ?? 0,

            ];
            $app->insert("warehouses", $insert);
            $orderId = $app->id();
            if ($data['purchase'] != '') {
                $app->update("purchase", ["status" => 5], ["id" => $data['purchase']]);
                $app->insert("purchase_logs", [
                    "purchase" => $data['purchase'],
                    "user" => $app->getSession("accounts")['id'] ?? 0,
                    "content" => $app->xss('Nhập hàng'),
                    "status" => 5,
                    "date" => date('Y-m-d H:i:s'),
                ]);
            }
            if (!isset($data['move'])) {
                $app->update("warehouses", [
                    "receive_status" => 2,
                    "receive_date" => date("Y-m-d H:i:s"),
                ], ["id" => $data['move'] ?? NULL]);
            }
            foreach ($data['ingredient'] as $key => $value) {
                $getProducts = $app->get("ingredient", ["id", "amount"], ["id" => $value['ingredient']]);
                if ($action == 'import') {
                    if ($value['ingredient'] > 0) {
                        $app->update("ingredient", ["amount" => $getProducts['amount'] + $value['amount']], ["id" => $getProducts['id']]);
                        $pro = [
                            "warehouses" => $orderId,
                            "data" => $insert['data'],
                            "type" => $insert['type'],
                            "vendor" => $value['vendor'],
                            "ingredient" => $value['ingredient'],
                            "amount_buy" => $value['amount_buy'] ?? 0,
                            "amount" => str_replace([','], '', $value['amount']),
                            "amount_total" => str_replace([','], '', $value['amount']),
                            "price" => $value['price'],
                            "cost" => $value['cost'],
                            "notes" => $value['notes'],
                            "date" => date("Y-m-d H:i:s"),
                            "user" => $app->getSession("accounts")['id'] ?? 0,
                            "stores" => $insert['stores'] ?? 0,
                            "branch" => $insert['branch'] ?? 0,
                        ];
                        $app->insert("warehouses_details", $pro);
                        $GetID = $app->id();
                        $warehouses_logs = [
                            "type" => $insert['type'],
                            "data" => $insert['data'],
                            "warehouses" => $orderId,
                            "details" => $GetID,
                            "ingredient" => $value['ingredient'],
                            "price" => $value['price'],
                            "cost" => $value['cost'],
                            "amount" => str_replace([','], '', $value['amount']),
                            "total" => $value['amount'] * $value['price'],
                            "notes" => $value['notes'],
                            "duration" => $value['duration'] ?? '',
                            "date" => date('Y-m-d H:i:s'),
                            "user" => $app->getSession("accounts")['id'] ?? 0,
                            "stores" => $insert['stores'] ?? 0,
                        ];
                        $app->insert("warehouses_logs", $warehouses_logs);
                        $pro_logs[] = $pro;
                    }
                }
                if ($action == 'export') {
                    $getAmounts = $app->select("warehouses_details", ["id", "amount_total", "user", "cost", "price", "amount_used"], ["ingredient" => $value['ingredient'], "data" => 'ingredient', "type" => "import", "deleted" => 0, "amount_total[>]" => 0]);
                    $numWarehouse = 0;
                    $buyAmount = $value['amount'];
                    foreach ($getAmounts as $keyAmount => $getAmount) {
                        if ($keyAmount <= $numWarehouse) {
                            $conlai = $getAmount['amount_total'] - $buyAmount;
                            if ($buyAmount > $getAmount['amount_total']) {
                                $getbuy = $getAmount['amount_total'];
                            } else {
                                $getbuy = $buyAmount;
                            }
                            $getwarehousess[$value['ingredient']][] = [
                                "id" => $getAmount['id'],
                                "amount_used" => $getAmount['amount_used'],
                                "amount_total" => $getAmount['amount_total'],
                                "price" => $getAmount['price'],
                                "cost" => $getAmount['cost'],
                                "buy" => $getbuy,
                            ];
                            if ($conlai < 0) {
                                $numWarehouse = $numWarehouse + 1;
                                $buyAmount = -$conlai;
                            }
                        }
                    }
                    foreach ($getwarehousess[$value['ingredient']] as $getwarehouses) {
                        $update_ware = [
                            "amount_used" => $getwarehouses['amount_used'] + $getwarehouses['buy'],
                            "amount_total" => $getwarehouses['amount_total'] - $getwarehouses['buy'],
                        ];
                        $app->update("warehouses_details", $update_ware, ["id" => $getwarehouses['id']]);
                    }
                    // 		$cost = $total_cost/count($getwarehousess[$value['ingredient']]);
                    $pro = [
                        "warehouses" => $orderId,
                        "data" => $insert['data'],
                        "type" => $insert['type'],
                        "ingredient" => $value['ingredient'],
                        "amount_old" => $value['warehouses'],
                        "amount" => str_replace([','], '', $value['amount']),
                        "amount_total" => str_replace([','], '', $value['amount']),
                        "price" => $value['price'],
                        "cost" => $value['cost'],
                        "notes" => $value['notes'],
                        "date" => date("Y-m-d H:i:s"),
                        "user" => $app->getSession("accounts")['id'] ?? 0,
                        "group_crafting" => $data['group_crafting'],
                    ];
                    $app->insert("warehouses_details", $pro);
                    $da = $app->id();
                    $warehouses_logs = [
                        "type" => $insert['type'],
                        "data" => $insert['data'],
                        "warehouses" => $orderId,
                        "details" => $da,
                        "ingredient" => $value['ingredient'],
                        "price" => $value['price'],
                        "cost" => $value['cost'],
                        "amount" => str_replace([','], '', $value['amount']),
                        "total" => str_replace([','], '', $value['amount']) * $value['price'],
                        "notes" => $insert['notes'],
                        "date" => date('Y-m-d H:i:s'),
                        "user" => $app->getSession("accounts")['id'] ?? 0,
                        "group_crafting" => $data['group_crafting'],
                    ];
                    $app->insert("warehouses_logs", $warehouses_logs);
                    $getProducts = $app->get("ingredient", ["id", "amount"], ["id" => $value['ingredient']]);
                    $app->update("ingredient", [
                        "amount" => $getProducts['amount'] - $value['amount'],
                    ], ["id" => $getProducts['id']]);
                    $pro_logs[] = $pro;
                }
            }
            if ($action == 'cancel') {
                $getProducts = $app->get("ingredient", ["id", "amount"], ["id" => $data['ingredient']]);
                foreach ($_SESSION['ingredient'][$action]['warehouses'] as $key => $value) {
                    if ($value['amount'] > 0) {
                        $getwarehouses = $app->get("warehouses_details", "*", ["id" => $value['id'], "deleted" => 0, "amount_total[>]" => 0, "type" => "import", "data" => 'ingredient']);
                        $update = [
                            "amount_cancel" => $getwarehouses['amount_cancel'] + $value['amount'],
                            "amount_total" => $getwarehouses['amount_total'] - $value['amount'],
                        ];
                        $app->update("warehouses_details", $update, ["id" => $getwarehouses['id']]);
                        $total_amount = $value['amount_total'] - $value['amount'];
                        $pro = [
                            "warehouses" => $orderId,
                            "data" => $insert['data'],
                            "type" => $insert['type'],
                            "ingredient" => $value['ingredient'],
                            "amount_old" => $value['amount_total'],
                            "amount" => str_replace([','], '', $value['amount']),
                            "amount_total" => $total_amount,
                            "price" => $value['price'],
                            "cost" => $value['cost'],
                            "notes" => $value['notes'],
                            "date" => date("Y-m-d H:i:s"),
                            "user" => $app->getSession("accounts")['id'] ?? 0,
                        ];
                        $app->insert("warehouses_details", $pro);
                        $warehouses_logs = [
                            "type" => $insert['type'],
                            "data" => $insert['data'],
                            "warehouses" => $orderId,
                            "details" => $getwarehouses['id'],
                            "ingredient" => $value['ingredient'],
                            "amount" => str_replace([','], '', $value['amount']),
                            "price" => $value['price'],
                            "cost" => $value['cost'],
                            "total" => $value['amount'] * $value['price'],
                            "date" => date('Y-m-d H:i:s'),
                            "user" => $app->getSession("accounts")['id'] ?? 0,
                        ];
                        $app->insert("warehouses_logs", $warehouses_logs);
                        $pro_logs[] = $pro;
                        $amount += $value['amount'];
                    }
                }
                $app->update("ingredient", ["amount" => $getProducts['amount'] - $amount], ["id" => $getProducts['id']]);
            }
            $jatbi->logs('warehouses', $action, [$insert, $pro_logs, $data]);
            $app->update("warehouses", ["export_status" => 2, "export_date" => date("Y-m-d H:i:s")], ["id" => $data['crafting'] ?? ""]);
            unset($_SESSION['ingredient'][$action]);
            echo json_encode(['status' => 'success', 'content' => "Cập nhật thành công",]);
        } else {
            echo json_encode(['status' => 'error', 'content' => $error['content']]);
        }
    });

    $app->router('/products-add/{dispatch}/{action}', ['GET', 'POST'], function ($vars) use ($app, $jatbi,$template, $setting, $stores) {
        $vars['title'] = $jatbi->lang("Thêm Sản phẩm");

        if ($app->method() === 'GET') {
            $vars['data'] = ['status' => 'A', 'vat_type' => 1, 'amount_status' => 1];
            $vars['groups'] = $app->select("products_group", ["id(value)", "name(text)"], ["deleted" => 0]);
            $vars['categorys'] = $app->select("categorys", ["id(value)", "name(text)"], ["deleted" => 0]);
            $vars['units'] = $app->select("units", ["id(value)", "name(text)"], ["deleted" => 0]);
            $vars['default_codes'] = $app->select("default_code", ["id(value)", "name(text)"], ["deleted" => 0]);
            $accountants_data = $app->select("accountants_code", ["id", "name", "code"], ["deleted" => 0]);

            $formatted_accountants = [
                ['value' => '', 'text' => $jatbi->lang('Tài khoản')]
            ];

            foreach ($accountants_data as $item) {
                $formatted_accountants[] = [
                    'value' => $item['id'],
                    'text' => $item['code'] . ' - ' . $item['name']
                ];
            }
            array_unshift($stores, [
                'value' => '',
                'text' => $jatbi->lang('Tất cả')
            ]);
            $vars['stores'] = $stores;

            // Gán mảng đã có lựa chọn mặc định cho view
            $vars['accountants'] = $formatted_accountants;

            echo $app->render($template . '/warehouses/products-post.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json']);
            $post = array_map([$app, 'xss'], $_POST);
            $error = [];

            $required_fields = ['code', 'name', 'categorys', 'units', 'amount_status', 'stores', 'branch'];
            foreach ($required_fields as $field) {
                if (empty($post[$field])) {
                    $error = ['status' => 'error', 'content' => $jatbi->lang("Vui lòng điền đủ các trường bắt buộc")];
                    break;
                }
            }

            if (empty($error)) {
                $store_code = $app->get("stores", "code", ["id" => $post['stores']]);
                $branch_code = $app->get("branch", "code", ["id" => $post['branch']]);
                $full_code = $store_code . $branch_code . $post['code'];
                if ($app->has("products", ["code" => $full_code, "deleted" => 0])) {
                    $error = ['status' => 'error', 'content' => $jatbi->lang('Mã sản phẩm đã tồn tại')];
                }
            }

            if (empty($error)) {
                // --- BẮT ĐẦU TÍCH HỢP LOGIC XỬ LÝ ẢNH ---
                $image_name = 'no-images.jpg';
                $active_hash = $jatbi->active(32); // Mã active này sẽ được dùng cho cả sản phẩm và thư mục ảnh
                $upload_info_for_db = null;

                if (isset($_FILES['images']) && $_FILES['images']['error'] === UPLOAD_ERR_OK) {
                    $handle = $app->upload($_FILES['images']);
                    if ($handle->uploaded) {
                        $path_upload = $setting['uploads'] . '/products/' . $active_hash . '/';
                        $path_upload_thumb = $path_upload . 'thumb/';
                        if (!is_dir($path_upload_thumb)) {
                            mkdir($path_upload_thumb, 0755, true);
                        }
                        $new_image_name_body = $jatbi->active(16);

                        // Xử lý ảnh gốc
                        $handle->file_new_name_body = $new_image_name_body;
                        $handle->process($path_upload);

                        // Xử lý ảnh thumb
                        if ($handle->processed) {
                            $handle->image_resize = true;
                            $handle->image_ratio_crop = true;
                            $handle->image_y = $setting['upload']['images']['products']['thumb_y'] ?? 300;
                            $handle->image_x = $setting['upload']['images']['products']['thumb_x'] ?? 300;
                            $handle->file_new_name_body = $new_image_name_body;
                            $handle->process($path_upload_thumb);

                            if ($handle->processed) {
                                $image_name = $handle->file_dst_name;
                                $upload_info_for_db = [
                                    "type" => "products",
                                    "content" => 'products/' . $active_hash . '/' . $image_name,
                                    "date" => date("Y-m-d H:i:s"),
                                    "active" => $active_hash,
                                    "size" => $handle->file_src_size,
                                    "data" => json_encode(['...'])
                                ];
                            }
                        }
                    }
                }
                $insert = [
                    "type" => 1,
                    "main" => $post['main'] ?? 0,
                    "code" => $full_code,
                    "name" => $post['name'],
                    "categorys" => $post['categorys'],
                    "vat" => $post['vat'] ?? 0,
                    "vat_type" => $post['vat_type'],
                    "vendor" => $post['vendor'] ?? null,
                    "amount_status" => $post['amount_status'],
                    "group" => $post['group'],
                    "price" => str_replace(',', '', $post['price'] ?? 0),
                    "units" => $post['units'],
                    "notes" => $post['notes'],
                    "active" => $jatbi->active(32),
                    "images" => $image_name,
                    "date" => date('Y-m-d H:i:s'),
                    "status" => $post['status'] ?? 'A',
                    "user" => $app->getSession("accounts")['id'] ?? 0,
                    "new" => 1,
                    "stores" => $post['stores'],
                    "branch" => $post['branch'],
                    "code_products" => $post['code'],
                    "tkno" => $post['tkno'] ?? '',
                    "tkco" => $post['tkco'] ?? '',
                    "default_code" => $post['default_code'],
                ];
                $app->insert("products", $insert);
                $getID = $app->id();
                if ($upload_info_for_db) {
                    $upload_info_for_db['parent'] = $getID;
                    $app->insert("uploads", $upload_info_for_db);
                }
                $dispatch = $vars['dispatch'];
                $action = $vars['action'];
                if ($insert['vat_type'] == 1) {
                    $vat_price = $insert['price'] - ($insert['price'] / (1 + ($insert['vat'] / 100)));
                    $price = $insert['price'];
                    $price_vat = $insert['price'] - $vat_price;
                }
                if ($insert['vat_type'] == 2) {
                    $vat_price = $insert['price'] * ($insert['vat'] / 100);
                    $price = $insert['price'] + $vat_price;
                    $price_vat = $insert['price'];
                }
                $_SESSION[$dispatch][$action]['products'][$getID] = [
                    "products" => $getID,
                    "amount" => 1,
                    "price" => $price,
                    "price_vat" => $price_vat,
                    "vendor" => $insert['vendor'],
                    "vat" => $insert['vat'],
                    "vat_type" => $insert['vat_type'],
                    "vat_price" => $vat_price,
                    "price_old" => $price,
                    "images" => $insert['images'],
                    "code" => $insert['code'],
                    "name" => $insert['name'],
                    "categorys" => $insert['categorys'],
                    "amount_status" => $insert['amount_status'],
                    "units" => $insert['units'],
                    "notes" => $insert['notes'],
                    "warehouses" => (isset($warehouses) && $warehouses > 0 ? $warehouses : 0),
                ];
                $_SESSION[$dispatch][$action]['vat'][$insert['vat']][$getID] = $vat_price;
                $jatbi->logs('products', 'add', $insert);
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Thêm mới thành công'), 'url' => 'auto']);
            } else {
                echo json_encode($error);
            }
        }
    })->setPermissions(['products.add']);




    $app->router('/products-excel', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {
        // Kiểm tra quyền truy cập ở đầu, áp dụng cho cả GET và POST
        $jatbi->permission('products.add');

        // --- XỬ LÝ KHI LÀ PHƯƠNG THỨC GET ---
        if ($app->method() === 'GET') {
            $vars['title'] = $jatbi->lang('Nhập sản phẩm từ Excel');
            // Render file template của form và trả về dưới dạng HTML
            echo $app->render($template . '/warehouses/products-excel.html', $vars, $jatbi->ajax());

            // --- XỬ LÝ KHI LÀ PHƯƠNG THỨC POST ---
            // --- XỬ LÝ KHI LÀ PHƯƠNG THỨC POST ---
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);

            // 1. XỬ LÝ UPLOAD FILE
            if (!isset($_FILES['files']) || $_FILES['files']['error'] != UPLOAD_ERR_OK) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Vui lòng chọn file')]);
                return;
            }

            $handle = $app->upload($_FILES['files']);
            if ($handle->uploaded) {
                $handle->allowed = array('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel');
                $handle->process('datas/');
            }

            if (!$handle->processed) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Lỗi upload') . ': ' . $handle->error]);
                return;
            }

            // 2. ĐỌC DỮ LIệu TỪ FILE EXCEL
            $inputFileName = 'datas/' . $handle->file_dst_name;
            try {
                $spreadsheet = IOFactory::load($inputFileName);
                $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
            } catch (\Exception $e) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Lỗi đọc file Excel') . ': ' . $e->getMessage()]);
                return;
            } finally {
                @unlink($inputFileName); // Xóa file tạm sau khi đọc xong
            }

            $success_count = 0;
            $error_count = 0;
            $errors = [];
            $user_id = $app->getSession("accounts")['id'] ?? 0;

            // 3. LẶP QUA TỪNG DÒNG VÀ ÁP DỤNG LOGIC
            foreach ($sheetData as $key => $value) {
                if ($key <= 1)
                    continue; // Bỏ qua dòng tiêu đề

                $current_row = $key;
                $row_data = array_map([$app, 'xss'], $value);

                // A. LẤY ID TỪ TÊN/MÃ (SỬA LẠI ĐÚNG CỘT)
                $unit_id = $app->get("units", "id", ["name" => trim($row_data['D']), "deleted" => 0]);
                $store_id = $app->get("stores", "id", ["name" => trim($row_data['F']), "deleted" => 0]); // SỬA: Cột F cho Cửa hàng
                $branch_id = $app->get("branch", "id", ["name" => trim($row_data['G']), "stores" => $store_id, "deleted" => 0]); // SỬA: Cột G cho Chi nhánh
                $default_code_id = !empty($row_data['H']) ? $app->get("default_code", "id", ["code" => trim($row_data['H']), "deleted" => 0]) : 0; // SỬA: Lấy mã mặc định từ cột H

                // B. VALIDATION (SỬA LẠI ĐÚNG TRƯỜNG BẮT BUỘC)
                $required_check = [
                    'Mã sản phẩm' => $row_data['A'],
                    'Tên sản phẩm' => $row_data['B'],
                    'Đơn vị' => $unit_id,
                    'Cửa hàng' => $store_id,
                    'Chi nhánh' => $branch_id
                ];

                $has_error = false;
                foreach ($required_check as $field_name => $field_value) {
                    if (empty($field_value)) {
                        $errors[] = $jatbi->lang("Dòng $current_row: Thiếu thông tin hoặc tên/mã cho '$field_name' không hợp lệ.");
                        $has_error = true;
                        break;
                    }
                }
                if ($has_error) {
                    $error_count++;
                    continue;
                }

                // C. KIỂM TRA SẢN PHẨM TRÙNG LẶP (SỬA LẠI THEO LOGIC CŨ)
                $product_code = $row_data['A'];
                $conditions = [
                    "code_products" => $product_code,
                    "stores" => $store_id,
                    "branch" => $branch_id,
                    "deleted" => 0
                ];

                if ($app->has("products", $conditions)) {
                    $errors[] = $jatbi->lang("Dòng $current_row: Mã sản phẩm '$product_code' đã tồn tại tại chi nhánh này.");
                    $error_count++;
                    continue;
                }

                // D. CHUẨN BỊ MẢNG INSERT (SỬA LẠI THEO CÁC TRƯỜNG CỦA CODE CŨ)
                $insert_data = [
                    "type" => 1,
                    "main" => 0,
                    "code" => $product_code, // SỬA: Dùng mã gốc
                    "name" => $row_data['B'],
                    "categorys" => 1, // SỬA: Hardcode là 1 giống code cũ
                    "amount_status" => 1,
                    "price" => str_replace(',', '', $row_data['E'] ?? 0), // SỬA: Giá ở cột E
                    "units" => $unit_id,
                    "notes" => 'nhap hang tu excel',
                    "active" => $jatbi->active(32),
                    "images" => 'no-images.jpg',
                    "date" => date('Y-m-d H:i:s'),
                    "vat_type" => 1,
                    "vat" => 8,
                    "status" => "A",
                    "user" => $user_id,
                    "new" => 1,
                    "stores" => $store_id,
                    "amount" => 0,
                    "branch" => $branch_id,
                    "default_code" => $default_code_id,
                    "code_products" => $product_code,
                ];

                // E. THỰC THI INSERT VÀ GHI LOG
                if ($app->insert("products", $insert_data)) {
                    $success_count++;
                    $jatbi->logs('products', 'add', $insert_data);
                } else {
                    $errors[] = $jatbi->lang("Dòng $current_row: Lỗi không xác định khi lưu vào database.");
                    $error_count++;
                }
            }

            if ($error_count == 0) {
                // Nếu KHÔNG có lỗi nào
                $status = 'success';
                $content = $jatbi->lang("Cập nhật thành công! Đã thêm mới $success_count sản phẩm.");
            } else {
                // Nếu CÓ lỗi
                $status = 'error';
                $content = $jatbi->lang("Cập nhật thất bại! Vui lòng kiểm tra các lỗi chi tiết.");
            }

            // Gửi về phản hồi JSON
            echo json_encode([
                'status' => $status,
                'content' => $content,
                'errors' => $errors
            ]);
        }
    })->setPermissions(['products.add']);

    $app->router('/ingredient-excel', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {

        if ($app->method() === 'GET') {
            $vars['title'] = $jatbi->lang('Nhập nguyên liệu từ Excel');
            echo $app->render($template . '/warehouses/ingredient-excel.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);

            if (!isset($_FILES['files']) || $_FILES['files']['error'] != UPLOAD_ERR_OK) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Vui lòng chọn file')]);
                return;
            }
            $handle = $app->upload($_FILES['files']);
            if ($handle->uploaded) {
                $handle->allowed = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'];
                $handle->process('datas/');
            }
            if (!$handle->processed) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Lỗi upload') . ': ' . $handle->error]);
                return;
            }
            $inputFileName = 'datas/' . $handle->file_dst_name;
            try {
                $spreadsheet = IOFactory::load($inputFileName);
                $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
            } catch (\Exception $e) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Lỗi đọc file Excel') . ': ' . $e->getMessage()]);
                return;
            } finally {
                @unlink($inputFileName);
            }


            $colors_map = array_column($app->select("colors", ["id", "code"], ["deleted" => 0]), 'id', 'code');
            $groups_map = array_column($app->select("products_group", ["id", "code"], ["deleted" => 0]), 'id', 'code');
            $categorys_map = array_column($app->select("categorys", ["id", "code"], ["deleted" => 0]), 'id', 'code');
            $sizes_map = array_column($app->select("sizes", ["id", "code"], ["deleted" => 0]), 'id', 'code');
            $pearls_map = array_column($app->select("pearl", ["id", "code"], ["deleted" => 0]), 'id', 'code');
            $units_map = array_column($app->select("units", ["id", "name"], ["deleted" => 0]), 'id', 'name');


            $valid_rows_to_insert = [];
            $updated_rows_count = 0;
            $errors = [];
            $user_id = $app->getSession("accounts")['id'] ?? 0;

            foreach ($sheetData as $key => $value) {
                if ($key <= 1 || empty($value['A']))
                    continue;

                $current_row = $key;
                $row_data = array_map(fn($cell) => trim($cell ?? ''), $value);
                $ingredient = $app->get("ingredient", ["id", "code"], ["code" => $row_data['A'], "deleted" => 0]);

                $color_id = $colors_map[$row_data['C']] ?? null;
                $group_id = $groups_map[$row_data['D']] ?? null;
                $category_id = $categorys_map[$row_data['E']] ?? null;
                $pearl_id = $pearls_map[$row_data['F']] ?? null;
                $size_id = $sizes_map[$row_data['G']] ?? null;
                $unit_id = $units_map[$row_data['J']] ?? null;
                $type_id = 0;
                if ($row_data['L'] == 'Đai') {
                    $type_id = 1;
                } elseif ($row_data['L'] == 'Ngọc') {
                    $type_id = 2;
                } elseif ($row_data['L'] == 'Khác') {
                    $type_id = 3;
                } else {
                    $errors[] = "Dòng $current_row: Phân Loại '{$row_data['L']}' không hợp lệ.";
                    continue;
                }

                $data_payload = [
                    "type" => $type_id,
                    "number" => $app->xss($row_data['B']),
                    "colors" => $color_id,
                    "group" => $group_id,
                    "categorys" => $category_id,
                    "sizes" => $size_id,
                    "pearl" => $pearl_id,
                    "quality" => $app->xss($row_data['H']),
                    "units" => $unit_id,
                    "price" => str_replace(',', '', $row_data['K']) ?: 0,
                    "notes" => 'Nhập từ excel',
                    "user" => $user_id,
                    "date" => date("Y-m-d H:i:s"),
                    "status" => "A",
                ];

                if ($ingredient) {
                    if ($app->update("ingredient", $data_payload, ["id" => $ingredient["id"]])) {
                        $updated_rows_count++;
                    }
                } else {
                    $data_payload['code'] = $app->xss($row_data['A']);
                    $valid_rows_to_insert[] = $data_payload;
                }
            }


            if (empty($errors) && !empty($valid_rows_to_insert)) {


                $transaction_result = $app->action(function () use ($app, $jatbi, $valid_rows_to_insert) {
                    foreach ($valid_rows_to_insert as $insert_data) {
                        $result = $app->insert("ingredient", $insert_data);
                        if (!$result) {

                            return false;
                        }
                        $jatbi->logs('ingredient', 'add', $insert_data);
                    }
                    return true;
                });

                if ($transaction_result === false) {
                    $errors[] = "Đã xảy ra lỗi database trong quá trình import. Toàn bộ dữ liệu đã được hủy bỏ.";
                }
            }

            if (empty($errors)) {
                $new_rows_count = count($valid_rows_to_insert);

                $total_success_count = $new_rows_count + $updated_rows_count;


                $content = "Cập nhật thành công! Đã xử lý $total_success_count nguyên liệu.";
                echo json_encode(['status' => 'success', 'content' => $jatbi->lang($content), 'errors' => []]);
            } else {

                $content = "Cập nhật thất bại! Vui lòng kiểm tra các lỗi chi tiết.";
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang($content), 'errors' => $errors]);
            }
        }
    })->setPermissions(['ingredient.add']);

    $app->router('/warehouse-ingredient-excel', ['GET', 'POST'], function ($vars) use ($app, $jatbi, $template) {

        if ($app->method() === 'GET') {
            $vars['title'] = $jatbi->lang('Nhập kho nguyên liệu từ Excel');
            echo $app->render($template . '/warehouses/warehouse-ingredient-excel.html', $vars, $jatbi->ajax());
        } elseif ($app->method() === 'POST') {
            $app->header(['Content-Type' => 'application/json; charset=utf-8']);

            if (!isset($_FILES['files']) || $_FILES['files']['error'] != UPLOAD_ERR_OK) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Vui lòng chọn file')]);
                return;
            }
            $handle = $app->upload($_FILES['files']);
            if ($handle->uploaded) {
                $handle->allowed = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'];
                $handle->process('datas/');
            }
            if (!$handle->processed) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Lỗi upload') . ': ' . $handle->error]);
                return;
            }
            $inputFileName = 'datas/' . $handle->file_dst_name;
            try {
                $spreadsheet = IOFactory::load($inputFileName);
                $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
            } catch (\Exception $e) {
                echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Lỗi đọc file Excel') . ': ' . $e->getMessage()]);
                return;
            } finally {
                @unlink($inputFileName);
            }

            $errors = [];
            $valid_ingredients_session = [];
            $success_count = 0;

            unset($_SESSION['ingredient_import']['ingredients']);

            foreach ($sheetData as $key => $value) {
                if ($key <= 1 || empty($value['A'])) continue;

                $current_row = $key;
                $row_data = array_map(fn($cell) => trim($cell ?? ''), $value);
                $ingredient_data = $app->get("ingredient", "*", ["code" => $app->xss($row_data['A']), "deleted" => 0]);

                if (!$ingredient_data) {

                    $errors[] = "Dòng $current_row: Không tìm thấy nguyên liệu có mã '{$row_data['A']}'.";
                    continue;
                }

                $amount = str_replace(',', '', $row_data['I'] ?? '');
                $price = str_replace(',', '', $row_data['K'] ?? '');

                /*
            if (!is_numeric($amount) || !is_numeric($price)) {
                $errors[] = "Dòng $current_row: Số lượng (Cột I) hoặc Đơn giá (Cột K) không phải là số.";
                continue;
            }
            */

                $unit_name = $app->get('units', 'name', ['id' => $ingredient_data['units']]);

                $valid_ingredients_session[$ingredient_data['id']] = [
                    "ingredient_id"        => $ingredient_data['id'],
                    "code"              => $ingredient_data['code'],
                    "name"              => $ingredient_data['name'] ?? '',
                    "selling_price"     => $price,
                    "stock_quantity"    => $ingredient_data['amount'],
                    "amount"            => $amount,
                    "unit_name"         => $unit_name,
                    "notes"             => $ingredient_data['notes'],
                ];
                $success_count++;
            }

            if (empty($errors)) {
                $_SESSION['ingredient_import']['ingredients'] = $valid_ingredients_session;
                $content = "Đã thêm thành công $success_count nguyên liệu vào phiếu! ";
                echo json_encode([
                    'status' => 'success',
                    'content' => $jatbi->lang($content),
                    'url' => $_SERVER['HTTP_REFERER']
                ]);
            } else {
                $content = "Thêm thất bại! Vui lòng kiểm tra các lỗi chi tiết.";
                echo json_encode([
                    'status' => 'error',
                    'content' => $jatbi->lang($content),
                    'errors' => $errors
                ]);
            }
        }
    })->setPermissions(['ingredient.add']);
})->middleware('login');
