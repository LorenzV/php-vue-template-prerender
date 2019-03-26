<?php

namespace VuePre;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Exception;
use LibXMLError;

//

class Engine {

    private $componentDir = null;
    private $cacheDir = null;

    private $componentAlias = [];
    private $components = [];
    private $methods = [];
    private $renderedComponentNames = [];
    private $componentBeforeRender = [];
    private $settingsLoaded = [];
    private $componentTemplates = [];

    public $disableCache = false;
    public $disableAutoScan = false;

    private $errorLine = null;
    private $errorExpression = null;

    private $slotHtml = [];

    const PHPOPEN = '__VUEPREPHPTAG__';
    const PHPEND = '__VUEPREPHPENDTAG__';

    public function __construct() {
    }

    /////////////////////////
    // Settings
    /////////////////////////

    public function setCacheDirectory(String $dir) {
        $dir = realpath($dir);
        if (!file_exists($dir) || !is_dir($dir)) {
            throw new Exception('Cache directory not found: ' . $dir);
        }
        $this->cacheDir = $dir;
    }

    public function setComponentDirectory(String $dir) {
        $dir = realpath($dir);
        if (!file_exists($dir) || !is_dir($dir)) {
            throw new Exception('Component directory not found: ' . $dir);
        }
        $this->componentDir = $dir;
        if (!$this->disableAutoScan) {
            $this->scanComponentDirectoryForComponents();
        }
    }

    /////////////////////////
    // Getters / Setters
    /////////////////////////

    public function getRenderedComponentNames() {
        return array_values($this->renderedComponentNames);
    }

    /////////////////////////
    // Component finding
    /////////////////////////

    public function setComponentAlias(array $aliasses) {
        foreach ($aliasses as $componentName => $alias) {
            if (is_string($componentName) && is_string($alias)) {
                $this->componentAlias[$componentName] = $alias;
            }
        }
    }

    public function getComponentAlias($componentName, $default = null) {
        if (isset($this->componentAlias[$componentName])) {
            return $this->componentAlias[$componentName];
        }
        if ($default) {
            return $default;
        }
        throw new \Exception('Cannot find alias for "' . $componentName . '"');
    }

    /////////////////////////
    // Helper functions
    /////////////////////////

    public function loadComponent($componentName) {
        if (!isset($this->components[$componentName])) {
            $component = [
                'settings' => null,
                'template' => null,
                'js' => null,
            ];

            $alias = $this->getComponentAlias($componentName);
            $dirPath = $this->componentDir . '/' . implode('/', explode('.', $alias));
            $path = $dirPath . '.php';

            if (!file_exists($path)) {
                throw new Exception('Component file not found: ' . $path);
            }

            $content = "\n" . file_get_contents($path);

            $php = static::getStringBetweenTags($content, '\n<\?php\s', '\n\?>');
            $template = static::getStringBetweenTags($content, '\n<template ?[^>]*>', '\n<\/template>');
            $js = static::getStringBetweenTags($content, '\n<script ?[^>]*>', '\n<\/script>');

            $loadSettings = function ($php) {
                $settings = eval($php);
                return $settings;
            };

            $settings = $loadSettings($php);

            if (!isset($this->componentBeforeRender[$componentName]) && isset($settings['beforeRender'])) {
                $this->componentBeforeRender[$componentName] = $settings['beforeRender'];
            }

            $component['settings'] = $settings;
            $component['template'] = $template;
            $component['js'] = $js;

            $this->components[$componentName] = $component;
        }

        return $this->components[$componentName];
    }

    private static function getStringBetweenTags($string, $startReg, $endReg) {
        $found = preg_match("/" . $startReg . "/", $string, $match, PREG_OFFSET_CAPTURE);
        if (!$found) {
            return '';
        }

        $startPos = $match[0][1] + strlen($match[0][0]);
        $result = '';
        $count = 1;
        while (preg_match("/" . $startReg . "|" . $endReg . "/", $string, $match, PREG_OFFSET_CAPTURE, $match[0][1] + 1)) {
            $isStart = preg_match("/" . $startReg . "/", $match[0][0]);

            if ($isStart) {
                $count++;
                continue;
            }

            $count--;
            if ($count === 0) {
                $result = substr($string, $startPos, $match[0][1] - $startPos);
                break;
            }
        }

        if ($count !== 0) {
            throw new \Exception('Cannot find closing tag "' . $endReg . '"');
        }

        return $result;
    }

    public function getComponentTemplate($componentName, $default = null) {
        $comp = $this->loadComponent($componentName);
        return $comp['template'];
    }

    public function getComponentJs($componentName, $default = null) {
        $comp = $this->loadComponent($componentName);
        return $comp['js'];
    }

    /////////////////////////
    // <script> functions
    /////////////////////////

    public function getTemplateScripts($idPrefix = 'vue-template-') {
        $result = '';
        foreach ($this->components as $componentName => $component) {
            $template = $this->getComponentTemplate($componentName);
            $result .= '<script type="text/template" id="' . $idPrefix . $componentName . '">' . ($template) . '</script>';
        }
        return $result;
    }

    public function getJsScripts() {
        $result = '';
        foreach ($this->renderedComponentNames as $componentName => $c) {
            $result .= $this->getJsScript($componentName, '');
        }
        return $result;
    }

    public function getTemplateScript($componentName, $default = null, $idPrefix = 'vue-template-') {
        $template = $this->getComponentTemplate($componentName);
        return '<script type="text/template" id="' . $idPrefix . $componentName . '">' . ($template) . '</script>';
    }

    public function getJsScript($componentName, $default = null) {
        return '<script type="text/javascript">' . $this->getComponentJs($componentName, $default) . '</script>';
    }

    public function getScripts($componentName = null, $idPrefix = 'vue-template-') {
        if ($componentName) {
            $result = '';
            $result .= $this->getTemplateScript($componentName, null, $idPrefix);
            $result .= $this->getJsScript($componentName);
            return $result;
        }
        return $this->getTemplateScripts($idPrefix) . $this->getJsScripts();
    }

    public function getVueInstanceScript($el, $componentName, $data) {
        $html = '<script type="text/javascript">
    var VuePreApp = new Vue({
        el: "' . $el . '",
        data: function(){
            return { componentData: ' . json_encode($data) . ' };
        },
        template: \'<' . $componentName . ' :vue-pre-data="componentData"></' . $componentName . '>\',
    });
</script>';
        return $html;
    }

    /////////////////////////
    // Scan for aliasses
    /////////////////////////

    private function scanComponentDirectoryForComponents() {
        $this->scanDirectoryForComponents($this->componentDir);
    }

    public function scanDirectoryForComponents($dir) {
        if (!$this->componentDir) {
            throw new Exception('"componentDirectory" not set');
        }
        $dir = realpath($dir);
        if (strpos($dir, $this->componentDir) !== 0) {
            throw new Exception('scanDirectoryForComponents: directory must be a sub directory from "componentDirectory"');
        }
        $files = static::recursiveGlob($dir . '/*.php');
        foreach ($files as $file) {
            $fn = basename($file);
            $alias = substr($file, 0, -strlen('.php'));
            $name = basename($alias);
            $alias = str_replace('/', '.', substr($alias, strlen($this->componentDir . '/')));
            $this->componentAlias[$name] = $alias;
        }
    }

    private static function recursiveGlob($pattern, $flags = 0) {
        $files = glob($pattern, $flags);
        foreach (glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
            $files = array_merge($files, static::recursiveGlob($dir . '/' . basename($pattern), $flags));
        }
        return $files;
    }

    /////////////////////////
    // Cache
    /////////////////////////

    private function createCachedTemplate($html) {

        $dom = $this->parseHtml($html);
        // $dom = $this->parseHtml('<div>' . $html . '</div>');

        $rootNode = $this->getRootNode($dom);
        $this->handleNode($rootNode, [
            'nodeDepth' => 0,
            'nextSibling' => null,
        ]);

        //
        $html = $this->getBodyHtml($dom);

        // Replace php tags
        $html = str_replace(static::PHPOPEN, '<?php', $html);
        $html = str_replace(static::PHPEND, '?>', $html);

        // Revert html escaping within php tags
        $offset = 0;
        while (true) {
            $found = preg_match('/<\?php((?s:.)*)\?>/', $html, $match, PREG_OFFSET_CAPTURE, $offset);
            if (!$found) {
                break;
            }
            $code = $match[1][0];
            $code = htmlspecialchars_decode($code);
            $code = '<?php' . $code . '?>';
            $html = substr_replace($html, $code, $match[0][1], strlen($match[0][0]));

            $offset = $match[0][1] + 1;
        }

        return $html;
    }

    // reallyUnrealisticVariableNameForVuePre is the variable that holds the template data
    private function renderCachedTemplate($file, $reallyUnrealisticVariableNameForVuePre) {

        foreach ($this->methods as $k => $v) {
            ${$k} = $v;
        }

        foreach ($reallyUnrealisticVariableNameForVuePre as $k => $v) {
            if ($k === 'this') {
                throw new Exception('Variable "this" is not allowed');
            }
            if (isset(${$k})) {
                throw new Exception('Variable "' . $k . '" is already the name of a method');
            }
            ${$k} = $v;
        }

        set_error_handler(array($this, 'handleError'));
        ob_start();
        include $file;
        $html = ob_get_contents();
        ob_end_clean();
        restore_error_handler();

        // $esdfdsf;

        return $html;
    }

    public function handleError($errno, $errstr, $errFile, $errLine) {
        echo '<pre>';
        echo 'Error parsing "' . htmlspecialchars($this->errorExpression) . '" at line ' . ($this->errorLine) . ': ' . $errstr . "\n";
        echo 'Error at: line ' . $errLine . ' in ' . $errFile;
        echo '</pre>';
        exit;
    }

    /////////////////////////
    // Rendering
    /////////////////////////

    public function getSlotHtml($name = '_DEFAULTSLOT_') {
        return isset($this->slotHtml[$name]) ? $this->slotHtml[$name] : '';
    }

    public function renderHtml($template, $data = [], $slotHtml = '') {

        if (empty(trim($template))) {
            return '';
        }

        $hash = md5($template . filemtime(__FILE__) . json_encode($this->componentAlias)); // If package is updated, hash should change
        $cacheFile = $this->cacheDir . '/' . $hash . '.php';

        // Create cache template
        if (!file_exists($cacheFile) || $this->disableCache) {
            $html = $this->createCachedTemplate($template);
            file_put_contents($cacheFile, $html);
        }

        $this->slotHtml = ['_DEFAULTSLOT_' => $slotHtml];

        // Render cached template
        return $this->renderCachedTemplate($cacheFile, $data);
    }

    public function renderComponent($componentName, $data = [], $slotHtml = '') {

        if (!$this->componentDir) {
            throw new Exception('Trying to find component, but componentDirectory was not set');
        }

        if (!$this->cacheDir) {
            throw new Exception('Cache directory was not set');
        }

        // Load settings
        $this->loadComponent($componentName);

        // Before mount
        if (isset($this->componentBeforeRender[$componentName])) {
            $this->componentBeforeRender[$componentName]($data);
        }

        // Render template
        if ($componentName === 'div') {
            die();
        }
        $template = $this->getComponentTemplate($componentName);
        $html = $this->renderHtml($template, $data, $slotHtml);

        // Remember
        if (!isset($this->renderedComponentNames[$componentName])) {
            $this->renderedComponentNames[$componentName] = $componentName;
        }

        //
        return $html;
    }

    private function parseHtml($html) {
        $entityLoaderDisabled = libxml_disable_entity_loader(true);
        $internalErrors = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        // Ensure $html is treated as UTF-8, see https://stackoverflow.com/a/8218649
        if (!$document->loadHTML('<?xml encoding="utf-8" ?>' . $html)) {
            //TODO Test failure
            throw new \Exception('Error');
        }
        /** @var LibXMLError[] $errors */
        $errors = libxml_get_errors();
        libxml_clear_errors();
        // Restore previous state
        libxml_use_internal_errors($internalErrors);
        libxml_disable_entity_loader($entityLoaderDisabled);
        foreach ($errors as $error) {
            // var_dump($error);
            // echo '<pre>';
            // echo htmlspecialchars($html);
            // echo '</pre>';
            // exit;
            //TODO html5 tags can fail parsing
            //TODO Throw an exception
        }
        return $document;
    }

    private function getRootNode(DOMDocument $document) {
        $rootNodes = $document->documentElement->childNodes->item(0)->childNodes;
        if ($rootNodes->length > 1) {
            echo '<h2>Component template should have only one root node</h2>';
            echo '<pre>' . htmlspecialchars($this->getBodyHtml($document)) . '</pre>';
            exit;
        }
        return $rootNodes->item(0);
    }

    private function handleNode(DOMNode $node, array $options = []) {

        $this->replaceMustacheVariables($node);
        if ($this->isElementNode($node)) {
            $this->stripEventHandlers($node);
            $this->handleFor($node, $options);

            $this->handleIf($node, $options);
            if ($this->isRemovedFromTheDom($node)) {return;}

            $this->handleTemplateTag($node, $options);
            if ($this->isRemovedFromTheDom($node)) {return;}

            $this->handleSlot($node, $options);
            if ($this->isRemovedFromTheDom($node)) {return;}

            $this->handleComponent($node, $options);
            if ($this->isRemovedFromTheDom($node)) {return;}

            $this->handleAttributeBinding($node);
            if ($this->isRemovedFromTheDom($node)) {return;}

            $this->handleRawHtml($node);
            if ($this->isRemovedFromTheDom($node)) {return;}

            $subOptions = $options;
            $subOptions['nodeDepth'] += 1;
            // $subNode = $node->firstChild;
            // $lastKeptNode = null;
            // while ($subNode) {
            //     $next = $subNode->nextSibling;
            //     $subOptions['nextSibling'] = $next;
            //     $this->handleNode($subNode, $subOptions);
            //     if (!$this->isRemovedFromTheDom($subNode)) {
            //         $lastKeptNode = $subNode;
            //     }
            //     if ($lastKeptNode) {
            //         $subNode = $lastKeptNode->nextSibling;
            //     } else {
            //         $subNode = $next;
            //     }
            // }
            $subNodes = iterator_to_array($node->childNodes);
            foreach ($subNodes as $index => $childNode) {
                $subOptions['nextSibling'] = isset($subNodes[$index + 1]) ? $subNodes[$index + 1] : null;
                $this->handleNode($childNode, $subOptions);
            }
        }
    }

    private function stripEventHandlers(DOMNode $node) {
        foreach ($node->attributes as $attribute) {
            if (strpos($attribute->name, 'v-on:') === 0) {
                $node->removeAttribute($attribute->name);
            }
        }
    }

    private function replaceMustacheVariables(DOMNode $node) {
        if ($node instanceof DOMText) {
            $text = $node->nodeValue;
            $regex = '/\{\{(?P<expression>.*?)\}\}/x';
            preg_match_all($regex, $text, $matches);
            foreach ($matches['expression'] as $index => $expression) {
                $phpExpr = ConvertJsExpression::convert($expression);
                $errorCode = '$this->setErrorHint(' . ($node->getLineNo()) . ', "' . addslashes($expression) . '");';
                $text = str_replace($matches[0][$index], static::PHPOPEN . ' ' . $errorCode . ' echo htmlspecialchars(' . $phpExpr . '); ' . static::PHPEND, $text);
            }
            if ($text !== $node->nodeValue) {
                $newNode = $node->ownerDocument->createTextNode($text);
                $node->parentNode->replaceChild($newNode, $node);
            }
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
                $phpExpr = ConvertJsExpression::convert($attribute->value);
                $errorCode = '$this->setErrorHint(' . ($node->getLineNo()) . ', "' . addslashes($attribute->value) . '");';
                $node->setAttribute($name, static::PHPOPEN . ' ' . $errorCode . ' echo (' . $phpExpr . '); ' . static::PHPEND);
            }

            if ($node->tagName === 'component' && $name === 'is') {
                $phpExpr = ConvertJsExpression::convert($attribute->value);
                $this->replaceNodeWithComponent($node, $phpExpr, true);
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

        $subNodes = iterator_to_array($node->childNodes);
        foreach ($subNodes as $index => $childNode) {
            $node->parentNode->insertBefore($childNode, $node);
        }

        $newOptions = $options;

        foreach ($subNodes as $index => $childNode) {
            $newOptions['nextSibling'] = $childNode->nextSibling;
            $this->handleNode($childNode, $options);
        }

        $this->removeNode($node);
    }

    private function handleSlot(DOMNode $node, $options) {
        $tagName = $node->tagName;
        if ($tagName !== 'slot') {
            return;
        }

        $newNode = $node->ownerDocument->createTextNode(static::PHPOPEN . ' echo $this->getSlotHtml(); ' . static::PHPEND);
        $node->parentNode->insertBefore($newNode, $node);
        $this->removeNode($node);
    }

    private function handleComponent(DOMNode $node) {
        $tagName = $node->tagName;
        $this->replaceNodeWithComponent($node, $tagName);
    }

    private function replaceNodeWithComponent(DOMNode $node, $componentName, $dynamicComponent = false) {

        if ($dynamicComponent) {
            $componentNameExpr = $componentName;
        } else {
            if (!isset($this->componentAlias[$componentName])) {
                return;
            }
            $componentNameExpr = '\'' . $componentName . '\'';
        }

        $errorCode = '$this->setErrorHint(' . ($node->getLineNo()) . ', ' . json_encode($node->ownerDocument->saveHTML($node), JSON_UNESCAPED_SLASHES) . ');';

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
            $data[] = "'$name' => " . $phpExpr;
            $node->removeAttribute($attribute->name);
        }

        $dataString = implode(', ', $data);

        $slotHtml = '';
        $subNodes = iterator_to_array($node->childNodes);
        foreach ($subNodes as $index => $childNode) {
            $slotHtml .= $node->ownerDocument->saveHTML($childNode);
        }
        $slotHtml = trim($slotHtml);
        $slotsVar = "''";
        if (!empty($slotHtml)) {
            $slotsVar = '$this->renderHtml(' . json_encode('<div>' . $slotHtml . '</div>', JSON_UNESCAPED_SLASHES) . ', $reallyUnrealisticVariableNameForVuePre)';
        }

        $newNode = $node->ownerDocument->createTextNode(static::PHPOPEN . ' ' . $errorCode . ' echo $this->renderComponent(' . $componentNameExpr . ', [' . $dataString . '], ' . $slotsVar . '); ' . static::PHPEND);
        $node->parentNode->replaceChild($newNode, $node);
    }

    private function handleIf(DOMNode $node, array $options) {
        if ($node->hasAttribute('v-if')) {
            $conditionString = $node->getAttribute('v-if');
            $node->removeAttribute('v-if');
            $phpExpr = ConvertJsExpression::convert($conditionString);
            $errorCode = '$this->setErrorHint(' . ($node->getLineNo()) . ', "' . addslashes($conditionString) . '");';
            // Add php code
            $beforeNode = $node->ownerDocument->createTextNode(static::PHPOPEN . ' ' . $errorCode . ' if($_HANDLEIFRESULT' . ($options["nodeDepth"]) . ' = ' . $phpExpr . ') { ' . static::PHPEND);
            $afterNode = $node->ownerDocument->createTextNode(static::PHPOPEN . ' } ' . static::PHPEND);
            $node->parentNode->insertBefore($beforeNode, $node);
            if ($options['nextSibling']) {$node->parentNode->insertBefore($afterNode, $options['nextSibling']);} else { $node->parentNode->appendChild($afterNode);}
        } elseif ($node->hasAttribute('v-else-if')) {
            $conditionString = $node->getAttribute('v-else-if');
            $node->removeAttribute('v-else-if');
            $phpExpr = ConvertJsExpression::convert($conditionString);
            $errorCode = '$this->setErrorHint(' . ($node->getLineNo()) . ', "' . addslashes($conditionString) . '");';
            // Add php code
            $beforeNode = $node->ownerDocument->createTextNode(static::PHPOPEN . ' ' . $errorCode . ' if(!$_HANDLEIFRESULT' . ($options["nodeDepth"]) . ' && $_HANDLEIFRESULT' . ($options["nodeDepth"]) . ' = ' . $phpExpr . ') { ' . static::PHPEND);
            $afterNode = $node->ownerDocument->createTextNode(static::PHPOPEN . ' } ' . static::PHPEND);
            $node->parentNode->insertBefore($beforeNode, $node);
            if ($options['nextSibling']) {$node->parentNode->insertBefore($afterNode, $options['nextSibling']);} else { $node->parentNode->appendChild($afterNode);}
        } elseif ($node->hasAttribute('v-else')) {
            $node->removeAttribute('v-else');
            // Add php code
            $beforeNode = $node->ownerDocument->createTextNode(static::PHPOPEN . ' if(!$_HANDLEIFRESULT' . ($options["nodeDepth"]) . ') { ' . static::PHPEND);
            $afterNode = $node->ownerDocument->createTextNode(static::PHPOPEN . ' } ' . static::PHPEND);
            $node->parentNode->insertBefore($beforeNode, $node);
            if ($options['nextSibling']) {$node->parentNode->insertBefore($afterNode, $options['nextSibling']);} else { $node->parentNode->appendChild($afterNode);}
        }
    }
    private function handleFor(DOMNode $node, array $options) {
        /** @var DOMElement $node */
        if ($node->hasAttribute('v-for')) {
            list($itemName, $listName) = explode(' in ', $node->getAttribute('v-for'));
            $node->removeAttribute('v-for');

            // Support for item,index in myArray
            $itemName = trim($itemName, '() ');
            $itemNameEx = explode(',', $itemName);
            if (count($itemNameEx) === 2) {
                $itemName = trim($itemNameEx[1]) . ' => $' . trim($itemNameEx[0]);
            }

            $phpExpr = ConvertJsExpression::convert($listName);

            $beforeNode = $node->ownerDocument->createTextNode(static::PHPOPEN . ' foreach((' . $phpExpr . ') as $' . $itemName . ') { ' . static::PHPEND);
            $afterNode = $node->ownerDocument->createTextNode(static::PHPOPEN . ' } ' . static::PHPEND);
            $node->parentNode->insertBefore($beforeNode, $node);
            if ($options['nextSibling']) {$node->parentNode->insertBefore($afterNode, $options['nextSibling']);} else { $node->parentNode->appendChild($afterNode);}
        }
    }
    private function appendHTML(DOMNode $parent, $source) {
        $tmpDoc = $this->parseHtml($source);
        foreach ($tmpDoc->getElementsByTagName('body')->item(0)->childNodes as $node) {
            $node = $parent->ownerDocument->importNode($node, true);
            $parent->appendChild($node);
        }
    }
    private function handleRawHtml(DOMNode $node) {
        /** @var DOMElement $node */
        if ($node->hasAttribute('v-html')) {
            $expr = $node->getAttribute('v-html');
            $node->removeAttribute('v-html');
            $phpExpr = ConvertJsExpression::convert($expr);
            $text = static::PHPOPEN . ' echo (' . $phpExpr . '); ' . static::PHPEND;
            $node->textContent = $text;
        }
    }

    private function getBodyHtml($dom) {
        $html = '';
        $subNodes = iterator_to_array($dom->getElementsByTagName('body')->item(0)->childNodes);
        foreach ($subNodes as $index => $childNode) {
            $html .= $dom->saveHTML($childNode);
        }
        return $html;
    }

    private function removeNode(DOMElement $node) {
        $node->parentNode->removeChild($node);
    }
    /**
     * @param DOMNode $node
     *
     * @return bool
     */
    private function isElementNode(DOMNode $node) {
        return $node->nodeType === 1;
    }
    // private function isTextNode(DOMNode $node) {
    //     return $node instanceof DOMCharacterData;
    // }
    private function isRemovedFromTheDom(DOMNode $node) {
        return $node->parentNode === null;
    }

    private function setErrorHint($line, $expression) {
        $this->errorLine = $line;
        $this->errorExpression = $expression;
    }

}