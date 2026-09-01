# คู่มือการเชื่อมต่อระบบยืนยันตัวตนกลาง (MEDSCI ACC & SSO Integration Guide)
**คณะวิทยาศาสตร์การแพทย์ มหาวิทยาลัยพะเยา**

ระบบบัญชีผู้ใช้งานกลาง (**MEDSCI ACC**) เป็นระบบบริการระบุตัวตนรวม (Identity Provider) ที่ช่วยให้ระบบงานคอมพิวเตอร์ต่าง ๆ ภายในคณะฯ สามารถยืนยันตัวตนผู้ใช้บริการผ่านบัญชี **UP Account** ของมหาวิทยาลัย และตรวจสอบสิทธิ์ความเป็นบุคลากรในสังกัดคณะวิทยาศาสตร์การแพทย์ได้พร้อมกัน โดยที่นักพัฒนาของระบบย่อย**ไม่จำเป็นต้องบันทึกรหัสผ่านของผู้ใช้ไว้ในฐานข้อมูลท้องถิ่น** และ**ไม่ต้องเขียนระบบเชื่อมต่อ SOAP Web Service เอง**

---

## สารบัญ
1. [การลงทะเบียนระบบย่อย (Client Registration)](#1-การลงทะเบียนระบบย่อย-client-registration)
2. [วิธีที่ 1: การเชื่อมต่อแบบ Single Sign-On (SSO Redirect - แนะนำ)](#วิธีที่-1-การเชื่อมต่อแบบ-single-sign-on-sso-redirect---แนะนำ)
3. [วิธีที่ 2: การเชื่อมต่อแบบเรียกใช้ API โดยตรง (Direct API Auth)](#วิธีที่-2-การเชื่อมต่อแบบเรียกใช้-api-โดยตรง-direct-api-auth)
4. [รายละเอียด API สำหรับตรวจสอบโทเคน (Verify Token API)](#รายละเอียด-api-สำหรับตรวจสอบโทเคน-verify-token-api)
5. [การออกจากระบบส่วนกลาง (Single Logout - SLO Endpoint)](#5-การออกจากระบบส่วนกลาง-single-logout---slo-endpoint)
6. [บัญชีสำหรับทดสอบออฟไลน์ (Developer Bypass Mode)](#6-บัญชีสำหรับทดสอบออฟไลน์-developer-bypass-mode)
7. [ข้อแนะนำด้านความปลอดภัย (Security Best Practices)](#7-ข้อแนะนำด้านความปลอดภัย-security-best-practices)

---

## 1. การลงทะเบียนระบบย่อย (Client Registration)

ก่อนที่จะเริ่มเขียนโค้ดเชื่อมต่อระบบย่อย ผู้ดูแลระบบของระบบย่อยจะต้องลงทะเบียนแอปพลิเคชันที่แผงควบคุมระบบกลางเพื่อรับสิทธิ์เข้าถึง:

1. เข้าไปที่แผงควบคุมของระบบกลาง เมนู **จัดการระบบย่อย** (หรือลิงก์: `https://www.medsci.up.ac.th/msc_acc/admin/clients.php`)
2. กดปุ่ม **"ลงทะเบียนระบบใหม่"**
3. กรอกข้อมูลดังต่อไปนี้:
   * **ชื่อระบบ**: ระบุชื่อระบบย่อยที่ต้องการนำไปแสดงผลเมื่อมีหน้าล็อกอิน เช่น *ระบบจองห้องประชุมออนไลน์*
   * **Client ID**: รหัสระบุตัวตนระบบย่อย (แนะนำให้ใช้ภาษาอังกฤษตัวพิมพ์เล็กและไม่มีช่องว่าง เช่น `room_booking`)
   * **Client Secret**: กดปุ่ม **"สุ่มกุญแจ"** เพื่อสร้างกุญแจรหัสผ่านลับ ซึ่งจะใช้ในการลงชื่อดิจิทัล (Digital Signature) และตรวจสอบสิทธิ์ทางเบื้องหลัง
   * **Redirect URIs**: ระบุ URL ปลายทางของระบบย่อยที่จะอนุญาตให้ระบบกลางส่งผู้ใช้กลับไปพร้อมกับโทเคนเมื่อล็อกอินสำเร็จ เช่น `https://www.medsci.up.ac.th/room_booking/sso_callback.php` *(หากมีหลายโดเมน ให้คั่นด้วยเครื่องหมายจุลภาค `,` )*
4. กด **"บันทึกข้อมูล"** และคัดลอกค่า **Client ID** กับ **Client Secret** ไว้เพื่อใช้ในซอร์สโค้ดของระบบย่อย

---

## วิธีที่ 1: การเชื่อมต่อแบบ Single Sign-On (SSO Redirect - แนะนำ)

วิธีนี้เหมาะสำหรับระบบที่พัฒนาใหม่ หรือระบบปัจจุบันที่ต้องการให้ผู้ใช้งานล็อกอินเพียงครั้งเดียวและเข้าใช้งานได้ทุกระบบงาน (Single Sign-On) โดยสถาปัตยกรรมจะแบ่งการทำงานเป็นขั้นตอนดังนี้:

```
[ ผู้ใช้งาน ] --(1. กดล็อกอิน)--> [ ระบบย่อยของคุณ ]
                                         |
                                (2. Redirect ไปล็อกอินกลาง)
                                         v
                                [ ล็อกอินกลาง (SSO) ] --(3. ตรวจสอบกับ UP SOAP)
                                         |
                            (4. ส่งกลับ Callback + Token)
                                         v
[ ผู้ใช้งาน ] <--(6. เข้าสู่ระบบ)-- [ ระบบย่อยของคุณ ] <--(5. ส่ง Token ไปตรวจเบื้องหลัง)--> [ API ระบบกลาง ]
```

### ขั้นตอนการทำสิทธิ์ (Implementation Steps)

#### 1. หน้าล็อกอินของระบบย่อย (เช่น `login.php`)
ให้เปลี่ยนปุ่มล็อกอินเดิมเป็นการ Redirect ส่งตัวผู้ใช้ไปยังระบบกลางพร้อมพารามิเตอร์ดังนี้:
* **`client_id`**: Client ID ที่ได้ลงทะเบียนไว้
* **`redirect_uri`**: URL หน้ารับข้อมูลผลลัพธ์ (Callback) ของระบบย่อย
* **`state`** *(แนะนำอย่างยิ่งเพื่อป้องกัน Login CSRF)*: ค่า Random Nonce หรือ CSRF Token สุ่มขึ้นมาและเก็บลงใน Session เพื่อผูกคำขอเริ่มต้นเข้ากับผลลัพธ์ที่ได้รับกลับมา

```php
<?php
// login.php (ระบบย่อยของคุณ)
session_start();

$client_id = "room_booking"; // ระบุ Client ID ของระบบคุณ
$callback_url = "https://www.medsci.up.ac.th/room_booking/sso_callback.php"; // ระบุ URL หน้า Callback

// สุ่มค่า state เก็บลง Session เพื่อป้องกันช่องโหว่ Login CSRF
$state = bin2hex(random_bytes(16));
$_SESSION['sso_state'] = $state;

// ทำการ Redirect ผู้ใช้งานไปยังหน้าล็อกอินกลาง
$sso_url = "https://www.medsci.up.ac.th/msc_acc/sso/login.php" . 
           "?client_id=" . urlencode($client_id) . 
           "&redirect_uri=" . urlencode($callback_url) .
           "&state=" . urlencode($state);

header("Location: " . $sso_url);
exit();
?>
```

#### 2. หน้ารับข้อมูลผลลัพธ์ของระบบย่อย (เช่น `sso_callback.php`)
เมื่อผู้ใช้งานป้อน Username และ Password สำเร็จ ระบบกลางจะ Redirect ส่งตัวผู้ใช้กลับมาที่ URL นี้พร้อมส่งพารามิเตอร์โทเคน `token` และ `state` เดิมแนบมาทาง URL (เช่น `sso_callback.php?token=eyJhbG...&state=...`)

ให้เขียนโค้ดทำหน้าที่ตรวจสอบความถูกต้องของ `state` เพื่อป้องกัน Login CSRF จากนั้นรับโทเคนดังกล่าวส่งตรวจสอบสิทธิ์ (Verify) ผ่าน HTTP POST ไปยัง API ระบบกลาง เพื่อถอดรหัสออกมาเป็นข้อมูลโปรไฟล์:

```php
<?php
// sso_callback.php (ระบบย่อยของคุณ)
session_start();

$token = trim($_GET['token'] ?? '');
$state = trim($_GET['state'] ?? '');
$saved_state = $_SESSION['sso_state'] ?? '';

$client_id = "room_booking"; // ระบุ Client ID ของระบบคุณ
$client_secret = "YOUR_CLIENT_SECRET_KEY"; // ระบุ Client Secret ของระบบคุณ

// -------------------------------------------------------------
// 1. ตรวจสอบความถูกต้องของ state ป้องกัน Login CSRF
// -------------------------------------------------------------
if (empty($state) || empty($saved_state) || !hash_equals($saved_state, $state)) {
    die("เข้าสู่ระบบล้มเหลว: ตรวจพบความไม่ถูกต้องของสถานะคำขอ (Invalid State / Login CSRF detected)");
}
unset($_SESSION['sso_state']); // ใช้งานแล้วลบทิ้งทันที

if (empty($token)) {
    die("เกิดข้อผิดพลาด: ไม่พบโทเคนยืนยันตัวตนส่งกลับมาจากระบบกลาง");
}

// -------------------------------------------------------------
// 2. เรียก API เพื่อตรวจสอบโทเคน (Token Verify)
// -------------------------------------------------------------
$verify_api_url = 'https://www.medsci.up.ac.th/msc_acc/api/verify.php';

$ch = curl_init($verify_api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'token' => $token,
    'client_id' => $client_id,
    'client_secret' => $client_secret
]));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // ป้องกันปัญหา Cert ในสภาพแวดล้อม Local Host
$response_json = curl_exec($ch);

if (curl_errno($ch)) {
    $error = curl_error($ch);
    curl_close($ch);
    die("ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์เพื่อยืนยันตัวตนได้: " . $error);
}
curl_close($ch);

$result = json_decode($response_json, true);

if ($result && $result['status'] === 'success') {
    // -------------------------------------------------------------
    // 3. ยืนยันตัวตนสำเร็จ! นำข้อมูลโปรไฟล์ที่ได้ไปเก็บใน Session ของระบบย่อย
    // -------------------------------------------------------------
    $_SESSION['logged_in'] = true;
    $_SESSION['user_id']   = $result['user']['user_id'];
    $_SESSION['username']  = $result['user']['username'];
    $_SESSION['name']      = $result['user']['name'];
    $_SESSION['pos_name']  = $result['user']['pos_name'];
    $_SESSION['div_name']  = $result['user']['div_name'];
    $_SESSION['email']     = $result['user']['email'];
    
    // Redirect ส่งผู้ใช้ไปยังหน้าหลักของระบบย่อย
    header("Location: index.php");
    exit();
} else {
    // โทเคนถูกดัดแปลง ดักจับ ใช้ซ้ำ หรือหมดอายุ (อายุโทเคน 2 นาที และใช้ได้ครั้งเดียว)
    $error_msg = $result['message'] ?? 'โทเคนยืนยันสิทธิ์ไม่ถูกต้อง หมดอายุ หรือถูกใช้ไปแล้ว';
    die("สิทธิ์เข้าถึงล้มเหลว: " . htmlspecialchars($error_msg));
}
?>
```

---

## วิธีที่ 2: การเชื่อมต่อแบบเรียกใช้ API โดยตรง (Direct API Auth)

วิธีนี้เหมาะสำหรับ**ระบบย่อยเดิม**ที่ต้องการใช้หน้าจอ Login (UI) ตัวเก่าของตัวเองอยู่ แต่ต้องการเปลี่ยนระบบเบื้องหลัง (Backend) ในการตรวจสอบบัญชี โดยส่ง Username และ Password ไปเช็คกับระบบกลางผ่าน HTTP POST API

### สคริปต์ตรวจสอบการล็อกอินของระบบย่อย (เช่น `check_login.php`)
ให้แก้ไขโค้ดที่เคยใช้คิวรี่เช็คจากตารางฐานข้อมูลโดยตรง เปลี่ยนเป็นการยิง cURL ไปตรวจสอบแทน:

```php
<?php
// check_login.php (ระบบย่อยของคุณ)
session_start();

$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');

$client_id = "room_booking"; // ระบุ Client ID ของระบบคุณ
$client_secret = "YOUR_CLIENT_SECRET_KEY"; // ระบุ Client Secret ของระบบคุณ

if (empty($username) || empty($password)) {
    die("กรุณากรอกข้อมูลให้ครบถ้วน");
}

// ยิงขอตรวจสอบสิทธิ์ไปยัง API ล็อกอินส่วนกลาง
$login_api_url = 'https://www.medsci.up.ac.th/msc_acc/api/login.php';

$ch = curl_init($login_api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'username' => $username,
    'password' => $password,
    'client_id' => $client_id,
    'client_secret' => $client_secret
]));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response_json = curl_exec($ch);
curl_close($ch);

$result = json_decode($response_json, true);

if ($result && $result['status'] === 'success') {
    // -------------------------------------------------------------
    // ล็อกอินและเช็คสิทธิ์สำเร็จ! เซ็ต Session ของระบบย่อย
    // -------------------------------------------------------------
    $_SESSION['logged_in'] = true;
    $_SESSION['user_id']   = $result['user']['user_id'];
    $_SESSION['username']  = $result['user']['username'];
    $_SESSION['name']      = $result['user']['name'];
    $_SESSION['pos_name']  = $result['user']['pos_name'];
    $_SESSION['div_name']  = $result['user']['div_name'];
    $_SESSION['email']     = $result['user']['email'];
    
    header("Location: index.php");
    exit();
} else {
    // รหัสผ่านผิด หรือไม่ใช่บุคลากรของคณะวิทยาศาสตร์การแพทย์
    $error_msg = $result['message'] ?? 'ชื่อผู้ใช้งานหรือรหัสผ่านไม่ถูกต้อง';
    echo "<script>alert('" . addslashes($error_msg) . "'); window.history.back();</script>";
    exit();
}
?>
```

---

## 4. รายละเอียด API สำหรับตรวจสอบโทเคน (Verify Token API)

ในกรณีที่ระบบย่อยของคุณใช้สถาปัตยกรรมประเภท Single Page Application (เช่น React, Vue) หรือภาษาอื่น ๆ สามารถยิงตรวจสอบสถานะ Token ได้ตามรายละเอียดนี้:

* **URL**: `https://www.medsci.up.ac.th/msc_acc/api/verify.php`
* **Method**: `POST` หรือ `GET`
* **Content-Type**: `application/x-www-form-urlencoded` หรือ `multipart/form-data`

### พารามิเตอร์ที่ต้องส่ง (Request Parameters)
| พารามิเตอร์ | ประเภท | คำอธิบาย |
| --- | --- | --- |
| `token` | String | โทเคน JWT ที่ได้ส่งกลับมาจากระบบกลางหลังการล็อกอิน |
| `client_id` | String | Client ID ของระบบย่อยคุณที่ลงทะเบียนไว้ |
| `client_secret` | String | กุญแจลับ Client Secret ที่คู่กัน |

### ตัวอย่างผลลัพธ์เมื่อทำสิทธิ์สำเร็จ (Response - HTTP 200)
```json
{
  "status": "success",
  "message": "Token is valid",
  "user": {
    "user_id": 1,
    "username": "wittaya.su",
    "name": "นายวิทยา สุนสะดี",
    "pos_name": "นักวิชาการคอมพิวเตอร์",
    "div_name": "สำนักงานธุรการ",
    "email": "wittaya.su@up.ac.th"
  }
}
```

### ตัวอย่างผลลัพธ์เมื่อทำสิทธิ์ไม่สำเร็จ (Response - HTTP 400/401)
```json
{
  "status": "failed",
  "message": "Token is invalid or expired"
}
```

---

## 5. การออกจากระบบส่วนกลาง (Single Logout - SLO Endpoint)

เพื่อให้การออกจากระบบมีความปลอดภัยสูงสุด เมื่อผู้ใช้งานกดปุ่ม **"ออกจากระบบ" (Logout)** ในระบบย่อย แนะนำให้ระบบย่อยทำลาย Session ภายในของตัวเอง แล้วทำการ Redirect ผู้ใช้มายัง Single Logout Endpoint ของระบบกลาง เพื่อล้าง Session ส่วนกลางพร้อมกัน:

* **Endpoint URL**: `https://www.medsci.up.ac.th/msc_acc/sso/logout.php`
* **HTTP Method**: `GET`
* **พารามิเตอร์**:
  * `redirect_uri` *(แนะนำ)*: URL ปลายทางของระบบย่อยที่ต้องการให้ระบบกลาง Redirect ส่งผู้ใช้กลับไปหลังจากล้าง Session กลางเสร็จแล้ว (เช่น `https://www.medsci.up.ac.th/your_app/login.php`)
  * `client_id` *(ไม่บังคับ)*: รหัสระบุตัวตนของระบบย่อย เพื่อบันทึกประวัติการออกจากระบบลงใน `audit_logs`

### ตัวอย่างโค้ดการทำ Single Logout ในระบบย่อย (เช่น `logout.php`)
```php
<?php
// logout.php (ของระบบย่อย)
session_start();

// 1. ทำลาย Session ของระบบย่อย
session_destroy();

// 2. กำหนด URL หน้ารับกลับ
$return_url = 'https://www.medsci.up.ac.th/your_app/login.php';
$client_id  = 'your_client_id';

// 3. ส่งตัวผู้ใช้ไปยัง Single Logout Endpoint ของระบบกลาง
$sso_logout_url = 'https://www.medsci.up.ac.th/msc_acc/sso/logout.php' .
                  '?redirect_uri=' . urlencode($return_url) .
                  '&client_id=' . urlencode($client_id);

header("Location: " . $sso_logout_url);
exit();
?>
```

---

## 6. บัญชีสำหรับทดสอบออฟไลน์ (Developer Bypass Mode)

เพื่ออำนวยความสะดวกในการพัฒนาระบบต่อในเครื่องส่วนตัว (Localhost) ที่ไม่สามารถเชื่อมต่อกับเน็ตเวิร์ก SOAP Web Service ของมหาวิทยาลัยได้โดยตรง คุณสามารถส่งบัญชีทดสอบเพื่อรับผลลัพธ์จำลองสำเร็จดังนี้:

* **Username**: `admin.dev`
* **Password**: `dev1234`
* **ผลลัพธ์ที่ได้**: ระบบกลางจะจำลองการล็อกอินสำเร็จ และจับคู่โปรไฟล์เข้ากับบุคลากรในฐานข้อมูล คือ บัญชี `wittaya.su` เพื่อส่งข้อมูลกลับไปยังระบบย่อยให้ไปเขียนโค้ดพัฒนาต่อได้ทันที

---

## 7. ข้อแนะนำด้านความปลอดภัย (Security Best Practices)

> [!WARNING]
> **1. การเก็บรักษากุญแจลับ (Client Secret)**
> รหัส `client_secret` ถือเป็นกุญแจสำคัญในการรับประกันความปลอดภัยของระบบ ห้ามเผยแพร่ เปิดเผยลงในโค้ด JavaScript ฝั่ง Client-Side หรือจัดเก็บใน Git Repository ที่เป็นสาธารณะโดยเด็ดขาด ให้จัดเก็บไว้ในตัวแปรสิ่งแวดล้อม (Environment Variables) หรือไฟล์ Config ทางฝั่ง Server-Side เท่านั้น
> 
> **2. การใช้โปรโตคอล HTTPS**
> เพื่อป้องกันการดักจับโทเคนระหว่างทาง (Man-in-the-Middle Attack) ระบบย่อยและระบบหลักบัญชีกลางทั้งหมดจะต้องส่งผ่านข้อมูลภายใต้โปรโตคอลความปลอดภัย **HTTPS** เท่านั้น
> 
> **3. การกำหนด Redirect URIs**
> ควรใส่ URL ส่งกลับในหน้าลงทะเบียนให้ตรงกับโฟลเดอร์ใช้งานจริงที่สุด ไม่ควรใช้เครื่องหมาย Wildcard และไม่แนะนำให้ปล่อยว่าง เพื่อป้องกันการโจมตีประเภท Open Redirect (การดักขโมยโทเคนส่งต่อออกนอกโดเมน)
> 
> **4. การป้องกันช่องโหว่ Login CSRF ด้วยพารามิเตอร์ `state`**
> ระบบย่อยทุกระบบ**ต้องสุ่มค่า `state` (Random Nonce)** บันทึกไว้ใน Session ก่อนส่งผู้ใช้ไปล็อกอินกลาง และนำค่า `state` ที่ได้รับกลับมาตรวจสอบกับ Session เสมอ เพื่อป้องกันผู้ไม่หวังดีส่ง URL Callback ที่มีโทเคนของตนเองมาหลอกให้เหยื่อกดเข้าใช้งาน ซึ่งจะทำให้เบราว์เซอร์ของเหยื่อกลายเป็นบัญชีของผู้ไม่หวังดีโดยไม่รู้ตัว (Login CSRF Attack)
> 
> **5. อายุของโทเคนสั้น (Short-Lived Token: 2 นาที) และใช้ได้ครั้งเดียว (Single-Use Token)**
> โทเคนยืนยันสิทธิ์ที่ส่งผ่าน URL Query String มีอายุใช้งานเพียง **120 วินาที (2 นาที)** เท่านั้น และระบบกลาง **MEDSCI ACC** มีกลไกป้องกัน Replay Attack โดยจะยอมให้โทเคนถูกนำมา Verify สำเร็จได้เพียง **ครั้งเดียว (Single-Use)** หากนำโทเคนเดิมมาเรียกซ้ำจะถูกปฏิเสธทันที
> 
> **6. การป้องกันการรั่วไหลของโทเคน (Token Leakage Mitigation)**
> เมื่อระบบย่อยได้รับโทเคนในหน้า `sso_callback.php` และเรียก API ตรวจสอบสิทธิ์สำเร็จแล้ว **ต้องทำการ `header("Location: index.php");` (Redirect)** ออกจากหน้า Callback ทันที เพื่อป้องกันไม่ให้ URL ที่มี `?token=...` ติดค้างอยู่ใน Browser History หรือรั่วไหลผ่าน `Referer` Header เมื่อผู้ใช้เปิดลิงก์ภายนอก
