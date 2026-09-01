# คู่มือปรับปรุงการเชื่อมต่อ SSO สำหรับระบบย่อย (SSO Quickstart & Upgrade Guide)
**ระบบบัญชีผู้ใช้งานกลาง คณะวิทยาศาสตร์การแพทย์ (MEDSCI ACC)**

เอกสารฉบับนี้จัดทำขึ้นเพื่อให้นักพัฒนาของระบบย่อย (เช่น ระบบ BPM, ระบบจองห้อง ฯลฯ) นำไปใช้ปรับปรุงโค้ดเชื่อมต่อระบบยืนยันตัวตนกลาง (Single Sign-On) ให้รองรับการป้องกันช่องโหว่ **Login CSRF** และสอดคล้องกับมาตรฐานความปลอดภัยใหม่

---

## 📌 สิ่งที่ต้องเตรียมก่อนเริ่ม
1. **Client ID** และ **Client Secret** ที่ได้รับจากผู้ดูแลระบบกลาง (หากยังไม่มี ให้ติดต่อแอดมินเพื่อลงทะเบียนที่ระบบกลาง)
2. **Redirect URI (Callback URL)** เช่น `https://www.medsci.up.ac.th/your_app/sso_callback.php` ที่ได้ลงทะเบียนไว้ในระบบกลาง

---

## 🛠️ จุดที่ต้องแก้ไขในระบบย่อย (มีเพียง 2 ไฟล์)

### 1. หน้าส่งผู้ใช้ไปล็อกอิน (เช่น `login.php`)
ทำการสุ่มค่า `state` (Random Nonce) เก็บลงใน `$_SESSION` เพื่อผูกคำขอเริ่มต้นเข้ากับผลลัพธ์ที่จะได้รับกลับมา และแนบพารามิเตอร์ `&state=` ไปกับ URL ก่อน Redirect:

```php
<?php
// login.php (ระบบย่อยของคุณ)
session_start();

// กำหนดค่าคอนฟิกของระบบคุณ
define('CLIENT_ID', 'YOUR_CLIENT_ID'); // เช่น 'bpm_system'
define('CALLBACK_URL', 'https://www.medsci.up.ac.th/your_app/sso_callback.php');
define('SSO_LOGIN_URL', 'https://www.medsci.up.ac.th/msc_acc/sso/login.php');

// 1. สุ่มค่า state เก็บลง Session เพื่อป้องกัน Login CSRF
$state = bin2hex(random_bytes(16));
$_SESSION['sso_state'] = $state;

// 2. สร้าง URL สำหรับ Redirect ไปยังหน้าล็อกอินกลาง
$sso_url = SSO_LOGIN_URL .
           "?client_id=" . urlencode(CLIENT_ID) .
           "&redirect_uri=" . urlencode(CALLBACK_URL) .
           "&state=" . urlencode($state);

// 3. ส่งตัวผู้ใช้ไปยังหน้าล็อกอินกลาง
header("Location: " . $sso_url);
exit();
?>
```

---

### 2. หน้ารับผลลัพธ์การล็อกอิน (เช่น `sso_callback.php`)
เมื่อผู้ใช้ล็อกอินสำเร็จ ระบบกลางจะส่งผู้ใช้กลับมาที่หน้านี้พร้อม `?token=...&state=...` ให้ตรวจสอบค่า `state` ก่อน แล้วจึงส่ง `token` ไป Verify กับ API ระบบกลาง:

```php
<?php
// sso_callback.php (ระบบย่อยของคุณ)
session_start();

// กำหนดค่าคอนฟิกของระบบคุณ
define('CLIENT_ID', 'YOUR_CLIENT_ID');
define('CLIENT_SECRET', 'YOUR_CLIENT_SECRET_KEY');
define('VERIFY_API_URL', 'https://www.medsci.up.ac.th/msc_acc/api/verify.php');

$token = trim($_GET['token'] ?? '');
$state = trim($_GET['state'] ?? '');
$saved_state = $_SESSION['sso_state'] ?? '';

// -------------------------------------------------------------
// ขั้นตอนที่ 1: ตรวจสอบความถูกต้องของ state (ป้องกัน Login CSRF)
// -------------------------------------------------------------
if (empty($state) || empty($saved_state) || !hash_equals($saved_state, $state)) {
    die("เข้าสู่ระบบล้มเหลว: ตรวจพบความผิดปกติของคำขอเข้าสู่ระบบ (Invalid SSO State / Login CSRF Protection)");
}
// ลบ state ทิ้งทันทีเพื่อป้องกันการนำมาใช้ซ้ำ (One-Time Use)
unset($_SESSION['sso_state']);

if (empty($token)) {
    die("เข้าสู่ระบบล้มเหลว: ไม่พบโทเคนยืนยันสิทธิ์ส่งกลับมาจากระบบกลาง");
}

// -------------------------------------------------------------
// ขั้นตอนที่ 2: ยิง cURL ไปยัง API เพื่อตรวจสอบโทเคน (Verify Token)
// -------------------------------------------------------------
$ch = curl_init(VERIFY_API_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'token' => $token,
    'client_id' => CLIENT_ID,
    'client_secret' => CLIENT_SECRET
]));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // ป้องกันปัญหา SSL ในสภาพแวดล้อม Local
$response_json = curl_exec($ch);

if (curl_errno($ch)) {
    $error = curl_error($ch);
    curl_close($ch);
    die("ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์หลักเพื่อยืนยันโทเคนได้: " . $error);
}
curl_close($ch);

$result = json_decode($response_json, true);

// -------------------------------------------------------------
// ขั้นตอนที่ 3: รับผลลัพธ์และสร้าง Session ของระบบย่อย
// -------------------------------------------------------------
if ($result && $result['status'] === 'success') {
    // ดึงข้อมูลโปรไฟล์ผู้ใช้งานที่ผ่านการยืนยันตัวตนและตรวจสิทธิ์คณะฯ แล้ว
    $user = $result['user'];
    
    $_SESSION['logged_in'] = true;
    $_SESSION['user_id']   = $user['user_id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['name']      = $user['name'];
    $_SESSION['pos_name']  = $user['pos_name'];
    $_SESSION['div_name']  = $user['div_name'];
    $_SESSION['email']     = $user['email'];

    // ส่งผู้ใช้เข้าสู่หน้าแรกของระบบย่อยทันที
    header("Location: index.php");
    exit();
} else {
    $msg = $result['message'] ?? 'โทเคนไม่ถูกต้อง หมดอายุ หรือถูกใช้งานไปแล้ว';
    die("การยืนยันตัวตนล้มเหลว: " . htmlspecialchars($msg));
}
?>
```

---

### 3. หน้าออกจากระบบ (Single Logout - เช่น `logout.php`)
เมื่อผู้ใช้กดออกจากระบบในระบบย่อย ให้ทำลาย Session ในระบบย่อย แล้ว Redirect ไปยัง Single Logout Endpoint ของระบบกลางเพื่อล้าง Session กลางพร้อมกัน:

```php
<?php
// logout.php (ระบบย่อยของคุณ)
session_start();

// 1. ล้าง Session ในระบบย่อย
session_destroy();

// 2. กำหนด URL ปลายทางที่ต้องการให้กลับมาหลังจาก Logout ส่วนกลางเสร็จ
$return_url = 'https://www.medsci.up.ac.th/your_app/login.php';

// 3. Redirect ไปยัง Single Logout Endpoint ของระบบกลาง
$sso_logout_url = 'https://www.medsci.up.ac.th/msc_acc/sso/logout.php' .
                  '?redirect_uri=' . urlencode($return_url);

header("Location: " . $sso_logout_url);
exit();
?>
```

---

## 🔒 ข้อควรทราบด้านความปลอดภัย (Security Rules)

1. **อายุของโทเคน (Token Lifetime)**: โทเคนที่ส่งผ่าน URL มีอายุการใช้งานเพียง **120 วินาที (2 นาที)** และต้องนำไปเรียก Verify กับ API ทันที
2. **การใช้งานครั้งเดียว (Single-Use Token)**: โทเคนสามารถนำไป Verify ได้เพียง **1 ครั้งเท่านั้น** หากนำโทเคนเดิมมายิงซ้ำ ระบบกลางจะปฏิเสธคำขอทันที
3. **การ Redirect หนีจากหน้า Callback**: เมื่อ Verify โทเคนสำเร็จ ต้องทำการ `header("Location: index.php");` ออกจากหน้า `sso_callback.php` เสมอ เพื่อไม่ให้ URL ที่มี `?token=...` ค้างอยู่ใน History ของเบราว์เซอร์
4. **Single Logout (SLO)**: แนะนำให้ระบบย่อยส่งผู้ใช้ไปยัง `sso/logout.php` เสมอ เพื่อให้การ Logout มีผลทั้งระบบย่อยและระบบกลาง ไม่เกิดปัญหาค้าง Session ส่วนกลาง

---

## 📁 ไฟล์ตัวอย่างและคู่มือฉบับเต็ม
- **โค้ดตัวอย่างที่ทดสอบได้จริง**: โฟลเดอร์ `client_sample/` ในระบบกลาง
- **คู่มือฉบับเต็ม**: ไฟล์ `sso_integration_guide.md`

