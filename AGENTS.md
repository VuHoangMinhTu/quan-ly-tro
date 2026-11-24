# BeeHouse — Hướng dẫn cho AI/Codex

Áp dụng cho toàn bộ repository. Tài liệu nghiệp vụ chính: [docs/BUSINESS_RULES.md](docs/BUSINESS_RULES.md).

## 1. Trước khi trả lời hoặc sửa code
- Đọc quy tắc nghiệp vụ liên quan, README, tài liệu kiến trúc và hướng dẫn AGENTS.md ở thư mục con (nếu có).
- Xác định branch, commit, git status và diff local. Không giả định GitHub trùng với máy local hoặc production. Nếu chỉ đọc được GitHub, ghi rõ revision đã xem; không khẳng định các hotfix local đã được kiểm tra.
- Truy vết luồng thật: route/UI → API → controller/request validation → service/model → database → test. Đọc cả các nơi gọi và tác động của thay đổi. Không chỉ dựa trên tên file hoặc suy luận từ nghiệp vụ thông thường.
- Phân biệt: hành vi hiện tại có bằng chứng trong code; quy tắc chủ dự án đã chốt; đề xuất chưa được chốt; phần chưa kiểm chứng. Nếu tài liệu và code khác nhau, chỉ rõ sự khác biệt và không tự chọn một bên để thay đổi nghiệp vụ.

## 2. Khi triển khai
- Chủ dự án quyết định nghiệp vụ. Không tự đổi điều kiện hợp đồng, chu kỳ, giá, số tiền, trạng thái hay dữ liệu lịch sử để làm UI dễ hơn.
- Ưu tiên thay đổi nhỏ đúng phạm vi; bảo toàn phân quyền theo nhà trọ, dữ liệu tenant, hóa đơn, tiền cọc và mốc công tơ. Không ghi trùng, không bỏ kiểm tra backend để lách lỗi frontend.
- Mọi thay đổi dữ liệu tài chính cần xác định ảnh hưởng đến hợp đồng, ghi điện nước, hóa đơn, thanh toán, trả phòng và gia hạn. Không chạy migration phá hủy dữ liệu, reset/seed hoặc chỉnh dữ liệu production nếu chưa có yêu cầu rõ ràng.
- Khi đóng gói patch local: kiểm tra source trước khi ghi, sao lưu file, dừng nếu không khớp, nêu file thay đổi. Không sửa mù bằng regex trên source khác phiên bản.
- Kiểm tra phù hợp phạm vi (unit/feature, frontend build/lint, luồng UI, regression). Nếu chủ dự án yêu cầu bỏ test, ghi rõ chưa chạy test; không tuyên bố PASS. Không chạy test với database production.

## 3. Cập nhật tài liệu và bàn giao
- Khi chủ dự án chốt quy tắc mới, cập nhật BUSINESS_RULES.md với mã quy tắc, điều kiện, ngoại lệ, trạng thái triển khai và nguồn kiểm chứng. Không đánh dấu đã triển khai/đã test nếu thiếu bằng chứng.
- Nếu phát hiện mâu thuẫn hoặc chưa rõ điều kiện nghiệp vụ, ghi vào phần cần xác minh và hỏi khi điều đó ảnh hưởng quyết định triển khai. Không thay đổi quy tắc đã chốt chỉ để làm cho code hiện tại có vẻ đúng.
- Khi hoàn thành, báo cáo ngắn: quy tắc áp dụng, file đã sửa, thay đổi thực tế, test đã chạy/chưa chạy, rủi ro và bước kiểm tra. Cập nhật tài liệu cùng commit nếu có thay đổi nghiệp vụ.
- Không tự push, merge hoặc deploy production chỉ vì đã sửa code; xác nhận phạm vi triển khai và các kiểm tra cần thiết theo yêu cầu của chủ dự án.
