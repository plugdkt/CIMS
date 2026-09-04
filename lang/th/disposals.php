<?php

return [
    'index_title' => 'ทำลาย/ตัดจำหน่าย',
    'index_subtitle' => 'บันทึกการทำลายหรือทิ้งสารเคมี/วัสดุ พร้อมเหตุผลและผู้อนุมัติ',
    'new_disposal' => 'ขอทำลาย/ตัดจำหน่าย',
    'back_to_list' => 'กลับไปยังรายการ',
    'view' => 'ดู',
    'no_results' => 'ยังไม่มีรายการทำลาย/ตัดจำหน่าย',
    'col_doc_no' => 'เลขที่เอกสาร',
    'col_item' => 'รายการสาร/วัสดุ',
    'col_reason' => 'เหตุผล',
    'col_disposal_date' => 'วันที่ทำลาย',
    'col_status' => 'สถานะ',

    'status_pending' => 'รอพิจารณา',
    'status_approved' => 'อนุมัติแล้ว',
    'status_rejected' => 'ไม่อนุมัติ',

    'reason_expired' => 'หมดอายุ',
    'reason_contaminated' => 'ปนเปื้อน',
    'reason_damaged' => 'ชำรุด/เสียหาย',
    'reason_waste' => 'ของเสีย',
    'reason_other' => 'อื่น ๆ',

    'create_title' => 'ขอทำลาย/ตัดจำหน่าย',
    'field_barcode' => 'บาร์โค้ดภาชนะ',
    'field_qty' => 'ปริมาณที่ทำลาย',
    'field_reason' => 'เหตุผล',
    'field_method' => 'วิธีกำจัด',
    'field_method_hint' => 'เช่น เผาทำลาย, ฝังกลบ, ส่งบริษัทกำจัดของเสีย',
    'field_disposal_date' => 'วันที่ทำลาย',
    'field_item' => 'รายการสาร/วัสดุ',
    'field_requested_by' => 'ผู้ขอทำลาย',
    'field_approved_by' => 'ผู้อนุมัติ',
    'save' => 'บันทึก',

    'created' => 'บันทึกคำขอทำลาย/ตัดจำหน่ายเรียบร้อยแล้ว รอการอนุมัติ',
    'approved' => 'อนุมัติเรียบร้อยแล้ว ระบบบันทึกรายการทำลายเข้าบัญชีคุมแล้ว',
    'rejected' => 'ปฏิเสธคำขอทำลาย/ตัดจำหน่ายเรียบร้อยแล้ว',

    'approve' => 'อนุมัติ',
    'reject' => 'ไม่อนุมัติ',

    'validation' => [
        'barcode_required' => 'กรุณาระบุบาร์โค้ดภาชนะ',
        'barcode_not_found' => 'ไม่พบภาชนะตามบาร์โค้ดนี้',
        'qty_required' => 'กรุณาระบุปริมาณที่ทำลาย',
        'qty_gt' => 'ปริมาณที่ทำลายต้องมากกว่า 0',
        'reason_required' => 'กรุณาเลือกเหตุผล',
        'disposal_date_required' => 'กรุณาระบุวันที่ทำลาย',
    ],
];
