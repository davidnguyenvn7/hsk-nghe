# HSK Nghe Thụ Động — plugin WordPress

Nghe thụ động từ vựng HSK 3.0 cấp 2–3 và mẫu câu theo chủ đề công việc (bất động sản, văn phòng, ngân hàng, xây dựng, giao thông), cho người Việt học tiếng Trung.

- **Trình phát mobile**: mỗi bài là một file MP3 do server ghép sẵn theo cài đặt, nên vẫn phát khi khoá màn hình. Có điều khiển trên màn hình khoá và tai nghe, tự chuyển sang bài kế tiếp.
- **Thứ tự nghe tuỳ chỉnh**: từ × N lần → nghĩa (Việt / Anh / không đọc) → mỗi câu: câu → nghĩa → câu chậm × (M − 1).
- **Thêm nội dung bằng DeepSeek** ngay trong WP Admin: chủ đề mới, từ mới, câu theo từ. Câu sinh ra được kiểm tra chỉ dùng từ HSK 1–3, rồi bạn duyệt trước khi lưu.
- **Quản lý nội dung**: sửa và ẩn chủ đề, từ, câu. Giọng đọc (Azure Speech) được tạo lại cho đúng phần đã sửa.

## Cài đặt

1. Copy thư mục này vào `wp-content/plugins/hsk-nghe/` rồi kích hoạt plugin. Yêu cầu PHP 8.1+, WordPress 6.4+.
2. **HSK Nghe → Import**: import gói nội dung `hskn-pack-*.zip` (tạo bằng repo [hsk-nghe-thu-dong](https://github.com/davidnguyenvn7/hsk-nghe-thu-dong)). Gói lớn thì upload vào `wp-content/uploads/hskn/incoming/` qua SFTP hoặc File Manager.
3. **HSK Nghe → Cài đặt**: nhập DeepSeek API key, Azure Speech key + region, và chọn cách đọc mặc định.
4. Tạo một trang có shortcode `[hskn_player]`.

## Shortcode

```
[hskn_player]                    thư viện bài nghe + trình phát (đọc ?track= trên URL)
[hskn_player track="topic-03"]   mở sẵn một bài
[hskn_player group="03"]         chỉ hiện bài của chủ đề 03 (group="hsk2" | "hsk3")
```

## WP-CLI

```
wp hskn import <zip|thư mục> [--dry-run] [--skip-audio]
wp hskn status
wp hskn verify-audio
wp hskn clear-cache
```

## Cấu trúc

```
src/Install     bảng hskn_* (dbDelta)
src/Import      import gói nội dung theo lô (CLI + Admin)
src/Service     danh sách bài, quy tắc ghép playlist
src/Audio       đọc / ghép frame MP3, cache bài nghe đã ghép
src/Author      DeepSeek, kiểm tra câu, lưu nội dung tạo trong WP, giọng đọc
src/Rest        REST API hskn/v1
src/Admin       trang quản trị
assets/         trình phát (JS thuần) + giao diện Admin
data/           danh sách cấp HSK 1–3 (MIT, drkameleon/complete-hsk-vocabulary)
```

Đặc tả chi tiết: `docs/wordpress-plugin.md` trong repo nội dung.
