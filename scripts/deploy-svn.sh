#!/usr/bin/env bash
# ==============================================================================
# Script hỗ trợ đẩy bản phát hành lên WordPress.org SVN Repository
# Sử dụng khi plugin của bạn ĐÃ ĐƯỢC DUYỆT và bạn nhận được SVN URL từ WordPress.org
# ==============================================================================

set -e

PLUGIN_SLUG="ticket-pro-vn"
SVN_URL="https://plugins.svn.wordpress.org/$PLUGIN_SLUG"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SVN_CHECKOUT_DIR="$DIR/.svn-repo"

echo "=== KIỂM TRA SVN CLI TRÊN MACOS ==="
if ! command -v svn &> /dev/null; then
    echo "LỖI: Chưa cài đặt 'svn' trên macOS."
    echo "Hãy chạy lệnh sau để cài đặt qua Homebrew:"
    echo "    brew install subversion"
    exit 1
fi

echo "Nhập WordPress.org Username của bạn:"
read -r WP_USERNAME

if [ -z "$WP_USERNAME" ]; then
    echo "Username không được để trống!"
    exit 1
fi

# Đọc version hiện tại từ ticket-pro-vn.php
VERSION=$(grep "Version:" "$DIR/ticket-pro-vn/ticket-pro-vn.php" | head -n 1 | awk '{print $NF}')
echo "Phiên bản phát hiện: $VERSION"

echo "=== CHECKOUT REPOSITORY TỪ WORDPRESS.ORG ==="
if [ ! -d "$SVN_CHECKOUT_DIR/.svn" ]; then
    rm -rf "$SVN_CHECKOUT_DIR"
    svn co "$SVN_URL" "$SVN_CHECKOUT_DIR" --username "$WP_USERNAME"
else
    echo "Đang cập nhật SVN repo..."
    svn up "$SVN_CHECKOUT_DIR" --username "$WP_USERNAME"
fi

echo "=== ĐỒNG BỘ CODE VÀO TRUNK ==="
# Đồng bộ code plugin vào trunk, loại trừ file rác
rsync -av --delete --exclude=".DS_Store" --exclude="*.pem" "$DIR/ticket-pro-vn/" "$SVN_CHECKOUT_DIR/trunk/"

echo "=== ĐỒNG BỘ ASSETS (BANNER, ICON, SCREENSHOTS) ==="
if [ -d "$DIR/wp-org-assets" ]; then
    mkdir -p "$SVN_CHECKOUT_DIR/assets"
    rsync -av --exclude="README.md" --exclude=".DS_Store" "$DIR/wp-org-assets/" "$SVN_CHECKOUT_DIR/assets/"
fi

echo "=== TẠO TAG RELEASE $VERSION ==="
mkdir -p "$SVN_CHECKOUT_DIR/tags/$VERSION"
rsync -av --delete --exclude=".DS_Store" --exclude="*.pem" "$DIR/ticket-pro-vn/" "$SVN_CHECKOUT_DIR/tags/$VERSION/"

cd "$SVN_CHECKOUT_DIR"

echo "=== ĐĂNG KÝ CÁC FILE MỚI VỚI SVN ==="
svn status | grep '^?' | awk '{print $2}' | xargs -I {} svn add {} 2>/dev/null || true
svn status | grep '^!' | awk '{print $2}' | xargs -I {} svn rm {} 2>/dev/null || true

echo "=== TRẠNG THÁI SVN TRƯỚC KHI COMMIT ==="
svn status

echo ""
read -p "Bạn có chắc chắn muốn COMMIT và xuất bản phiên bản $VERSION lên WordPress.org? (y/N): " CONFIRM
if [[ "$CONFIRM" =~ ^[Yy]$ ]]; then
    svn ci -m "Release version $VERSION" --username "$WP_USERNAME"
    echo "===================================================================="
    echo "THÀNH CÔNG! Phiên bản $VERSION đã được đẩy lên WordPress.org."
    echo "Sau khoảng 5-15 phút, trang plugin của bạn sẽ cập nhật tại:"
    echo "https://wordpress.org/plugins/$PLUGIN_SLUG/"
    echo "===================================================================="
else
    echo "Hủy bỏ commit."
fi
