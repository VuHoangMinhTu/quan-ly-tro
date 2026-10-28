<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use LogicException;

class ContractTemplateDemo
{
    public function __construct(private ContractTemplateRenderer $renderer) {}

    public function document(string $type): array
    {
        $fixtures = require resource_path('demo/contracts.php');
        abort_unless(isset($fixtures[$type]), 404);
        $demo = $fixtures[$type];
        $content = file_get_contents(resource_path('views/'.$demo['template']));
        foreach ($demo['checked_options'] ?? [] as $label) {
            $content = str_replace('[  ] '.$label, '[x] '.$label, $content, $count);
            if ($count !== 1) {
                throw new LogicException('Demo checkbox no longer matches the source template.');
            }
        }

        return ['is_demo' => true, 'name' => $demo['name'], 'filename' => $demo['filename'], 'summary' => $demo['summary'],
            'rendered_html' => $this->renderer->render($this->fillPaperFields($content, $demo['paper_fields']), $demo['snapshot'])];
    }

    /** Fill only the source template's paper blanks; never copy or rewrite legal clauses. */
    private function fillPaperFields(string $content, array $fields): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<html><head><meta charset="UTF-8"></head><body>'.$content.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        $matched = [];
        foreach (iterator_to_array($dom->getElementsByTagName('p')) as $paragraph) {
            if (str_starts_with(trim($paragraph->textContent), 'Đại diện : {{lessor.full_name}}')) {
                foreach ($xpath->query('.//text()', $paragraph) as $text) {
                    $text->nodeValue = preg_replace('/\s+(?=Chức vụ:)/u', str_repeat("\u{00A0}", 6), $text->nodeValue);
                }
            }
            if (str_starts_with(trim($paragraph->textContent), 'Ngân hàng: {{lessor.bank_name}}')) {
                foreach (explode('|', $paragraph->textContent) as $part) {
                    [$label, $value] = explode(':', $part, 2);
                    $line = $dom->createElement('p');
                    $line->appendChild($dom->createTextNode(trim($label).': '.trim($value)));
                    $paragraph->parentNode->insertBefore($line, $paragraph);
                }
                $paragraph->parentNode->removeChild($paragraph);

                continue;
            }
            foreach ($fields as $prefix => $values) {
                if (! str_starts_with(trim($paragraph->textContent), $prefix)) {
                    continue;
                }
                $index = 0;
                foreach ($xpath->query('.//text()', $paragraph) as $text) {
                    $text->nodeValue = preg_replace_callback('/[.…]{2,}(?:[\/\d]*[.…]+)*/u', function () use ($values, &$index): string {
                        if (! array_key_exists($index, $values)) {
                            throw new LogicException('Demo paper fields no longer match the source template.');
                        }

                        return $values[$index++];
                    }, $text->nodeValue);
                }
                if ($index !== count($values)) {
                    throw new LogicException('Demo paper fields no longer match the source template.');
                }
                $matched[$prefix] = true;
            }
        }
        if (count($matched) !== count($fields)) {
            throw new LogicException('Demo paper fields no longer match the source template.');
        }

        return implode('', array_map(fn ($node) => $dom->saveHTML($node), iterator_to_array($dom->getElementsByTagName('body')->item(0)->childNodes)));
    }
}
