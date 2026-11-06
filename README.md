# Hệ thống ticket nội bộ (PHP 8 + XAMPP)

Ứng dụng PHP thuần, MySQL/MariaDB qua PDO, giao diện UTF-8 responsive. Đặt thư mục này tại `C:\xampp\htdocs\ticket` để dùng tại `http://localhost/ticket/`.
Đường dẫn ứng dụng được tự nhận diện theo vị trí thư mục dưới `htdocs`, nên cũng có thể chạy từ tên thư mục khác.

## 1. Cài đặt với XAMPP

1. Cài XAMPP có PHP 8.x, Apache và MySQL/MariaDB. Mở XAMPP Control Panel, nhấn **Start** tại Apache và MySQL.
2. Sao chép toàn bộ thư mục `ticket` vào `C:\xampp\htdocs\ticket`.
3. Mở `http://localhost/phpmyadmin`, chọn **Import**, chọn `database.sql`, rồi nhấn **Import/Go**. SQL tạo database `internal_tickets` và các bảng cần thiết.
4. Mở `config.php`, cập nhật `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` theo cấu hình MySQL trên máy. Đổi `APP_KEY` thành chuỗi bí mật ngẫu nhiên dài tối thiểu 32 ký tự. Ví dụ tạo bằng PHP CLI: `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`.
5. Đảm bảo thư mục `uploads` có quyền ghi bởi Apache. Tệp được lưu trong thư mục này khi gửi ticket và admin có thể xem ảnh trong chi tiết ticket; truy cập web trực tiếp vào thư mục upload bị chặn. Giữ `uploads/.htaccess` đúng chỗ, **không** chép ra thư mục gốc `ticket` (nó chặn mọi file PHP). Sau khi import xong, xóa hoặc chuyển `database.sql` ra ngoài `htdocs`.
6. Truy cập `http://localhost/ticket/` để gửi ticket; trang quản trị tại `http://localhost/ticket/admin/`.
7. Đăng nhập ban đầu: **admin / ChangeMe123!**. Vào **Tài khoản**, đổi mật khẩu admin sang mật khẩu riêng mạnh và không dùng lại mật khẩu này. Tạo thêm tài khoản staff nếu cần.
8. Nếu nâng cấp cơ sở dữ liệu đã có, chạy `migration_ticket_device_types.sql` trong phpMyAdmin để cho phép các loại thiết bị mới mà vẫn giữ nguyên loại của ticket cũ; chạy thêm `migration_ticket_meeting_room.sql` để cho phép loại **Trực phòng họp**.
9. Để nâng cấp phân quyền, bảo đảm tài khoản `thuanpt` tồn tại (nếu chưa có, đăng nhập bằng `admin` và tạo tài khoản này trước), rồi chạy `migration_super_admin.sql` và `migration_rbac_permissions.sql`. `migration_super_admin.sql` đặt `thuanpt` làm Super Admin và hạ các Super Admin khác xuống Admin; migration RBAC tạo cấu hình quyền mặc định. Chỉ `thuanpt` mới được gán vai trò và cấu hình quyền tại mục **Phân quyền**; các tài khoản cùng vai trò dùng chung quyền. Quyền của tài khoản `thuanpt` không thể bị thay đổi hoặc tài khoản bị xóa qua ứng dụng. Các quyền được kiểm tra ở phía máy chủ. Việc sửa trực tiếp cơ sở dữ liệu vẫn nằm ngoài quyền kiểm soát của ứng dụng.
10. Để lưu nội dung trước/sau đầy đủ trong lịch sử chỉnh sửa ticket, chạy `migration_ticket_history_details.sql` trên database hiện có. Các database cài mới đã dùng cột `LONGTEXT`.
11. Để đổi mã các ticket hiện có sang số thứ tự theo ID, chạy `migration_numeric_ticket_codes.sql`.
12. Để bật nhắc ticket quá hạn, chạy `migration_ticket_reminders.sql`, sau đó tạo tác vụ trong Windows Task Scheduler chạy mỗi 5 phút:
    `C:\xampp\php\php.exe C:\xampp\htdocs\ticket1\tools\ticket_reminders.php`
    Chương trình nhắc lại ticket còn trạng thái **Mới**: ưu tiên Cao mỗi 2 giờ, Trung bình mỗi 4 giờ; dừng khi ticket được chuyển sang trạng thái khác. Có thể kiểm tra an toàn bằng tham số `--dry-run`.
13. Để bật phân loại ưu tiên và SLA, chạy `migration_ticket_priority_sla.sql`. Tạo thêm tác vụ Windows Task Scheduler chạy mỗi 5 phút:
    `C:\xampp\php\php.exe C:\xampp\htdocs\ticket1\tools\ticket_sla_escalations.php`
    Dùng tham số `--dry-run` để xem ticket sẽ bị nâng mức mà không ghi dữ liệu. Ticket mới được chấm tự động: phạm vi cá nhân/phòng/toàn công ty lần lượt 0/3/6 điểm; khả năng làm việc đầy đủ/một phần/không thể lần lượt 0/3/6; mạng +3, PC/Laptop/Máy in +2, phòng IT +2; từ khóa khẩn cấp/gián đoạn/bảo mật +2 mỗi nhóm (tối đa +4). Từ 10 điểm là mức Cao, 5–9 là Trung bình, dưới 5 là Thấp. SLA lần lượt là Cao: phản hồi 15 phút/xử lý 2 giờ; Trung bình: 30 phút/xử lý 4 giờ; Thấp: 60 phút/xử lý 6 giờ. Khi quá hạn phản hồi, mức tăng một bậc; quá hạn xử lý tăng thêm một bậc, tối đa Cao. Kỹ thuật viên có thể chỉnh mức ưu tiên thủ công trong chi tiết ticket.
14. Để lưu nhiều ảnh/tệp đính kèm cho một ticket, chạy `migration_multiple_ticket_attachments.sql`. Form cho phép dán nhiều ảnh bằng Ctrl+V hoặc chọn tối đa 5 tệp; mỗi tệp tối đa 5MB.

Không nên để `APP_KEY` ở giá trị mẫu, mở phpMyAdmin ra mạng LAN, hoặc triển khai với thông tin xác thực mặc định.

## 2. Email thông báo Office 365 (SMTP) — đang dùng

Thư viện PHPMailer 7.x đã có sẵn trong `includes/PHPMailer/` — **không cần cài Composer**.

**Email được gửi tự động khi:**
- Có ticket mới → quản lý trong `MANAGER_RECIPIENTS['all']` + quản lý theo loại ticket. Ticket ưu tiên Cao có tiêu đề `[KHẨN]`.
- Ticket đổi trạng thái / mức ưu tiên / người xử lý → cùng nhóm quản lý (chỉ sửa ghi chú thì không gửi).

### 2.1. Điền `config.php`

```php
const APP_BASE_URL = 'http://192.168.1.10';         // IP máy chủ XAMPP — để link "Mở ticket" bấm được
const MAIL_DRIVER  = 'smtp';                         // đã đặt sẵn
const SMTP_USER    = 'ticket@ten-mien-cong-ty.vn';   // email Office 365 dùng để gửi
const SMTP_PASS    = 'mat-khau-email';
const MANAGER_RECIPIENTS = [
    'all'        => ['truongphong@ten-mien-cong-ty.vn'],   // nhận mọi ticket
    'it'         => ['it.lead@ten-mien-cong-ty.vn'],       // nhận thêm ticket loại IT
    'hr'         => [], 'admin' => [], 'other' => [],
];
```

`SMTP_HOST = smtp.office365.com`, `SMTP_PORT = 587` đã đặt sẵn. Email người gửi (From) tự động là `SMTP_USER` — Office 365 bắt buộc như vậy.

### 2.2. Bật Authenticated SMTP cho hộp thư gửi (Admin Microsoft 365)

1. https://admin.microsoft.com → **Users → Active users** → chọn hộp thư gửi → tab **Mail** → **Manage email apps** → tick **Authenticated SMTP** → Save.
2. Exchange admin center → **Settings → Mail flow** → bỏ tick **Turn off SMTP AUTH protocol for your organization** (nếu đang bật).
3. Entra ID → **Overview → Properties → Manage security defaults**: nếu **Security defaults = Enabled**, đăng nhập SMTP bằng mật khẩu sẽ bị chặn. Trường hợp này dùng mục 2b (Graph) thay vì tắt security defaults.

Thay đổi có thể mất 15–60 phút để có hiệu lực.

### 2.3. Gửi thử

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\ticket\tools\test_mail.php ban@ten-mien-cong-ty.vn
# Khi lỗi, xem chi tiết hội thoại SMTP:
C:\xampp\php\php.exe C:\xampp\htdocs\ticket\tools\test_mail.php ban@ten-mien-cong-ty.vn --debug
```

| Thông báo lỗi | Nguyên nhân / cách xử lý |
|---|---|
| `SMTP_USER / SMTP_PASS chưa điền` | Điền 2 dòng này trong `config.php`. |
| `Could not authenticate` / `535 5.7.3` | Sai email/mật khẩu, hoặc tài khoản bị chặn đăng nhập. |
| `5.7.139 ... SmtpClientAuthentication is disabled` | Chưa làm bước 2.2 (mục 1 hoặc 2). |
| `5.7.139 ... security defaults` hoặc `5.7.30 Basic authentication is not supported` | Tenant chặn đăng nhập SMTP bằng mật khẩu → chuyển sang mục 2b. |
| `Could not connect to SMTP host` | Firewall/mạng chặn cổng TCP 587 ra ngoài. |
| `5.7.60 ... not allowed to send as` | `SMTP_FROM_EMAIL` khác `SMTP_USER` — để trống `SMTP_FROM_EMAIL`. |

Mọi lỗi gửi mail được ghi vào PHP error log (`C:\xampp\php\logs\php_error_log`); ticket vẫn được tạo/cập nhật bình thường.

**Lưu ý thời hạn:** Microsoft sẽ tắt mặc định đăng nhập SMTP bằng mật khẩu (Basic Auth) cho tenant hiện có từ cuối tháng 12/2026; admin vẫn bật lại được, việc gỡ bỏ hẳn sẽ được công bố trong nửa cuối 2027. Khi đó chuyển sang mục 2b.

**Bảo mật:** `config.php` chứa mật khẩu email — không gửi file này qua chat/email, không đưa lên Git. Nên dùng một hộp thư riêng cho hệ thống (shared mailbox có đặt mật khẩu hoặc tài khoản chuyên gửi), không dùng email cá nhân.

## 2b. Dự phòng: Office 365 qua Microsoft Graph (OAuth)

Dùng khi Microsoft tắt SMTP Basic Auth cho tenant (mục 2 ngừng chạy, báo lỗi `5.7.139` / `5.7.30`). Chỉ cần đổi `MAIL_DRIVER = 'graph'` và làm các bước dưới; phần thông báo giữ nguyên.

Hệ thống gửi email nội bộ qua Microsoft Graph bằng OAuth (không dùng mật khẩu hộp thư, không phụ thuộc SMTP AUTH Basic). Không cần cài PHPMailer; chỉ cần PHP extension `curl` (XAMPP bật sẵn — kiểm tra `extension=curl` trong `php.ini`).

### 2b.1. Tạo hộp thư gửi
Dùng một **shared mailbox** (không tốn license), ví dụ `ticket@ten-mien-cong-ty.vn`.

### 2b.2. Đăng ký ứng dụng trên Entra ID
1. https://entra.microsoft.com → **App registrations** → **New registration** → tên `Ticket Mailer`, Single tenant → Register.
2. Ghi lại **Application (client) ID** và **Directory (tenant) ID**.
3. **Certificates & secrets** → **New client secret** → copy cột **Value** ngay (chỉ hiện một lần). Ghi chú ngày hết hạn để gia hạn.

### 2b.3. Cấp quyền gửi mail — CHỈ cho hộp thư ticket (khuyến nghị)
Dùng RBAC for Applications của Exchange Online để app chỉ gửi được từ đúng một hộp thư. **Không** cấp thêm quyền `Mail.Send` (Application) trong Entra — nếu cấp, app gửi được thay mọi hộp thư trong tenant.

Mở PowerShell với quyền Exchange Admin:

```powershell
Install-Module ExchangeOnlineManagement   # nếu chưa có
Connect-ExchangeOnline

# AppId = Application (client) ID; ObjectId = Object ID ở mục Enterprise applications (không phải App registrations)
New-ServicePrincipal -AppId <AppId> -ObjectId <EnterpriseAppObjectId> -DisplayName "Ticket Mailer"

New-ManagementScope -Name "Ticket Mailer Scope" -RecipientRestrictionFilter "PrimarySmtpAddress -eq 'ticket@ten-mien-cong-ty.vn'"

New-ManagementRoleAssignment -App <AppId> -Role "Application Mail.Send" -CustomResourceScope "Ticket Mailer Scope"

# Kiểm tra: InScope = True với hộp thư ticket, False với hộp thư khác
Test-ServicePrincipalAuthorization -Identity <AppId> -Resource ticket@ten-mien-cong-ty.vn
```

Quyền có thể mất 30 phút – 2 giờ để có hiệu lực.

*Cách nhanh (kém an toàn hơn):* Entra → app → **API permissions** → Microsoft Graph → **Application permissions** → `Mail.Send` → **Grant admin consent**. App khi đó gửi được dưới danh nghĩa mọi hộp thư.

### 2b.4. Cấu hình `config.php`

```php
const MAIL_DRIVER = 'graph';
const M365_TENANT_ID = '<Directory (tenant) ID>';
const M365_CLIENT_ID = '<Application (client) ID>';
const M365_CLIENT_SECRET = '<Client secret Value>';
const M365_SENDER = 'ticket@ten-mien-cong-ty.vn';
```

### 2b.5. Kiểm tra

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\ticket\tools\test_mail.php ban@ten-mien-cong-ty.vn
```

| Lỗi | Nguyên nhân / cách xử lý |
|---|---|
| `SSL certificate problem` | Thêm `curl.cainfo="C:\xampp\apache\bin\curl-ca-bundle.crt"` vào `php.ini` rồi restart Apache, hoặc điền `MAIL_CA_BUNDLE`. |
| `invalid_client` / `AADSTS7000215` | Client secret sai — phải dùng cột **Value**, không phải Secret ID; hoặc secret hết hạn. |
| `ErrorAccessDenied` / HTTP 403 | Chưa gán quyền (bước 2b.3), quyền chưa có hiệu lực, hoặc `M365_SENDER` nằm ngoài scope. |
| `ErrorInvalidUser` / HTTP 404 | `M365_SENDER` không tồn tại trong tenant. |
| Không có lỗi nhưng không thấy mail | Kiểm tra Junk / Quarantine; kiểm tra transport rule lọc nội dung. |

Mọi lỗi gửi mail được ghi vào PHP error log (`C:\xampp\php\logs\php_error_log`); ticket vẫn được tạo/cập nhật bình thường.

## 3. Mạng LAN

1. Xem IPv4 của máy chủ bằng `ipconfig`, ví dụ `192.168.1.10`.
2. Trên máy nhân viên truy cập `http://192.168.1.10/ticket/`. Apache phải lắng nghe trên giao diện mạng; cấu hình mặc định XAMPP thường đáp ứng việc này.
3. Cho phép Apache HTTP Server hoặc cổng TCP 80 (và 443 nếu dùng HTTPS) trong Windows Defender Firewall, ưu tiên quy tắc chỉ áp dụng cho mạng Private/Domain của công ty.
4. Để link email dùng địa chỉ LAN thay vì `localhost`, đặt `APP_BASE_URL = 'http://192.168.1.10'` trong `config.php` (không thêm `/ticket` vào giá trị này).
5. Nên dùng HTTPS trong mạng công ty nếu thông tin ticket nhạy cảm. Không chuyển tiếp cổng XAMPP/phpMyAdmin trực tiếp ra Internet.

## 4. Telegram (tùy chọn)

Đặt `TELEGRAM_ENABLED = true`, điền `TELEGRAM_BOT_TOKEN` và `TELEGRAM_CHAT_ID`. Tạo bot qua BotFather, lấy chat ID của nơi nhận thông báo. Cần PHP cho phép kết nối HTTPS outbound. Nếu Telegram lỗi, lỗi được ghi vào PHP error log; ticket vẫn được tạo.

## 5. Kiểm tra sau khi cài

- [ ] Trang gửi ticket mở trên desktop và điện thoại, tiếng Việt hiển thị đúng.
- [ ] Gửi ticket không có tệp và nhận mã dạng số thứ tự, ví dụ `123`.
- [ ] Chạy `tools/test_mail.php` thành công; gửi ticket thử → quản lý nhận email "[Ticket mới]".
- [ ] Đổi trạng thái ticket → quản lý nhận email "[Cập nhật]".
- [ ] Admin nhận thông báo mới trong khu quản trị (polling 10 giây), số badge cập nhật và có âm báo.
- [ ] Tra cứu ticket chỉ bằng mã số, ví dụ `123`.
- [ ] Admin đổi trạng thái, mức ưu tiên, người xử lý, ghi chú; kiểm tra lịch sử và ghi chú phía tra cứu.
- [ ] Thử lọc, phân trang và xuất CSV; mở CSV bằng Excel để xác nhận tiếng Việt.
- [ ] Thử tải lên JPG/PNG/PDF/DOCX/XLSX hợp lệ dưới 5MB; thử tệp lớn và đuôi không cho phép.
- [ ] Thử sai mật khẩu admin 5 lần để kiểm tra khóa tạm 15 phút.
- [ ] Tạo tài khoản staff; xác nhận staff xử lý ticket được nhưng không mở được quản lý tài khoản.
- [ ] Dùng thiết bị khác trong mạng LAN để truy cập và gửi ticket.

## Ghi chú triển khai

- PDO dùng prepared statements; nội dung xuất HTML được escape; form có CSRF; upload giới hạn 5MB, kiểm tra MIME/đuôi và đổi tên ngẫu nhiên. Tệp được tải qua endpoint yêu cầu đăng nhập admin.
- Giới hạn gửi là 5 ticket mỗi giờ theo IP (lưu HMAC của IP thay vì IP thô). Honeypot được dùng cho form công khai.
- Login admin bị khóa 15 phút sau 5 lần sai trên cùng tên đăng nhập/IP. Super Admin `thuanpt` quản lý vai trò; Admin quản lý tài khoản thông thường và ticket; Staff chỉ đổi trạng thái ticket, ghi chú phản hồi và không thể sửa/xóa ticket.
- Nếu Apache không áp dụng `.htaccess`, hãy cấu hình tương đương tại VirtualHost và đảm bảo thư mục tải lên không thể thực thi PHP.
- `PRODUCTION_MODE` tắt hiển thị lỗi PHP và bật ghi log. Kiểm tra PHP error log của XAMPP khi cần xử lý lỗi cấu hình.
