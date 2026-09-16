# QA Finding: หน้าเบิกสารเคมี (`/requisitions/{id}/issue`) พัง 500 ทุกครั้ง — ยังไม่ถูกแก้

**สถานะ:** ยังไม่ได้แก้ ณ commit `e556861` (ตรวจซ้ำด้วย full test suite แล้ว — ยังพังเหมือนเดิมทุกประการ)

เอกสารนี้เป็นรายงานเสริมจาก [`docs/qa_review_2026-09-16_grade_physical_state.md`](qa_review_2026-09-16_grade_physical_state.md)
ซึ่งไม่ได้ครอบคลุมบั๊กนี้ — เป็นคนละประเด็นกับ Hallucination guardrails/UI integration ที่รายงานฉบับนั้นพูดถึง
บั๊กนี้คือ **หน้าเบิกจริงใช้งานไม่ได้เลย ไม่ว่ากรณีใด** ควรแก้ก่อนสิ่งอื่นเพราะ block การใช้งานจริงทั้งหมด

---

## อาการ

ทุก request ที่โหลดหน้า `resources/views/requisitions/issue.blade.php` (หน้าที่ SCIENTIST/LAB_MANAGER
ใช้เบิก-จ่ายสารเคมีจริง) แสดง 500 error:

```
ParseError: syntax error, unexpected token "class" (View: requisitions/issue.blade.php)
```

**เกิดขึ้นทุกครั้ง ไม่ว่ารายการนั้นจะมี `physical_state` หรือไม่ก็ตาม** เพราะ Blade compile เทมเพลต
เป็น PHP ครั้งเดียวตอน request แรก (ไม่ใช่ conditional ตาม runtime data) — เทมเพลตทั้งไฟล์ compile ไม่ผ่าน
เลยพังทุกกรณี

**พบจาก:** `tests/Feature/Requisitions/RequisitionReturnControllerTest.php` เป็นเทสต์เดียวในทั้งระบบที่
`GET` หน้านี้จริงแล้ว assert 200 — เทสต์อื่นทั้งหมดที่เกี่ยวกับหน้าเบิก `POST` ตรงไปที่ action เลย ไม่เคย
render หน้าเว็บจริงเลยไม่มีใครจับได้มาก่อน

**ตรวจซ้ำล่าสุด:** รัน `php artisan view:clear` แล้ว full suite ใหม่ทั้งหมด — ยังได้ error เดิมเป๊ะ
(457 passed, 1 failed) ยืนยันว่าไม่ใช่ view cache เก่า และยังไม่มี code fix เข้ามาใน commit ล่าสุด

---

## ต้นตอที่ยืนยันแล้ว (ไล่ทีละขั้นด้วย `Blade::compileString()` โดยตรง)

ไฟล์นี้มี `@php(...)` สองรูปแบบปนกันอยู่ในไฟล์เดียว:

1. ของเดิม (บรรทัด ~22) — inline shorthand แบบมีวงเล็บ:
   ```blade
   @php($line = $row['line'])
   ```
2. ที่เพิ่มใหม่ (ส่วน physical_state badge) — block form:
   ```blade
   @php
       $stateBadge = match($line->item->physical_state) { ... };
   @endphp
   ```

**การมี `@php(...)` แบบมีวงเล็บ (inline) ปนกับ `@php ... @endphp` แบบ block ในไฟล์ Blade เดียวกัน
ทำให้ Laravel compile ผิดพลาด** — inline directive ตัวแรกไม่ได้ปิด `?>` ทำให้ HTML ทั้งหมดหลังจากนั้น
ถูกตีความเป็น PHP code ต่อเนื่อง แล้ว parse error ทันทีที่เจอ `class="..."` (syntax ของ HTML attribute)

ยืนยันด้วย minimal repro ที่ไม่เกี่ยวกับ physical_state/match() เลย — พังเหมือนกัน:
```blade
@php($a = 1)
<div>hello</div>
@php
$b = 2;
@endphp
```

## วิธีแก้ที่แนะนำ

เลือกอย่างใดอย่างหนึ่ง เอาแค่อย่าผสม `@php` สองแบบในไฟล์เดียวกัน:

- แปลง inline shorthand เดิมให้เป็น block form แทน:
  ```blade
  @php
      $line = $row['line'];
  @endphp
  ```
- ค้นหาทั้งโปรเจกต์ว่ามีไฟล์ Blade อื่นที่ผสม `@php(...)` inline กับ `@php...@endphp` block ในไฟล์
  เดียวกันแบบนี้อีกหรือไม่ (`grep -rn '@php(' resources/views` แล้วเช็คว่าไฟล์เดียวกันมี `@endphp` ด้วยไหม)
  เพราะนี่คือ Blade compiler quirk ที่จะเกิดซ้ำได้ทุกที่ที่ผสมสองรูปแบบนี้

---

## บั๊กย่อยที่พบระหว่างเดียวกัน (severity ต่ำ)

`tests/Feature/Inventory/ChemicalPropertiesWorkflowTest.php` (ใน `beforeEach()`) สร้าง Location ด้วย
key ผิด:
```php
Location::create([
    'name' => 'ตู้เก็บสารเคมีทดสอบ',
    'code' => 'CAB-TEST-01',
    'type' => 'CABINET',   // ควรเป็น 'level_type' — คอลัมน์จริงคือ level_type
    'lab_id' => $this->lab->id,
]);
```

`Location` model's fillable list มีแค่ `level_type` ไม่มี `type` — Eloquent เลยเงียบๆ ทิ้ง key `type` ไป
และ MariaDB (enum column ไม่มี default ชัดเจน) ใส่ค่า default เป็นค่าแรกของ enum ให้เอง คือ `'BUILDING'`
แทนที่จะเป็น `'CABINET'` ตามที่ตั้งใจ — ยืนยันผ่าน tinker แล้ว (ไม่ error แต่ค่าที่ได้ผิดเงียบๆ)

ไม่มี test ไหนใน 4 tests ของไฟล์นี้อ้างอิง `$this->location` เลยจึงยังไม่ fail แต่จะเป็นกับดักถ้ามี test
ในอนาคตพึ่งพา field นี้ — แก้โดยเปลี่ยน `'type' => 'CABINET'` เป็น `'level_type' => 'CABINET'`

---

## สถานะการตรวจสอบล่าสุด (หลัง commit `e556861`)

- Full test suite: 457/458 ผ่าน (เหลือแค่รายการด้านบน)
- Pint: clean
- PHPStan (`--debug --memory-limit=2G`): 0 errors
- `composer audit`: clean
