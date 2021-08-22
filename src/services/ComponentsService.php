<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\sprig\services;

use Craft;
use craft\base\Component as BaseComponent;
use craft\base\ElementInterface;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\Template;
use craft\helpers\UrlHelper;
use craft\web\Request;
use craft\web\View;
use IvoPetkov\HTML5DOMDocument;
use IvoPetkov\HTML5DOMElement;
use putyourlightson\sprig\base\Component;
use putyourlightson\sprig\components\SprigPlayground;
use putyourlightson\sprig\errors\InvalidVariableException;
use putyourlightson\sprig\events\ComponentEvent;
use putyourlightson\sprig\Sprig;
use Twig\Markup;
use yii\base\Model;
use yii\web\BadRequestHttpException;

class ComponentsService extends BaseComponent
{
    /**
     * @event ComponentEvent
     */
    const EVENT_BEFORE_CREATE_COMPONENT = 'beforeCreateComponent';

    /**
     * @event ComponentEvent
     */
    const EVENT_AFTER_CREATE_COMPONENT = 'afterCreateComponent';

    /**
     * @const string
     */
    const COMPONENT_NAMESPACE = 'sprig\\components\\';

    /**
     * @const string
     */
    const RENDER_CONTROLLER_ACTION = 'sprig/components/render';

    /**
     * @const string[]
     */
    const SPRIG_PREFIXES = ['s', 'sprig', 'data-s', 'data-sprig'];

    /**
     * @const string[]
     */
    const HTMX_ATTRIBUTES = ['boost', 'confirm', 'delete', 'disable', 'encoding', 'ext', 'get', 'headers', 'history-elt', 'include', 'indicator', 'params', 'patch', 'post', 'preserve', 'prompt', 'push-url', 'put', 'request', 'select', 'sse', 'swap', 'swap-oob', 'target', 'trigger', 'vals', 'vars', 'ws'];

    /**
     * @var string
     */
    private $_hxPrefix = '';

    public function init()
    {
        parent::init();

        if (Sprig::$plugin->settings->hxDataPrefix === true) {
            $this->_hxPrefix = 'data-';
        }
    }

    /**
     * Creates a new component.
     *
     * @param string $value
     * @param array $variables
     * @param array $attributes
     * @return Markup
     */
    public function create(string $value, array $variables = [], array $attributes = []): Markup
    {
        $values = [];

        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $values['sprig:siteId'] = Craft::$app->getSecurity()->hashData($siteId);

        $mergedVariables = array_merge(
            $variables,
            Sprig::$plugin->request->getVariables()
        );

        $event = new ComponentEvent([
            'value' => $value,
            'variables' => $mergedVariables,
            'attributes' => $attributes,
        ]);
        $this->trigger(self::EVENT_BEFORE_CREATE_COMPONENT, $event);

        // Repopulate values from event
        $value = $event->value;
        $mergedVariables = $event->variables;
        $attributes = $event->attributes;

        $componentObject = $this->createObject($value, $mergedVariables);

        if ($componentObject) {
            $type = 'component';
            $renderedContent = $componentObject->render();
        }
        else {
            $type = 'template';

            if (!Craft::$app->getView()->doesTemplateExist($value)) {
                throw new BadRequestHttpException(Craft::t('sprig', 'Unable to find the component or template “{value}”.', [
                    'value' => $value,
                ]));
            }

            $renderedContent = Craft::$app->getView()->renderTemplate($value, $mergedVariables);
        }

        $content = $this->parseHtml($renderedContent);

        $values['sprig:'.$type] = Craft::$app->getSecurity()->hashData($value);

        foreach ($variables as $name => $val) {
            $values['sprig:variables['.$name.']'] = $this->_hashVariable($name, $val);
        }

        // Allow ID to be overridden, otherwise ensure random ID does not start with a digit (to avoid a JS error)
        $id = $attributes['id'] ?? ('component-'.StringHelper::randomString(6));

        // Merge base attributes with provided attributes first, to ensure that `hx-vals` is included in the attributes when they are parsed.
        $attributes = array_merge(
            [
                'id' => $id,
                'class' => 'sprig-component',
                $this->_hxPrefix.'hx-target' => 'this',
                $this->_hxPrefix.'hx-include' => '#'.$id.' *',
                $this->_hxPrefix.'hx-trigger' => 'refresh',
                $this->_hxPrefix.'hx-get' => UrlHelper::actionUrl(self::RENDER_CONTROLLER_ACTION),
                $this->_hxPrefix.'hx-vals' => Json::htmlEncode($values),
            ],
            $attributes
        );

        $this->_parseAttributes($attributes);

        $event->output = Html::tag('div', $content, $attributes);

        if ($this->hasEventHandlers(self::EVENT_AFTER_CREATE_COMPONENT)) {
            $this->trigger(self::EVENT_AFTER_CREATE_COMPONENT, $event);
        }

        return Template::raw($event->output);
    }

    /**
     * Creates a new component object with the provided variables.
     *
     * @param string $component
     * @param array $variables
     * @return Component|null
     */
    public function createObject(string $component, array $variables = [])
    {
        if ($component == 'SprigPlayground') {
            return new SprigPlayground(['variables' => $variables]);
        }

        $componentClass = self::COMPONENT_NAMESPACE.$component;

        if (!class_exists($componentClass)) {
            return null;
        }

        if (!is_subclass_of($componentClass, Component::class)) {
            throw new BadRequestHttpException(Craft::t('sprig', 'Component class “{class}” must extend “{extendsClass}”.', [
                'class' => $componentClass,
                'extendsClass' => Component::class,
            ]));
        }

        /** @var Component $component */
        $component = Craft::createObject([
            'class' => $componentClass,
            'attributes' => $variables,
        ]);

        return $component;
    }

    /**
     * Parses and returns HTML.
     *
     * @param string $html
     * @return string
     */
    public function parseHtml(string $html): string
    {
        if (empty(trim($html))) {
            return $html;
        }

        // Use HTML5DOMDocument which supports HTML5 and takes care of UTF-8 encoding.
        $dom = new HTML5DOMDocument();

        // Surround html with body tag to ensure script tags are not tampered with.
        // https://github.com/putyourlightson/craft-sprig/issues/34
        $html = '<!doctype html><html lang=""><body>'.$html.'</body></html>';

        // Allow duplicate IDs to avoid an error being thrown.
        // https://github.com/ivopetkov/html5-dom-document-php/issues/21
        $dom->loadHTML($html, HTML5DOMDocument::ALLOW_DUPLICATE_IDS);

        /** @var HTML5DOMElement $element */
        foreach ($dom->getElementsByTagName('*') as $element) {
            $attributes = $element->getAttributes();

            $this->_parseAttributes($attributes);

            foreach ($attributes as $attribute => $value) {
                $element->setAttribute($attribute, $value);
            }
        }

        $output = $dom->getElementsByTagName('body')[0]->innerHTML;

        // Solves HTML entities being double encoded.
        // https://github.com/putyourlightson/craft-sprig/issues/133#issuecomment-840662721
        $output = str_replace('&amp;#', '&#', $output);

        return $output;
    }

    /**
     * Parses an array of attributes.
     *
     * @param array $attributes
     */
    public function _parseAttributes(array &$attributes)
    {
        $this->_parseSprigAttribute($attributes);

        foreach ($attributes as $key => $value) {
            $this->_parseAttribute($attributes, $key, $value);
        }
    }

    /**
     * Parses the Sprig attribute on an array of attributes.
     *
     * @param array $attributes
     */
    private function _parseSprigAttribute(array &$attributes)
    {
        // Use `!isset` over `!empty` because the attributes value will be an empty string
        if (!isset($attributes['sprig']) && !isset($attributes['data-sprig'])) {
            return;
        }

        $verb = 'get';
        $params = [];

        $method = $this->_getSprigAttributeValue($attributes, 'method');

        // Make the check case-insensitive
        if (strtolower($method) == 'post') {
            $verb = 'post';

            $this->_mergeJsonAttributes($attributes, 'headers', [
                Request::CSRF_HEADER => Craft::$app->getRequest()->getCsrfToken(),
            ]);
        }

        $action = $this->_getSprigAttributeValue($attributes, 'action');

        if ($action) {
            $params['sprig:action'] = Craft::$app->getSecurity()->hashData($action);
        }

        $attributes[$this->_hxPrefix.'hx-'.$verb] = UrlHelper::actionUrl(self::RENDER_CONTROLLER_ACTION, $params);
    }

    /**
     * Parses an attribute in an array of attributes.
     *
     * @param array $attributes
     * @param string $key
     * @param string $value
     */
    public function _parseAttribute(array &$attributes, string $key, string $value)
    {
        $name = $this->_getSprigAttributeName($key);

        if (!$name) {
            return;
        }

        if (strpos($name, 'val:') === 0) {
            $name = StringHelper::toCamelCase(substr($name, 4));

            $this->_mergeJsonAttributes($attributes, 'vals', [$name => $value]);
        }
        elseif ($name == 'replace') {
            $attributes[$this->_hxPrefix.'hx-select'] = $value;
            $attributes[$this->_hxPrefix.'hx-target'] = $value;
            $attributes[$this->_hxPrefix.'hx-swap'] = 'outerHTML';
        }
        elseif (in_array($name, self::HTMX_ATTRIBUTES)) {
            if ($name == 'headers' || $name == 'vals') {
                $this->_mergeJsonAttributes($attributes, $name, $value);
            }
            else {
                $attributes[$this->_hxPrefix.'hx-'.$name] = $value;
            }

            // Deprecate `s-vars`
            if ($name == 'vars') {
                Craft::$app->getDeprecator()->log(__METHOD__.':vars', 'The “s-vars” attribute in Sprig components has been deprecated for security reasons. Use the new “s-vals” or “s-val:*” attribute instead.');
            }
        }
    }

    /**
     * Merges new values to existing JSON attribute values.
     *
     * @param array $attributes
     * @param string $name
     * @param array|string $values
     */
    private function _mergeJsonAttributes(array &$attributes, string $name, $values)
    {
        if (is_string($values)) {
            if (strpos($values, 'javascript:') === 0) {
                throw new BadRequestHttpException('The “s-'.$name.'” attribute in Sprig components may not contain a “javascript:” prefix for security reasons. Use a JSON encoded value instead.');
            }

            $values = Json::decode($values);
        }

        $key = $this->_hxPrefix.'hx-'.$name;

        if (!empty($attributes[$key])) {
            $values = array_merge(Json::decode($attributes[$key]), $values);
        }

        $attributes[$key] = Json::htmlEncode($values);
    }

    /**
     * Returns a Sprig attribute name if it exists.
     *
     * @param string $key
     * @return string
     */
    private function _getSprigAttributeName(string $key): string
    {
        foreach (self::SPRIG_PREFIXES as $prefix) {
            if (strpos($key, $prefix.'-') === 0) {
                return substr($key, strlen($prefix) + 1);
            }
        }

        return '';
    }

    /**
     * Returns a Sprig attribute value if it exists.
     *
     * @param array $attributes
     * @param string $name
     * @return string
     */
    private function _getSprigAttributeValue(array $attributes, string $name): string
    {
        foreach (self::SPRIG_PREFIXES as $prefix) {
            if (!empty($attributes[$prefix.'-'.$name])) {
                return $attributes[$prefix.'-'.$name];
            }
        }

        return '';
    }

    /**
     * Hashes a variable, possibly throwing an exception.
     *
     * @param string $name
     * @param mixed $value
     * @return string
     * @throws InvalidVariableException
     */
    private function _hashVariable(string $name, $value): string
    {
        $this->_validateVariableType($name, $value);

        if (is_array($value)) {
            $value = Json::encode($value);
        }

        return Craft::$app->getSecurity()->hashData($value);
    }

    private function _validateVariableType(string $name, $value, $isArray = false)
    {
        $variable = [
            'name' => $name,
            'value' => $value,
            'isArray' => $isArray,
        ];

        if ($value instanceof ElementInterface) {
            throw new InvalidVariableException(
                $this->_getError('variable-element', $variable)
            );
        }

        if ($value instanceof Model) {
            throw new InvalidVariableException(
                $this->_getError('variable-model', $variable)
            );
        }

        if (is_object($value)) {
            throw new InvalidVariableException(
                $this->_getError('variable-object', $variable)
            );
        }

        if (is_array($value)) {
            foreach ($value as $arrayValue) {
                $this->_validateVariableType($name, $arrayValue, true);
            }
        }
    }

    /**
     * Returns an error from a rendered template.
     *
     * @param string $templateName
     * @param array $variables
     * @return string
     */
    private function _getError(string $templateName, array $variables = []): string
    {
        $template = 'sprig/_errors/'.$templateName;

        return Craft::$app->getView()->renderTemplate($template, $variables, View::TEMPLATE_MODE_CP);
    }
}
