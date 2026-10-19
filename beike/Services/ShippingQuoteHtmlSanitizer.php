<?php

namespace Beike\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Keep interactive shipping-quote fields compatible without trusting plugin
 * HTML as executable storefront code.
 */
class ShippingQuoteHtmlSanitizer
{
    private const MAX_BYTES = 20000;

    /** @var array<string, true> */
    private const ALLOWED_TAGS = [
        'a'        => true, 'b' => true, 'br' => true, 'button' => true,
        'div'      => true, 'em' => true, 'fieldset' => true, 'i' => true,
        'img'      => true, 'input' => true, 'label' => true, 'legend' => true,
        'li'       => true, 'ol' => true, 'option' => true, 'p' => true,
        'select'   => true, 'small' => true, 'span' => true, 'strong' => true,
        'textarea' => true, 'ul' => true,
    ];

    /** @var array<string, true> */
    private const DROP_WITH_CONTENT = [
        'applet' => true, 'audio' => true, 'base' => true, 'canvas' => true,
        'embed'  => true, 'form' => true, 'frame' => true, 'iframe' => true,
        'link'   => true, 'meta' => true, 'object' => true, 'script' => true,
        'style'  => true, 'svg' => true, 'template' => true, 'video' => true,
    ];

    /** @var array<string, true> */
    private const ALLOWED_ATTRIBUTES = [
        'alt'      => true, 'autocomplete' => true, 'checked' => true,
        'class'    => true, 'cols' => true, 'disabled' => true, 'for' => true,
        'height'   => true, 'href' => true, 'id' => true, 'inputmode' => true,
        'max'      => true, 'maxlength' => true, 'min' => true, 'multiple' => true,
        'name'     => true, 'pattern' => true, 'placeholder' => true,
        'readonly' => true, 'rel' => true, 'required' => true, 'role' => true,
        'rows'     => true, 'selected' => true, 'src' => true, 'step' => true,
        'target'   => true, 'title' => true, 'type' => true, 'value' => true,
        'width'    => true,
    ];

    private const RESERVED_ATTRIBUTES = [
        'data-key'             => true,
        'data-value'           => true,
        'data-submit-checkout' => true,
        'data-express-payment' => true,
    ];

    public function sanitize(mixed $html, string $scope = ''): string
    {
        $html = trim(mb_strcut((string) $html, 0, self::MAX_BYTES, 'UTF-8'));
        if ($html === '') {
            return '';
        }

        if (! class_exists(DOMDocument::class)) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded   = $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="longos-shipping-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return '';
        }

        $root = $document->getElementById('longos-shipping-root');
        if (! $root) {
            return '';
        }

        $this->cleanChildren($root);
        $this->namespaceIds($root, $scope !== '' ? $scope : hash('sha256', $html));

        $result = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $result .= $document->saveHTML($child) ?: '';
        }

        return trim($result);
    }

    /**
     * Required plugin fields are part of the shipping quote contract. The
     * browser improves usability, while confirmation repeats the check so a
     * direct request cannot bypass carrier requirements.
     *
     * @return array<int, string>
     */
    public function requiredFieldNames(string $safeHtml): array
    {
        return $this->extractFieldNames($safeHtml, true);
    }

    /** @return array<int, string> */
    public function fieldNames(string $safeHtml): array
    {
        return $this->extractFieldNames($safeHtml, false);
    }

    /** @return array<int, string> */
    private function extractFieldNames(string $safeHtml, bool $requiredOnly): array
    {
        if ($safeHtml === '' || ! class_exists(DOMDocument::class)) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded   = $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="longos-required-root">' . $safeHtml . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return [];
        }

        $root   = $document->getElementById('longos-required-root');
        $fields = [];
        foreach ($root?->getElementsByTagName('*') ?? [] as $element) {
            if (! $element instanceof DOMElement
                || ! in_array(strtolower($element->tagName), ['input', 'select', 'textarea'], true)
                || ($requiredOnly && ! $element->hasAttribute('required'))
                || $element->hasAttribute('disabled')
                || ($requiredOnly && strtolower($element->tagName) === 'input' && strtolower($element->getAttribute('type')) === 'hidden')) {
                continue;
            }

            $name = trim($element->getAttribute('name'));
            if (preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $name) === 1) {
                $fields[$name] = true;
            }
        }

        return array_slice(array_keys($fields), 0, 50);
    }

    /**
     * Rehydrate previously submitted carrier fields after checkout recalculates
     * and replaces the shipping-method markup.
     */
    public function applyFieldValues(string $safeHtml, array $fields): string
    {
        if ($safeHtml === '' || ! class_exists(DOMDocument::class)) {
            return $safeHtml;
        }

        $values = [];
        foreach (array_slice($fields, 0, 50, true) as $name => $value) {
            if (preg_match('/^[A-Za-z0-9_.-]{1,80}$/', (string) $name) === 1
                && (is_scalar($value) || $value === null)) {
                $values[(string) $name] = mb_substr((string) $value, 0, 1000);
            }
        }
        if ($values === []) {
            return $safeHtml;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded   = $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="longos-values-root">' . $safeHtml . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded || ! ($root = $document->getElementById('longos-values-root'))) {
            return $safeHtml;
        }

        foreach ($root->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }
            $tag  = strtolower($element->tagName);
            $name = trim($element->getAttribute('name'));
            if (! in_array($tag, ['input', 'select', 'textarea'], true) || ! array_key_exists($name, $values)) {
                continue;
            }

            $value = $values[$name];
            if ($tag === 'textarea') {
                $element->textContent = $value;

                continue;
            }
            if ($tag === 'select') {
                $selectedValues = $element->hasAttribute('multiple') ? explode(',', $value) : [$value];
                foreach ($element->getElementsByTagName('option') as $option) {
                    if (in_array($option->getAttribute('value'), $selectedValues, true)) {
                        $option->setAttribute('selected', 'selected');
                    } else {
                        $option->removeAttribute('selected');
                    }
                }

                continue;
            }

            $type = strtolower($element->getAttribute('type'));
            if (in_array($type, ['checkbox', 'radio'], true)) {
                $selectedValues = explode(',', $value);
                $controlValue   = $element->hasAttribute('value') ? $element->getAttribute('value') : 'on';
                if (in_array($controlValue, $selectedValues, true)) {
                    $element->setAttribute('checked', 'checked');
                } else {
                    $element->removeAttribute('checked');
                }
            } else {
                $element->setAttribute('value', $value);
            }
        }

        $result = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $result .= $document->saveHTML($child) ?: '';
        }

        return trim($result);
    }

    private function cleanChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);
            if (! isset(self::ALLOWED_TAGS[$tag])) {
                if (isset(self::DROP_WITH_CONTENT[$tag])) {
                    $parent->removeChild($node);

                    continue;
                }

                $this->cleanChildren($node);
                while ($node->firstChild) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);

                continue;
            }

            $this->cleanAttributes($node);
            $this->cleanChildren($node);
        }
    }

    private function cleanAttributes(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name     = strtolower($attribute->name);
            $value    = trim($attribute->value);
            $reserved = isset(self::RESERVED_ATTRIBUTES[$name])
                || str_starts_with($name, 'data-shipping-')
                || str_starts_with($name, 'data-checkout-');
            $allowed = isset(self::ALLOWED_ATTRIBUTES[$name])
                || preg_match('/^(?:aria|data)-[a-z0-9_.:-]+$/', $name) === 1;

            if ($reserved
                || ! $allowed
                || str_starts_with($name, 'on')
                || in_array($name, ['form', 'formaction', 'srcdoc', 'style'], true)
            ) {
                $element->removeAttributeNode($attribute);

                continue;
            }

            if (in_array($name, ['href', 'src'], true) && ! $this->safeUrl($value, $name === 'href')) {
                $element->removeAttributeNode($attribute);

                continue;
            }

            if ($name === 'name' && preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $value) !== 1) {
                $element->removeAttributeNode($attribute);

                continue;
            }

            if ($name === 'type' && strtolower($element->tagName) === 'input') {
                $type = strtolower($value);
                if (! in_array($type, ['checkbox', 'date', 'email', 'hidden', 'number', 'radio', 'tel', 'text', 'time'], true)) {
                    $element->setAttribute('type', 'text');
                }
            }
        }

        if (strtolower($element->tagName) === 'a' && $element->getAttribute('target') === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private function namespaceIds(DOMElement $root, string $scope): void
    {
        $prefix = 'longos-shipping-' . substr(hash('sha256', $scope), 0, 12) . '-';
        $map    = [];
        $used   = [];

        foreach ($root->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement || ! $element->hasAttribute('id')) {
                continue;
            }

            $oldId = trim($element->getAttribute('id'));
            $base  = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $oldId), '-');
            if ($base === '') {
                $element->removeAttribute('id');

                continue;
            }

            $newId = $prefix . mb_substr($base, 0, 80);
            $copy  = 2;
            while (isset($used[$newId])) {
                $newId = $prefix . mb_substr($base, 0, 70) . '-' . $copy++;
            }
            $used[$newId] = true;
            $map[$oldId] ??= $newId;
            $element->setAttribute('id', $newId);
        }

        $referenceAttributes = ['for', 'aria-controls', 'aria-describedby', 'aria-labelledby', 'aria-owns', 'aria-activedescendant'];
        foreach ($root->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            foreach ($referenceAttributes as $attribute) {
                if (! $element->hasAttribute($attribute)) {
                    continue;
                }
                $references = preg_split('/\s+/', trim($element->getAttribute($attribute))) ?: [];
                $rewritten  = array_values(array_filter(array_map(fn ($id) => $map[$id] ?? null, $references)));
                if ($rewritten) {
                    $element->setAttribute($attribute, implode(' ', $rewritten));
                } else {
                    $element->removeAttribute($attribute);
                }
            }

            $href = $element->getAttribute('href');
            if (str_starts_with($href, '#')) {
                $target = substr($href, 1);
                if (isset($map[$target])) {
                    $element->setAttribute('href', '#' . $map[$target]);
                } else {
                    $element->removeAttribute('href');
                }
            }
        }
    }

    private function safeUrl(string $value, bool $allowContact): bool
    {
        if ($value === '' || str_starts_with($value, '/') || str_starts_with($value, '#')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, $allowContact ? ['http', 'https', 'mailto', 'tel'] : ['http', 'https'], true);
    }
}
