<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class ContractTemplateRenderer
{
    public function __construct(private ContractTemplateHtml $html, private ContractTemplateVariableRegistry $registry) {}

    public function prepare(string $content): string
    {
        $clean = $this->html->clean($content);
        $allowed = array_column($this->registry->definitions(), 'key');
        preg_match_all('/\{\{(.*?)\}\}/s', $clean, $matches);
        foreach ($matches[1] as $key) {
            if (! in_array(trim($key), $allowed, true)) {
                throw ValidationException::withMessages(['content_html' => 'Biến {{'.trim($key).'}} không được hỗ trợ.']);
            }
        }
        $withoutVariables = preg_replace('/\{\{(.*?)\}\}/s', '', $clean);
        if (str_contains($withoutVariables, '{{') || str_contains($withoutVariables, '}}')) {
            throw ValidationException::withMessages(['content_html' => 'Cú pháp biến không hợp lệ. Vui lòng dùng {{ten_bien}} trong danh sách hỗ trợ.']);
        }

        return $clean;
    }

    public function render(string $content, array $snapshot): string
    {
        $content = $this->prepare($content);
        $values = $this->registry->values($snapshot);
        $blocks = ['services_table' => $this->services($snapshot['services'] ?? []), 'room_rental_services' => $this->roomRentalServices($snapshot['services'] ?? []), 'occupants_table' => $this->occupants($snapshot['occupants'] ?? []),
            'vehicles_table' => $this->vehicles($snapshot['vehicles'] ?? []), 'handover_checkin_table' => $this->handover($snapshot['handover_checkin'] ?? [])];

        $rendered = preg_replace_callback('/\{\{(.*?)\}\}/s', fn ($match) => $blocks[trim($match[1])] ?? $this->escape($values[trim($match[1])]), $content);

        $type = str_contains($content, 'class="word-contract"') ? ($snapshot['contract']['contract_type'] ?? null) : null;

        return str_replace('<div class="document-signatures"></div>', $this->unsignedSignatureBlock($type), $rendered);
    }

    public function unsignedSignatureBlock(?string $type = null): string
    {
        if ($type === 'ROOM_RENTAL') {
            return '<div class="document-signatures"><div><h3>BÊN CHO THUÊ (BÊN A)</h3><p>Người đại diện (Họ tên, chữ ký)</p><p>Chưa ký</p></div><div><h3>BÊN THUÊ (BÊN B)</h3><p>(Họ tên, chữ ký)</p><p>Chưa ký</p></div></div>';
        }
        if ($type === 'DORM_BED') {
            return '<div class="document-signatures"><div><h3>ĐẠI DIỆN BÊN CHO THUÊ (BÊN A)</h3><p>(Ký và ghi rõ họ tên)</p><p>Chưa ký</p></div><div><h3>ĐẠI DIỆN BÊN THUÊ (BÊN B)</h3><p>(Ký và ghi rõ họ tên)</p><p>Chưa ký</p></div></div>';
        }

        return '<div class="document-signatures"><div><h3>BÊN CHO THUÊ (BÊN A)</h3><p>Chưa ký</p></div><div><h3>BÊN THUÊ (BÊN B)</h3><p>Chưa ký</p></div></div>';
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? 'Chưa cập nhật'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function table(array $headers, array $rows): string
    {
        $html = '<table><thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th>'.$this->escape($header).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $value) {
                $html .= '<td>'.$value.'</td>';
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table>';
    }

    private function services(array $services): string
    {
        if ($services === []) {
            return '<p>Không có dịch vụ được ghi nhận trong thỏa thuận này.</p>';
        }
        $methods = ['FIXED' => 'Cố định', 'PER_UNIT' => 'Theo đơn vị', 'PER_PERSON' => 'Theo người', 'TIERED' => 'Bậc thang'];
        $rows = [];
        foreach ($services as $service) {
            if (! ($service['is_active'] ?? true)) {
                continue;
            }
            $unit = empty($service['unit']) ? '' : ' / '.$this->escape($service['unit']);
            $price = $this->escape($this->registry->number($service['base_price'] ?? null, true)).$unit;
            if (($service['billing_method'] ?? '') === 'TIERED') {
                $lines = [];
                foreach ($service['pricing_snapshot'] ?? [] as $tier) {
                    $lines[] = $this->escape($this->registry->number($tier['from_quantity'])).'–'.(($tier['to_quantity'] ?? null) === null ? 'Không giới hạn' : $this->escape($this->registry->number($tier['to_quantity']))).': '.$this->escape($this->registry->number($tier['unit_price'], true)).$unit;
                }
                $price = implode('<br>', $lines);
            }
            $rows[] = [$this->escape($service['service_name']), $this->escape($methods[$service['billing_method']] ?? $service['billing_method']), $price, $this->escape($this->registry->number($service['vat_rate'] ?? 0)).'%', $this->escape($this->registry->date($service['effective_from'] ?? null))];
        }

        return $rows === [] ? '<p>Chưa có dịch vụ được ghi nhận.</p>' : $this->table(['Dịch vụ', 'Cách tính', 'Giá thỏa thuận', 'VAT', 'Hiệu lực từ'], $rows);
    }

    private function roomRentalServices(array $services): string
    {
        // Render only the prices agreed in this contract snapshot, never today's mutable Service price.
        $slots = [
            'ELECTRICITY' => 'Tiền điện',
            'WATER' => 'Tiền nước',
            'INTERNET' => 'Internet',
            'LAUNDRY' => 'Máy giặt',
            'PARKING' => 'Giữ xe',
            'SERVICE' => 'Dịch vụ',
        ];
        $grouped = [];
        foreach ($services as $service) {
            if (! ($service['is_active'] ?? true)) {
                continue;
            }
            $type = $service['service_type'] ?? $service['type'] ?? '';
            $slot = match ($type) {
                'OTHER', 'SERVICE' => str_contains(mb_strtolower((string) ($service['service_name'] ?? '')), 'giặt') ? 'LAUNDRY' : 'SERVICE',
                'TRASH', 'CLEANING' => 'SERVICE',
                default => $type,
            };
            if (isset($slots[$slot])) {
                $grouped[$slot][] = $service;
            }
        }

        $html = '';
        foreach ($slots as $type => $label) {
            $terms = array_map(fn ($service) => $this->roomRentalServicePrice($service, $type === 'SERVICE'), $grouped[$type] ?? []);
            $html .= '<p>'.($terms === [] ? '☐' : '☑').' '.$this->escape($label).' : '.($terms === [] ? '…………………………………………' : implode('; ', $terms)).'</p>';
        }

        return $html;
    }

    private function roomRentalServicePrice(array $service, bool $includeName): string
    {
        $name = $includeName ? $this->escape($service['service_name'] ?? '').' — ' : '';
        $method = $service['billing_method'] ?? '';
        if ($method === 'TIERED') {
            $tiers = array_map(fn ($tier) => $this->escape($this->registry->number($tier['from_quantity'] ?? 0)).'–'.
                ($tier['to_quantity'] ?? null ? $this->escape($this->registry->number($tier['to_quantity'])) : 'không giới hạn').': '.
                $this->escape($this->registry->number($tier['unit_price'] ?? null, true)).
                (empty($service['unit']) ? '' : ' / '.$this->escape($service['unit'])), $service['pricing_snapshot'] ?? []);

            return $name.'bậc thang: '.implode('; ', $tiers);
        }

        $price = $this->escape($this->registry->number($service['base_price'] ?? null, true));
        $unit = trim((string) ($service['unit'] ?? ''));

        return $name.$price.($unit === '' ? '' : ' / '.$this->escape($unit));
    }

    private function occupants(array $occupants): string
    {
        if ($occupants === []) {
            return '<p>Chưa có danh sách người ở được ghi nhận.</p>';
        }
        $rows = array_map(fn ($person) => [
            $this->escape($person['full_name']), $this->escape($this->registry->date($person['date_of_birth'] ?? null)),
            $this->escape($person['identity_number'] ?? null), ($person['is_primary'] ?? false) ? 'Người đứng tên' : 'Người ở cùng',
        ], $occupants);

        return $this->table(['Họ tên', 'Ngày sinh', 'Số định danh', 'Vai trò'], $rows);
    }

    private function vehicles(array $vehicles): string
    {
        if ($vehicles === []) {
            return '<p>Không có phương tiện được đăng ký.</p>';
        }

        $rows = array_map(fn ($vehicle) => [$this->escape($vehicle['type'] ?? null), $this->escape($vehicle['license_plate'] ?? null), $this->escape($vehicle['note'] ?? null)], $vehicles);

        return $this->table(['Loại phương tiện', 'Biển số', 'Ghi chú'], $rows);
    }

    private function handover(array $items): string
    {
        if ($items === []) {
            return '<p>Chưa có biên bản bàn giao được ghi nhận.</p>';
        }

        $conditions = ['GOOD' => 'Tốt', 'DAMAGED' => 'Hư hỏng', 'MISSING' => 'Thiếu', 'OTHER' => 'Khác'];
        $rows = array_map(fn ($item) => [
            $this->escape($item['name'] ?? null), $this->escape($item['quantity'] ?? null),
            $this->escape($conditions[$item['condition'] ?? ''] ?? ($item['condition'] ?? null)),
        ], $items);

        return $this->table(['Tài sản', 'Số lượng', 'Tình trạng'], $rows);
    }
}
