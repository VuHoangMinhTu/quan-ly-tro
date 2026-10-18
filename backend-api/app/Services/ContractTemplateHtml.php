<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Validation\ValidationException;

class ContractTemplateHtml
{
    private const TAGS = ['p', 'h1', 'h2', 'h3', 'h4', 'strong', 'em', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'br', 'div', 'span'];

    private const CLASSES = ['text-center', 'text-right', 'text-justify', 'document-signatures', 'page-break'];

    /** Rebuild a fragment with DOM nodes; never regex-strip executable HTML. */
    public function clean(string $html): string
    {
        if (str_contains($html, '<?') || stripos($html, '<!DOCTYPE') !== false || stripos($html, '<!ENTITY') !== false) {
            $this->reject();
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $source = new DOMDocument;
            $source->loadHTML('<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            // Inspect the whole parsed tree too: malformed input may move nodes into HEAD.
            foreach ($source->getElementsByTagName('*') as $element) {
                if (in_array($element->tagName, ['html', 'head', 'body'], true)) {
                    if ($element->attributes->length !== 0 || $source->getElementsByTagName($element->tagName)->length !== 1) {
                        $this->reject();
                    }
                } elseif ($element->tagName === 'meta') {
                    if ($source->getElementsByTagName('meta')->length !== 1 || $element->getAttribute('charset') !== 'UTF-8' || $element->attributes->length !== 1) {
                        $this->reject();
                    }
                } elseif (! in_array($element->tagName, self::TAGS, true)) {
                    $this->reject();
                }
            }
            $target = new DOMDocument('1.0', 'UTF-8');
            $root = $target->appendChild($target->createElement('div'));
            $count = 0;
            foreach ($source->getElementsByTagName('body')->item(0)->childNodes as $node) {
                $this->copy($node, $root, $target, 0, $count);
            }
            $result = '';
            foreach ($root->childNodes as $node) {
                $result .= $target->saveHTML($node);
            }

            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function copy(DOMNode $node, DOMNode $parent, DOMDocument $target, int $depth, int &$count): void
    {
        if (++$count > 20000 || $depth > 30) {
            $this->reject();
        }
        if ($node->nodeType === XML_TEXT_NODE) {
            $parent->appendChild($target->createTextNode($node->textContent));

            return;
        }
        if ($node->nodeType === XML_COMMENT_NODE) {
            return;
        }
        if (! $node instanceof DOMElement || ! in_array($node->tagName, self::TAGS, true)) {
            $this->reject();
        }
        $element = $target->createElement($node->tagName);
        foreach ($node->attributes as $attribute) {
            if ($attribute->name === 'class') {
                $classes = preg_split('/\s+/', trim($attribute->value), -1, PREG_SPLIT_NO_EMPTY);
                if (array_diff($classes, self::CLASSES)) {
                    $this->reject();
                }
                $element->setAttribute('class', implode(' ', $classes));
            } elseif (in_array($node->tagName, ['th', 'td'], true) && in_array($attribute->name, ['colspan', 'rowspan'], true)
                && ctype_digit($attribute->value) && (int) $attribute->value >= 1 && (int) $attribute->value <= 12) {
                $element->setAttribute($attribute->name, $attribute->value);
            } else {
                // No event handlers, URLs, inline CSS, IDs, embedded images or frames.
                $this->reject();
            }
        }
        $parent->appendChild($element);
        foreach ($node->childNodes as $child) {
            $this->copy($child, $element, $target, $depth + 1, $count);
        }
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['content_html' => 'HTML chứa thẻ hoặc thuộc tính không được hỗ trợ. Chỉ dùng nội dung văn bản, tiêu đề, danh sách và bảng; không dùng script, liên kết, ảnh nhúng hoặc CSS tùy ý.']);
    }
}
