<?php

return [
    'index_title' => 'ปรับปรุงยอด',
    'index_subtitle' => 'ประวัติการปรับปรุงยอดคงเหลือ (ADJUST_IN/ADJUST_OUT) พร้อมผู้สร้างและผู้อนุมัติ',
    'new_adjustment' => 'ปรับปรุงยอด',
    'back_to_list' => 'กลับไปยังรายการ',
    'no_results' => 'ยังไม่มีรายการปรับปรุงยอด',
    'col_doc_no' => 'เลขที่อ้างอิง',
    'col_item' => 'รายการสาร/วัสดุ',
    'col_container' => 'ภาชนะ',
    'col_type' => 'ประเภท',
    'col_qty' => 'จำนวน',
    'col_remark' => 'หมายเหตุ',
    'col_created_by' => 'ผู้สร้างรายการ',
    'col_approved_by' => 'ผู้อนุมัติ',
    'col_date' => 'วันที่',

    'type_adjust_in' => 'ปรับเพิ่ม',
    'type_adjust_out' => 'ปรับลด',

    'create_title' => 'ปรับปรุงยอดคงเหลือ',
    'field_barcode' => 'บาร์โค้ดภาชนะ',
    'field_direction' => 'ทิศทางการปรับ',
    'direction_in' => 'ปรับเพิ่ม (IN)',
    'direction_out' => 'ปรับลด (OUT)',
    'field_qty' => 'จำนวนที่ปรับ',
    'field_remark' => 'หมายเหตุ (อย่างน้อย 10 ตัวอักษร)',
    'field_approved_by' => 'ผู้อนุมัติ (ต้องเป็นหัวหน้าสาขาวิชาหรือผู้ดูแลคลัง ไม่ใช่ตัวท่านเอง)',
    'save' => 'บันทึกการปรับปรุงยอด',

    'created' => 'บันทึกการปรับปรุงยอดเรียบร้อยแล้ว',

    'validation' => [
        'barcode_required' => 'กรุณาระบุบาร์โค้ดภาชนะ',
        'barcode_not_found' => 'ไม่พบภาชนะตามบาร์โค้ดนี้',
        'qty_required' => 'กรุณาระบุจำนวนที่ปรับ',
        'qty_gt' => 'จำนวนที่ปรับต้องมากกว่า 0',
        'remark_required' => 'กรุณาระบุหมายเหตุ',
        'remark_min' => 'หมายเหตุต้องมีอย่างน้อย 10 ตัวอักษร (BR-06)',
        'approver_required' => 'กรุณาเลือกผู้อนุมัติ',
    ],
];
