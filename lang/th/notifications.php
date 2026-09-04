<?php

return [
    'index_title' => 'การแจ้งเตือน',
    'no_results' => 'ยังไม่มีการแจ้งเตือน',
    'mark_all_read' => 'ทำเครื่องหมายว่าอ่านแล้วทั้งหมด',
    'mail_cta' => 'ดูรายละเอียด',

    // FR-NT-03: มีใบเบิกรออนุมัติ
    'pending_advisor_title' => 'มีใบขอเบิกรออนุมัติจากท่าน',
    'pending_advisor_body' => 'ใบขอเบิกเลขที่ :doc_no จาก :requester รอการอนุมัติจากอาจารย์ที่ปรึกษา',
    'pending_scientist_title' => 'มีใบขอเบิกรอพิจารณา',
    'pending_scientist_body' => 'ใบขอเบิกเลขที่ :doc_no รอการพิจารณาจากนักวิทยาศาสตร์',

    // FR-NT-04: ผลอนุมัติ/ปฏิเสธใบเบิก
    'decision_approved_title' => 'ใบขอเบิกเลขที่ :doc_no ได้รับการอนุมัติ',
    'decision_rejected_title' => 'ใบขอเบิกเลขที่ :doc_no ไม่ได้รับการอนุมัติ',
    'decision_body_advisor' => 'อาจารย์ที่ปรึกษาได้พิจารณาใบขอเบิกเลขที่ :doc_no ของท่านแล้ว',
    'decision_body_scientist' => 'นักวิทยาศาสตร์ได้พิจารณาใบขอเบิกเลขที่ :doc_no ของท่านแล้ว',

    // FR-NT-01: คงเหลือ < reorder_point
    'reorder_title' => 'คงเหลือต่ำกว่าจุดสั่งซื้อ: :item',
    'reorder_body' => ':item (:item_code) มีปริมาณคงเหลือต่ำกว่าจุดสั่งซื้อที่กำหนดไว้',

    // FR-NT-02: ใกล้หมดอายุ 90/30/7 วัน
    'expiry_title' => ':item ใกล้หมดอายุใน :days วัน',
    'expiry_body' => 'ภาชนะบาร์โค้ด :barcode ของ :item จะหมดอายุวันที่ :expiry_date',

    // FR-NT-05: ภาชนะเปิดใช้เกิน shelf_life_days_after_open
    'shelf_life_title' => 'ภาชนะเปิดใช้เกินอายุการใช้งาน: :item',
    'shelf_life_body' => 'ภาชนะบาร์โค้ด :barcode ของ :item เปิดใช้งานนานเกินอายุการใช้งานหลังเปิดที่กำหนดไว้แล้ว',

    // FR-NT-06: Hash chain ผิดปกติ
    'hash_chain_title' => 'พบความผิดปกติของ Hash Chain ในบัญชีคุมวัสดุ',
    'hash_chain_body' => 'ตรวจพบ hash chain ที่ไม่สมบูรณ์ในรายการต่อไปนี้: :items — กรุณาตรวจสอบโดยด่วน',
];
