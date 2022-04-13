<?php
namespace verbb\vizy\models;

use verbb\vizy\Vizy;
use verbb\vizy\helpers\Nodes;

use Craft;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\helpers\ArrayHelper;
use craft\helpers\Component as ComponentHelper;
use craft\helpers\Template;

use yii2mod\query\ArrayQuery;
use Twig\Markup;

class NodeCollection extends Markup
{
    // Properties
    // =========================================================================

    private $element;
    private $field;
    private $nodes = [];
    private $rawNodes = [];
    
    private $_content;
    private $_registeredNodesByType = [];
    private $_registeredMarksByType = [];


    // Public Methods
    // =========================================================================

    public function __construct($field, $nodes = [], $element = null)
    {
        // Handle emoji's and un-serialize them
        foreach ($nodes as $key => $node) {
            $nodes[$key] = Nodes::normalizeContent($node);
        }

        $this->element = $element;
        $this->field = $field;
        $this->rawNodes = $nodes;

        // Save here as we're recursively populating nodes/marks
        $this->_registeredNodesByType = Vizy::$plugin->getNodes()->getRegisteredNodesByType();
        $this->_registeredMarksByType = Vizy::$plugin->getNodes()->getRegisteredMarksByType();

        // Prepare node/mark classes for the collection
        $this->nodes = $this->_populateNodes($nodes);
    }

    public function __toString()
    {
        if (!$this->_content) {
            $this->_content = (string)$this->renderHtml();
        }

        // Prevent everyone from having to use the `| raw` filter when outputting RTE content
        return $this->_content;
    }

    public function count()
    {
        if (!$this->_content) {
            $this->_content = (string)$this->renderHtml();
        }

        return mb_strlen($this->_content, Craft::$app->charset);
    }

    public function getNodes()
    {
        return $this->nodes;
    }

    public function getField()
    {
        return $this->field;
    }

    public function getRawNodes()
    {
        return $this->rawNodes;
    }

    public function renderHtml($config = [])
    {
        $html = [];

        $this->_prepNodesForHtml($config);

        foreach ($this->getNodes() as $node) {
            $html[] = $node->renderHtml();
        }

        $html = join($html);

        // Is this a completely empty field (we always have an empty paragraph)?
        if (str_replace(['<p>', '</p>'], '', $html) === '') {
            $html = '';
        }

        return Template::raw($html);
    }

    public function renderStaticHtml($config = [])
    {
        $html = [];

        $this->_prepNodesForHtml($config);

        foreach ($this->getNodes() as $node) {
            $html[] = $node->renderStaticHtml();
        }

        $html = join($html);

        return Template::raw($html);
    }

    public function all()
    {
        return $this->query()->all();
    }

    public function query()
    {
        $arrayQuery = new ArrayQuery();
        $arrayQuery->primaryKeyName = 'type';

        return $arrayQuery->from($this->getNodes())->where(['enabled' => true]);
    }

    public function serializeValues(ElementInterface $element = null)
    {
        $values = [];

        foreach ($this->getNodes() as $nodeKey => $node) {
            $rawNode = $node->serializeValue($element);

            // Handle serializing any emoji's in text nodes
            $values[$nodeKey] = Nodes::serializeContent($rawNode);
        }

        $values = array_values(array_filter($values));

        return $values;
    }

    public function isEmpty()
    {
        // Don't rely on `renderHtml()` as this is trigger on-load in the CP when editing a field
        // and it does plenty of unnesesary things. Instead, work with the `rawNodes` directly.
        $results = [];

        foreach ($this->getNodes() as $node) {
            $results[] = $node->isEmpty();
        }

        // Are _all_ the results the same?
        return (bool)array_product($results);
    }


    // Private Methods
    // =========================================================================

    private static function configure($object, $properties, $merge)
    {
        foreach ($properties as $name => $value) {
            if ($merge) {
                $value = array_merge($object->$name, $value);
            }

            $object->$name = $value;
        }

        return $object;
    }

    private function _populateNodes($nodes)
    {
        $result = [];

        foreach ($nodes as $nodeKey => $node) {
            $rawNode = $node;

            // Drill into any nested nodes first
            if (isset($node['content'])) {
                $node['content'] = $this->_populateNodes($node['content']);
            }

            // Handle initalizing nested marks
            if (isset($node['marks'])) {
                foreach ($node['marks'] as $markKey => $mark) {
                    if ($class = ($this->_registeredMarksByType[$mark['type']] ?? null)) {
                        unset($mark['type']);

                        $node['marks'][$markKey] = Craft::createObject(array_merge($mark, [
                            'class' => $class,
                            'field' => $this->field,
                            'element' => $this->element,
                        ]));
                    } else {
                        // If an un-registered mark, drop it
                        unset($node['marks'][$markKey]);
                    }
                }
            }

            if ($class = ($this->_registeredNodesByType[$node['type']] ?? null)) {
                unset($node['type']);
                
                $nodeClass = Craft::createObject(array_merge($node, [
                    'class' => $class,
                    'field' => $this->field,
                    'element' => $this->element,
                    'rawNode' => $rawNode,
                ]));

                if (!$nodeClass->isDeleted()) {
                    $result[] = $nodeClass;
                }
            } else {
                // If an un-registered node, drop it
                unset($nodes[$nodeKey]);
            }
        }

        return $result;
    }

    private function _prepNodesForHtml($config = [])
    {
        foreach ($this->getNodes() as $node) {
            // Apply any node config set in templates
            foreach ($config as $type => $nodeConfig) {
                if ($node->getType() === $type) {
                    // Extract any mark config and apply to all marks of matching type
                    // Also remove it from the config so it doesn't clash with the `marks` prop.
                    $marksConfig = ArrayHelper::remove($nodeConfig, 'marks');

                    foreach ($node->content as $nodeContent) {
                        foreach ($nodeContent->marks as $mark) {
                            $markConfig = $marksConfig[$mark->getType()] ?? [];

                            if ($markConfig) {
                                // Check if we want to merge attributes, instead of replace. Useful for attrs.
                                $merge = ArrayHelper::remove($markConfig, 'merge');

                                self::configure($mark, $markConfig, $merge);
                            }
                        }
                    }

                    // Check if we want to merge attributes, instead of replace. Useful for attrs.
                    $merge = ArrayHelper::remove($nodeConfig, 'merge');

                    self::configure($node, $nodeConfig, $merge);
                }
            }
        }
    }

}
