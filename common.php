<?php
if (!defined('ECLO')) die("Hacking attempt");
return [
    "proposal" => [
        "1" => [
            "id" => 1,
            "name" => $jatbi->lang("Thu"),
            "color" => 'success',
        ],
        "2" => [
            "id" => 2,
            "name" => $jatbi->lang("Chi"),
            "color" => 'danger',
        ],
        "3" => [
            "id" => 3,
            "name" => $jatbi->lang("Nghiệp vụ khác"),
            "color" => 'primary',
        ],
    ],
    "proposal-form" => [
        "1" => [
            "id" => 1,
            "name" => $jatbi->lang("Tiền mặt"),
            "color" => 'text-light bg-success',
        ],
        "2" => [
            "id" => 2,
            "name" => $jatbi->lang("Ngân hàng"),
            "color" => 'text-dark bg-danger',
        ],
        "3" => [
            "id" => 3,
            "name" => $jatbi->lang("Nghiệp vụ khác"),
            "color" => 'text-light bg-primary',
        ],
    ],
    "proposal-status" => [
        "0"=>[
            "name"=>$jatbi->lang('Nháp'),
            "color"=>'body text-body',
            "id"=>0,
        ],
        "1"=>[
            "name"=>$jatbi->lang('Chờ duyệt'),
            "color"=>'warning text-dark',
            "id"=>1,
        ],
        "2"=>[
            "name"=>$jatbi->lang('Đã duyệt'),
            "color"=>'success text-light',
            "id"=>2,
        ],
        "3"=>[
            "name"=>$jatbi->lang('Không duyệt'),
            "color"=>'dark text-light',
            "id"=>3,
        ],
        "4"=>[
            "name"=>$jatbi->lang('Đã bút toán'),
            "color"=>'primary text-light',
            "id"=>4,
        ],
        "5"=>[
            "name"=>$jatbi->lang('Khóa bút toán'),
            "color"=>'info text-light',
            "id"=>5,
        ],
        "10"=>[
            "name"=>$jatbi->lang('Yêu cầu hủy'),
            "color"=>'danger text-light',
            "id"=>10,
        ],
        "20"=>[
            "name"=>$jatbi->lang('Đã hủy'),
            "color"=>'secondary text-light',
            "id"=>20,
        ],
    ],
];
?> 