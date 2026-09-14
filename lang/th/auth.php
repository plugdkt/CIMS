<?php

return [
    'app_name' => 'CMIS',
    'app_tagline' => 'คลังวัสดุ · คณะวิทยาศาสตร์การแพทย์ มหาวิทยาลัยพะเยา',

    'pending_role_title' => 'รอผู้ดูแลระบบกำหนดสิทธิ์การใช้งาน',
    'pending_role_body' => 'บัญชีของคุณเข้าสู่ระบบสำเร็จแล้ว แต่ยังไม่ได้รับสิทธิ์การใช้งานจากผู้ดูแลระบบ กรุณาติดต่อผู้ดูแลระบบเพื่อขอกำหนดสิทธิ์การใช้งาน',
    'pending_role_signed_in_as' => 'เข้าสู่ระบบด้วยบัญชี',

    'pending_lab_title' => 'รอผู้ดูแลระบบกำหนดสาขา',
    'pending_lab_body' => 'บัญชีของคุณยังไม่ได้ถูกกำหนดสาขา (ห้องปฏิบัติการ) กรุณาติดต่อผู้ดูแลระบบเพื่อขอกำหนดสาขาก่อนสร้างใบขอเบิก',

    'complete_profile_title' => 'กรอกข้อมูลเพิ่มเติม',
    'complete_profile_body' => 'กรุณากรอกข้อมูลด้านล่างให้ครบก่อนสร้างใบขอเบิกครั้งแรก',
    'complete_profile_success' => 'บันทึกข้อมูลเรียบร้อยแล้ว',
    'field_person_type' => 'สถานภาพ',
    'field_person_type_lecturer' => 'อาจารย์',
    'field_person_type_staff' => 'เจ้าหน้าที่',
    'field_person_type_student' => 'นิสิต',
    'field_phone' => 'เบอร์โทรศัพท์',
    'field_person_code' => 'รหัสนิสิต/รหัสพนักงาน',
    'field_program' => 'สาขาวิชา/หลักสูตร',
    'field_faculty' => 'คณะ',
    'field_advisor' => 'อาจารย์ที่ปรึกษา',
    'field_advisor_help' => 'จำเป็นสำหรับนิสิตเท่านั้น',
    'save' => 'บันทึกข้อมูล',

    'logout' => 'ออกจากระบบ',
    'login' => 'เข้าสู่ระบบด้วยบัญชี MEDSCI ACC',

    'validation' => [
        'person_type_required' => 'กรุณาเลือกสถานภาพ',
        'person_type_invalid' => 'สถานภาพไม่ถูกต้อง',
        'phone_required' => 'กรุณากรอกเบอร์โทรศัพท์',
        'phone_invalid' => 'รูปแบบเบอร์โทรศัพท์ไม่ถูกต้อง',
        'person_code_required' => 'กรุณากรอกรหัสนิสิต/รหัสพนักงาน',
        'faculty_required' => 'กรุณากรอกคณะ',
        'advisor_required_for_student' => 'นิสิตต้องเลือกอาจารย์ที่ปรึกษา',
        'advisor_invalid' => 'ไม่พบอาจารย์ที่ปรึกษาที่เลือก',
    ],
];
