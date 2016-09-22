<?php
namespace enchikiben\sitemap;

use Yii;
use yii\base\Exception;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use yii\helpers\Url;
use yii\helpers\Html;
use yii\log\Logger;
use SimpleXMLElement;
use DateTime;
use ReflectionClass;

class SitemapGenerator
{
    public $defaultChangefreq = 'monthly';
    public $defaultPriority = 0.8;
    public $defaultLastmod;

    private $_sitemap;
    private $_aliases = [];

    public function __construct($aliases)
    {
        if (!class_exists('SimpleXMLElement'))
            throw new Exception(Yii::t('sitemap', 'SimpleXML extension is required.'));

        if (!class_exists('ReflectionClass'))
            throw new Exception(Yii::t('sitemap', 'Reflection extension is required.'));

        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';

        $this->_sitemap = new SimpleXMLElement($xml);

        if (is_array($aliases)) {
            $this->_aliases = ArrayHelper::merge($this->_aliases, $aliases);
        } else {
            $this->_aliases[] = $aliases;
        }

    }

    private function scanControllersAliases()
    {
        if (empty($this->_aliases))
            throw new Exception(Yii::t('sitemap', 'Controllers aliases is not set.'));

        foreach ($this->_aliases as $k => $v) {
            $this->scanControllers($v);
        }
    }

    private function extractNamespace($file)
    {
        $ns = NULL;
        $handle = fopen($file, "r");
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                if (strpos($line, 'namespace') === 0) {
                    $parts = explode(' ', $line);
                    $ns = rtrim(trim($parts[1]), ';');
                    break;
                }
            }
            fclose($handle);
        }
        return $ns;
    }

    private function scanControllers($alias)
    {
        $path = Yii::getAlias($alias);

        if (empty($path))
            throw new Exception(Yii::t('sitemap', "Alias path not founded. Alias: '{alias}'", array('{alias}' => $alias)));

        if (is_dir($path)) {
            $files = FileHelper::findFiles(Yii::getAlias($alias));
            foreach ($files as $file)
                if (($pos = strpos($file, 'Controller')) !== false) {
                    $namespace = $this->extractNamespace($file);
                    $controller = $namespace . "\\" . basename($file, ".php");
                    $this->parseController($controller);
                }
        } else
            throw new Exception(Yii::t('sitemap', "Alias is not directory or file. Alias: '{alias}'", array('{alias}' => $alias)));
    }

    private function parseParamsString($string)
    {
        $raw = explode(' ', trim($string));
        $raw = array_filter($raw);
        $data = array();
        foreach ($raw as $param) {
            list($key, $val) = explode('=', $param, 2);

            if (empty($val))
                throw new Exception(Yii::t('sitemap', "Option '{key}' cannot be empty.", array('{key}' => $key)));

            $data[$key] = $val;
        }
        return $data;
    }

    private function normalizeName($name)
    {
        preg_match_all('/[A-Z]+/', $name, $results);
        if (isset($results[0]) && is_array($results[0]) && !empty($results[0])) {
            foreach ($results[0] as $result) {
                $name = str_replace($result, "-" . strtolower($result), $name);
            }
        }
        return $name;
    }

    private function createRoute($controllerName, $actionMethodName)
    {
        $route = explode('\\', $controllerName);
        $action = lcfirst(substr($actionMethodName, strlen('action')));
        $controller = lcfirst(substr(array_pop($route), 0, -strlen('Controller')));
        $route = [];
        $route[] = $this->normalizeName($controller);
        $route[] = $this->normalizeName($action);
        return '/' . implode('/', $route);
    }

    private function evalParam($param, $model = null)
    {
        ob_start();
        $result = eval('return ' . $param . ';');
        ob_end_clean();
        if ($result === false)
            throw new Exception(Yii::t('sitemap', 'Error occured while trying to eval() model expression. Expression was: {value}', array('{value}' => $param)));
        return $result;
    }

    private function parseController($controller)
    {
        $cntr = new ReflectionClass($controller);
        $controller_instance = null;
        $methods = $cntr->getMethods();

        foreach ($methods as $m) {
            $comment = $m->getDocComment();
            if (strpos($comment, '@sitemap') !== false) {        // Precheck with quick function
                $results = array();
                preg_match_all('/@sitemap(.*)/u', $comment, $results);

                foreach ($results[1] as $result) {
                    // Parse params
                    $params = (!empty($result)) ? $this->parseParamsString($result) : array();
                    $action = $m->name;

                    if (isset($params['route'], $params['model'])) {
                        $models = new $params['model'];
                        $condition = $this->evalParam($params['condition']);

                        foreach ($models->find()->where($condition)->all() as $model) {
                            $defaultParams = $params;
                            $defaultParams['route'] = $this->evalParam($params['route'], $model);

                            $this->parseUrls($defaultParams);
                        }
                        continue;
                    } elseif (!isset($params['loc'])) {
                        $params['route'] = [$this->createRoute($cntr->getName(), $action)];
                    }

                    $this->parseUrls($params);
                }
            }
        }
    }

    private function getDefaultLastmod()
    {
        return date(DATE_W3C);
    }


    private function parseUrls($data)
    {
        if (isset($data['loc'])) $default['loc'] = $data['loc'];
        if (isset($data['route'])) $default['route'] = $data['route'];

        $default['priority'] = isset($data['priority']) ? $data['priority'] : $this->defaultPriority;
        $default['changefreq'] = isset($data['changefreq']) ? $data['changefreq'] : $this->defaultChangefreq;
        $default['lastmod'] = isset($data['lastmod']) ? $data['lastmod'] : $this->getDefaultLastmod();
        $default['params'] = [];

        $this->addUrl($default);
    }

    private function addUrl($params)
    {
        try {
            $link = !isset($params['loc']) ? Url::to($params['route'], true) : $params['loc'];

            $xmlurl = $this->_sitemap->addChild('url');
            $xmlurl->addChild('loc', Html::encode($link));
            $xmlurl->addChild('lastmod', $this->formatDatetime($params['lastmod']));
            $xmlurl->addChild('changefreq', $params['changefreq']);
            $xmlurl->addChild('priority', $params['priority']);

        } catch (Exception $e) {
            self::logExceptionError($e);
            if (YII_DEBUG) throw $e;
        }
    }

    public static function logExceptionError($e)
    {
        Yii::error(Yii::t('sitemap', 'SitemapGenerator error: {error}', ['{error}' => $e->getMessage()]), 'app.sitemap');
    }

    private function formatDatetime($val)
    {
        try {
            if (is_int($val)) {
                $result = date(DATE_W3C, $val);
            } elseif (is_string($val)) {
                $dt = new DateTime($val);
                $result = $dt->format(DateTime::W3C);
                if ($result === false)
                    throw new Exception(Yii::t('sitemap', 'Unable to format datetime object. Datetime value: {value}', array('{value}' => $val)));
            }
        } catch (Exception $e) {
            throw new Exception(Yii::t('sitemap', 'Unable to parse given datetime. Error: {error}', array('{error}' => $e->getMessage())));
        }
        return $result;
    }

    public function getAsXml()
    {
        $this->scanControllersAliases();
        return $this->_sitemap->asXML();
    }
}