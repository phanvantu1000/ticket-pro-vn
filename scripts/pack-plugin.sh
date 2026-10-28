#!/usr/bin/env bash
# ==============================================================================
# Script đóng gói plugin WordPress Ticket Pro VN sẵn sàng nộp lên WordPress.org
# ==============================================================================

set -e

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="$DIR/ticket-pro-vn"
DIST_DIR="$DIR/dist-wp-org"
ZIP_NAME="ticket-pro-vn.zip"

echo "=== 1. Kiểm tra tính toàn vẹn cú pháp PHP ==="
find "$PLUGIN_DIR" -name "*.php" | while read -r file; do
    php -l "$file" > /dev/null || { echo "LỖI cú pháp tại: $file"; exit 1; }
done
echo "-> Tất cả file PHP đều hợp lệ."

echo "=== 2. Kiểm tra file bắt buộc của WordPress.org ==="
if [ ! -f "$PLUGIN_DIR/readme.txt" ]; then
    echo "LỖI: Thiếu file readme.txt trong thư mục plugin!"
    exit 1
fi
if [ ! -f "$PLUGIN_DIR/ticket-pro-vn.php" ]; then
    echo "LỖI: Thiếu file plugin chính ticket-pro-vn.php!"
    exit 1
fi
echo "-> Các file cốt lõi đã đầy đủ."

echo "=== 3. Dọn dẹp file rác hệ thống (.DS_Store, backup files) ==="
find "$PLUGIN_DIR" -name ".DS_Store" -delete || true
find "$PLUGIN_DIR" -name "*~" -delete || true

echo "=== 4. Tạo thư mục output và đóng gói file ZIP ==="
mkdir -p "$DIST_DIR"
cd "$DIR"

# Đóng gói thư mục ticket-pro-vn vào zip
rm -f "$DIST_DIR/$ZIP_NAME"
zip -r -q -X "$DIST_DIR/$ZIP_NAME" ticket-pro-vn -x "*.DS_Store" "*__MACOSX*"

echo "-> File nộp WordPress.org đã được tạo tại: $DIST_DIR/$ZIP_NAME"
echo "Kích thước: $(ls -lh "$DIST_DIR/$ZIP_NAME" | awk '{print $5}')"
echo "=============================================================================="
echo "SẴN SÀNG! Bạn có thể tải file này lên: https://wordpress.org/plugins/developers/add/"
echo "=============================================================================="
