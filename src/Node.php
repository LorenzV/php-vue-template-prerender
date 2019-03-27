<?php

namespace VuePre;

use DOMElement;
use DOMNode;

class Node {

    public $childNodes = [];
    public $settings = [];

    public $engine = null;

    public function __construct(Engine $engine) {
        $this->engine = $engine;
    }

    public function parseDomNode(DOMNode $node, $options = []): Node {

        $this->settings = [
            'isTemplate' => false,
            'isComponent' => null,
            //
            'vfor' => null,
            'vif' => null,
            'velseif' => null,
            'velse' => null,
            'vhtml' => null,
            //
            'class' => null,
            'mustacheValues' => [],
            'bindedValues' => [],
            // Slots
            'vslot' => null,
            'slotNodes' => [],
        ];

        $this->settings['nodeType'] = $node->nodeType;
        $this->settings['content'] = $node->nodeType === 3 ? $node->textContent : '';

        $this->replaceMustacheVariables($node);
        if ($node->nodeType === 1) {
            $this->stripEventHandlers($node);
            $this->handleFor($node, $options);

            $this->handleIf($node, $options);
            if ($this->isRemovedFromTheDom($node)) {return $this;}

            $this->handleTemplateTag($node, $options);
            if ($this->isRemovedFromTheDom($node)) {return $this;}

            $this->handleSlot($node, $options);
            if ($this->isRemovedFromTheDom($node)) {return $this;}

            $this->handleComponent($node, $options);
            if ($this->isRemovedFromTheDom($node)) {return $this;}

            $this->handleAttributeBinding($node);
            if ($this->isRemovedFromTheDom($node)) {return $this;}

            $this->handleRawHtml($node);
            if ($this->isRemovedFromTheDom($node)) {return $this;}

            $subNodes = iterator_to_array($node->childNodes);
            $this->childNodes = [];
            foreach ($subNodes as $index => $childNode) {
                if (!$this->settings['isComponent']) {
                    $newNode = new Node($this->engine);
                    $this->childNodes[] = $newNode->parseDomNode($childNode);
                }
                $node->removeChild($childNode);
            }

            $newNode = $node->ownerDocument->createTextNode('_VUEPRE_HTML_PLACEHOLDER_');
            $node->appendChild($newNode);
            $this->settings['content'] = $node->ownerDocument->saveHTML($node);
        }

        return $this;
    }

    public function makeTemplate(): Array{
        $result = $this->settings;

        $result['childNodes'] = [];
        foreach ($this->childNodes as $node) {
            $result['childNodes'][] = $node->makeTemplate();
        }

        return $result;
    }

    private function stripEventHandlers(DOMNode $node) {
        foreach ($node->attributes as $attribute) {
            if (strpos($attribute->name, 'v-on:') === 0) {
                $node->removeAttribute($attribute->name);
            }
        }
    }

    private function replaceMustacheVariables(DOMNode $node) {
        if ($node->nodeType === 3) {
            // $text = $node->nodeValue;
            $text = $this->settings['content'];
            $count = 0;
            $this->settings['mustacheValues'] = [];
            $regex = '/\{\{(?P<expression>.*?)\}\}/x';
            preg_match_all($regex, $text, $matches);
            foreach ($matches['expression'] as $index => $expression) {
                $phpExpr = ConvertJsExpression::convert($expression);
                $tag = '_VUEPRE_MUSHTAG' . $count . '_';
                $text = str_replace($matches[0][$index], $tag, $text);
                $this->settings['mustacheValues'][$tag] = $phpExpr;
                $count++;
            }
            $this->settings['content'] = $text;
        }
    }

    private function handleAttributeBinding(DOMElement $node) {
        $removeAttrs = [];
        foreach (iterator_to_array($node->attributes) as $attribute) {

            if (!preg_match('/^:[\w-]+$/', $attribute->name)) {
                continue;
            }

            $name = substr($attribute->name, 1);
            $removeAttrs[] = $attribute->name;

            if ($name === 'class') {
                $currentClass = $node->getAttribute('class');
                $node->setAttribute($name, $currentClass . ' _VUEPRE_CLASS_');
                $phpExpr = ConvertJsExpression::convert($attribute->value);
                $this->settings['class'] = $phpExpr;
            }

            if ($node->tagName === 'component' && $name === 'is') {
                $phpExpr = ConvertJsExpression::convert($attribute->value);
                $this->settings['isComponent'] = $phpExpr;
                return;
            }
        }
        foreach ($removeAttrs as $attr) {
            $node->removeAttribute($attr);
        }
    }

    private function handleTemplateTag(DOMNode $node, $options) {
        $tagName = $node->tagName;
        if ($tagName !== 'template') {
            return;
        }

        $this->settings['isTemplate'] = true;
    }

    private function handleSlot(DOMNode $node, $options) {
        $tagName = $node->tagName;
        if ($tagName !== 'slot') {
            return;
        }

        $slotName = '_DEFAULTSLOT_';
        if ($node->hasAttribute('name')) {
            $slotName = $node->getAttribute('name');
        }

        $this->settings['vslot'] = $slotName;
    }

    private function handleComponent(DOMNode $node) {

        $componentName = $node->tagName;

        if (!isset($this->engine->componentAlias[$componentName])) {
            return;
        }
        $componentNameExpr = '\'' . $componentName . '\'';
        $this->settings['isComponent'] = $componentNameExpr;

        $data = [];
        foreach (iterator_to_array($node->attributes) as $attribute) {
            if (!preg_match('/^:[\w-]+$/', $attribute->name)) {
                continue;
            }

            $name = substr($attribute->name, 1);
            if ($name === 'class') {
                continue;
            }

            $phpExpr = ConvertJsExpression::convert($attribute->value);
            $this->settings['bindedValues'][$name] = $phpExpr;
            $node->removeAttribute($attribute->name);
        }

        $slotNodes = [];
        $subNodes = iterator_to_array($node->childNodes);
        foreach ($subNodes as $index => $childNode) {
            $slotName = '_DEFAULTSLOT_';

            if ($childNode->nodeType === 1) {
                foreach ($childNode->attributes as $attribute) {
                    if (strpos($attribute->name, 'v-slot:') === 0) {
                        $slotName = substr($attribute->name, strlen('v-slot:'));
                    }
                }
            }

            $slotNode = new Node($this->engine);
            $slotNode = $slotNode->parseDomNode($childNode);

            if (!isset($slotNodes[$slotName])) {
                $slotNodes[$slotName] = [];
            }
            $slotNodes[$slotName][] = $slotNode->makeTemplate();
        }

        $this->settings['slotNodes'] = $slotNodes;
    }

    private function handleIf(DOMNode $node, array $options) {
        if ($node->hasAttribute('v-if')) {
            $conditionString = $node->getAttribute('v-if');
            $node->removeAttribute('v-if');
            $phpExpr = ConvertJsExpression::convert($conditionString);
            $this->settings['vif'] = $phpExpr;
        } elseif ($node->hasAttribute('v-else-if')) {
            $conditionString = $node->getAttribute('v-else-if');
            $node->removeAttribute('v-else-if');
            $phpExpr = ConvertJsExpression::convert($conditionString);
            $this->settings['velseif'] = $phpExpr;
        } elseif ($node->hasAttribute('v-else')) {
            $node->removeAttribute('v-else');
            $this->settings['velse'] = true;
        }
    }
    private function handleFor(DOMNode $node, array $options) {
        /** @var DOMElement $node */
        if ($node->hasAttribute('v-for')) {
            list($itemName, $listName) = explode(' in ', $node->getAttribute('v-for'));
            $node->removeAttribute('v-for');

            // Support for item,index in myArray
            $itemName = trim($itemName, '() ');
            $itemIndex = null;
            $itemNameEx = explode(',', $itemName);
            if (count($itemNameEx) === 2) {
                $itemName = trim($itemNameEx[0]);
                $itemIndex = trim($itemNameEx[1]);
            }

            $phpExpr = ConvertJsExpression::convert($listName);
            $this->settings['vfor'] = $phpExpr;
            $this->settings['vforAsName'] = $itemName;
            $this->settings['vforIndexName'] = $itemIndex;
        }
    }

    private function handleRawHtml(DOMNode $node) {
        /** @var DOMElement $node */
        if ($node->hasAttribute('v-html')) {
            $expr = $node->getAttribute('v-html');
            $node->removeAttribute('v-html');
            $phpExpr = ConvertJsExpression::convert($expr);
            $this->settings['vhtml'] = $phpExpr;
        }
    }

    private function isRemovedFromTheDom(DOMNode $node) {
        return $node->parentNode === null;
    }
}
