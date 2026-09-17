# QA Finding: `vendor/bin/pest` (full suite) fatals immediately — `Cannot redeclare`

**สถานะ:** แก้ไขเรียบร้อยแล้ว (Resolved) — ลบนิยามซ้ำของ `submittedRequisition` และ `makeDraftGrn` ออกจากไฟล์เทสเฉพาะจุด โดยใช้ฟังก์ชันกลางจาก `tests/Pest.php` แทนเรียบร้อยแล้ว

---

## อาการ

รัน `vendor/bin/pest` (ไม่ใส่ `--filter`, สวีทเต็ม) ได้ fatal error ทันที ไม่มี test ไหนรันสำเร็จเลย:

```
Pest\Exceptions\FatalException

Cannot redeclare makeDraftGrn() (previously declared in /var/www/html/tests/Pest.php:230)

at tests/Feature/GoodsReceipts/GoodsReceiptServiceTest.php:28
```

รันแยกเฉพาะ `tests/Feature/Requisitions` ก็ fatal เหมือนกัน คนละฟังก์ชัน:

```
Cannot redeclare submittedRequisition() (previously declared in /var/www/html/tests/Pest.php:211)

at tests/Feature/Requisitions/ApprovalServiceTest.php:13
```

## ต้นตอ

Commit `c2daed0` ย้าย helper 2 ตัว (`submittedRequisition`, `makeDraftGrn`) ไปรวมไว้ใน
`tests/Pest.php` โดยห่อด้วย `if (! function_exists(...))` ถูกต้อง — ตรงตามแบบแผนที่ทำมาตลอด
(ดู `CLAUDE.md`'s helper-centralization notes) แต่**ลืมลบ (หรือห่อด้วย `function_exists`) ตัวเดิม**
ที่ยังอยู่แบบไม่มีการ์ดในไฟล์เทสเดิม:

- `tests/Feature/Requisitions/ApprovalServiceTest.php:13` — `function submittedRequisition(...)`
  ไม่มี `if (! function_exists(...))` ครอบ
- `tests/Feature/GoodsReceipts/GoodsReceiptServiceTest.php:28` — `function makeDraftGrn(...)`
  ไม่มี `if (! function_exists(...))` ครอบ

Pest โหลด `tests/Pest.php` เป็น bootstrap ก่อนเสมอ (นิยามฟังก์ชันทั้งสองไว้ก่อน) พอ parse ไฟล์เทสทั้งสอง
นี้ต่อ ก็ชนกับฟังก์ชันที่มีชื่อซ้ำที่นิยามไปแล้ว — PHP fatal ทันที เพราะ redeclare ฟังก์ชันชื่อเดียวกัน
ในกระบวนการเดียวกันไม่ได้ (ไม่ใช่ class ที่มี autoload/lazy resolution)

## ผลกระทบ

**สวีทเต็มรันไม่ได้เลยสักตัวเดียว** — ไม่ใช่แค่ test ที่เกี่ยวข้องกับ 2 ฟังก์ชันนี้ fail แต่ Pest หยุดทั้ง
กระบวนการทันทีที่เจอ fatal (เหมือนตอน Blade parse error ครั้งก่อน — บล็อกการยืนยัน (`php artisan test`)
ของทุกงานที่ยังไม่ merge ไม่ใช่แค่งานที่เพิ่งเปลี่ยน)

## วิธีแก้ที่แนะนำ

เลือกอย่างใดอย่างหนึ่ง (เอาแค่อย่าให้ชื่อเดียวกันถูกนิยาม 2 ที่แบบไม่มีการ์ด):

- ลบนิยามเดิมออกจาก `ApprovalServiceTest.php`/`GoodsReceiptServiceTest.php` เลย (เหมือนที่ทำกับ
  `stockItemInLab`/`studentUserWithLab` ในรอบก่อนหน้า — ของกลางใน `Pest.php` มีอยู่แล้ว ไม่ต้องมีซ้ำ)
- หรือถ้าจะเก็บไว้ในไฟล์เดิมด้วย ต้องห่อด้วย `if (! function_exists('...'))` ให้ตรงกับที่อื่นทุกที่

แนะนำให้ค้นทั้งโปรเจกต์อีกรอบว่ามีฟังก์ชันอื่นที่เพิ่งย้ายเข้า `Pest.php` แต่ยังมีนิยามเดิมไม่มีการ์ดหลง
เหลืออยู่ที่ไหนอีกหรือไม่ (`grep -rn "^function " tests/ | grep -v Pest.php` แล้วเทียบชื่อกับที่มีใน
`tests/Pest.php`) — same class of bug ที่จะเกิดซ้ำได้ทุกครั้งที่ centralize helper ตัวใหม่

## สถานะการตรวจสอบล่าสุด

- `vendor/bin/pest` (สวีทเต็ม): **fatal ทันที ไม่มี test รันเลย**
- แยกตรวจเฉพาะไฟล์ที่ผมแก้เอง (การตัดเลข 0 ท้ายของค่าที่แสดงผล) — รันผ่านทั้งหมดเมื่อรันแยกไฟล์
  (ไม่โดนผลกระทบจากบั๊กนี้โดยตรง แต่ยืนยันสวีทรวมไม่ได้จนกว่าจะแก้ 2 จุดข้างต้น)
