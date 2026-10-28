# 🎫 Ticket Pro VN

> **Hệ thống Quản lý Vé Hỗ Trợ Khách Hàng Chuyên Nghiệp Dành Riêng Cho WordPress**

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-GPLv2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Multilingual](https://img.shields.io/badge/Languages-VI%20%7C%20EN-orange.svg)](#ngôn-ngữ)

---

## 🌟 Giới Thiệu

**Ticket Pro VN** là giải pháp hỗ trợ khách hàng toàn diện, gọn nhẹ và bảo mật cao, được thiết kế tối ưu cho các doanh nghiệp, cửa hàng WooCommerce và website dịch vụ tại Việt Nam và quốc tế.

Hệ thống hoạt động theo mô hình **Freemium chuẩn WordPress.org**:
* **Bản Miễn Phí (Free Edition):** Đầy đủ các tính năng gửi vé, xử lý hội thoại, phân quyền người dùng và tích hợp WooCommerce trực tiếp trên trang web.
* **Bản Mở Rộng (Pro Edition):** Kích hoạt bằng License Key để mở khóa tải tệp đính kèm an toàn (WebP/PDF/Office), bộ lọc chặn từ khóa spam, phân công nhân viên xử lý vé và chặn IP phá hoại.

---

## 📁 Cấu Trúc Dự Án (Repository Structure)

```text
code-plugin-ticket-bac-Tu/
├── ticket-pro-vn/              # Mã nguồn WordPress Plugin (sẵn sàng cho WordPress.org)
│   ├── assets/                 # CSS và Javascript giao diện Frontend / Admin
│   ├── includes/               # Các lớp xử lý logic (DB, Security, License, Admin, Front)
│   ├── languages/              # Gói ngôn ngữ song ngữ Tiếng Việt (.po, .mo) và Tiếng Anh
│   ├── readme.txt              # File định danh chuẩn theo thông số WordPress.org
│   └── ticket-pro-vn.php       # File khởi động chính của Plugin
├── license-server/             # Máy chủ cấp phát & xác thực License Key độc lập (PHP 8.2+)
│   ├── public/                 # Document root chứa API endpoint và Dashboard quản trị
│   ├── src/                    # Logic cấp phát, mã hóa AES-256-GCM và chữ ký số RSA
│   ├── schema.sql              # Cơ sở dữ liệu SQLite / MySQL cho máy chủ License
│   └── README.md               # Hướng dẫn triển khai riêng cho License Server
├── scripts/                    # Bộ công cụ tự động hóa đa nền tảng
│   ├── pack-plugin.sh          # Đóng gói zip chuẩn nộp WordPress.org (macOS / Linux)
│   ├── pack-plugin.ps1         # Đóng gói zip chuẩn nộp WordPress.org (Windows PowerShell)
│   ├── deploy-svn.sh           # Tự động đẩy release lên WordPress.org SVN (macOS / Linux)
│   └── deploy-svn.ps1          # Tự động đẩy release lên WordPress.org SVN (Windows PowerShell)
├── wp-org-assets/              # Thư mục chuẩn bị Banner, Icon, Screenshots cho WordPress.org
├── tests/                      # Bộ kiểm thử tự động cú pháp và logic plugin
├── .gitignore                  # Cấu hình loại trừ file rác và thông tin bí mật
└── README.md                   # Tài liệu tổng quan dự án
```

---

## 🚀 Hướng Dẫn Phát Triển & Đóng Gói

### 1. Kiểm thử logic nội bộ
Chạy script kiểm tra cú pháp và logic kiểm duyệt:
```bash
php tests/plugin-logic-test.php
```

### 2. Đóng gói bản phát hành để nộp WordPress.org
* **Trên Windows (PowerShell):**
  ```powershell
  powershell -ExecutionPolicy Bypass -File .\scripts\pack-plugin.ps1
  ```
* **Trên macOS / Linux (Terminal):**
  ```bash
  ./scripts/pack-plugin.sh
  ```
File zip chuẩn sạch sẽ được xuất tại thư mục: `dist-wp-org/ticket-pro-vn.zip`.

---

## 🛡️ Bản Quyền & Giấy Phép (License)

* Toàn bộ mã nguồn Plugin WordPress được phát hành theo giấy phép **GNU General Public License v2 hoặc mới hơn (GPLv2+)**, hoàn toàn tương thích với hệ sinh thái WordPress.org.
* Thông tin hỗ trợ và bản quyền phát triển bởi **Ticket Pro VN Team** / **[OSB.VN](https://osb.vn)**.
