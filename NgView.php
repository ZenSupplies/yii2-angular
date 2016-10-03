<?php

namespace dee\angular;

use Yii;
use yii\helpers\Html;
use yii\helpers\ArrayHelper;
use yii\helpers\Json;
use yii\helpers\Inflector;
use yii\web\View as WebView;
use yii\base\Widget;
use yii\helpers\Url;
use yii\web\AssetBundle;

/**
 * Description of NgView
 *
 * 
 * @author Misbahul D Munir <misbahuldmunir@gmail.com>
 * @since 1.0
 */
class NgView extends Widget
{
    /**
     *
     * @var array
     */
    public $routes = [];

    /**
     *
     * @var array
     */
    public $resources = [];

    /**
     *
     * @var string
     */
    public $name = 'dApp';

    /**
     * @var boolean If `true` will render attribute `ng-app="appName"` in widget.
     */
    public $useNgApp = true;

    /**
     * @var array
     */
    public $requires = [];

    /**
     * @var string
     */
    public $tag = 'ng-view';

    /**
     *
     * @var string
     */
    public $js;

    /**
     *
     * @var string
     */
    public $controller;

    /**
     * @var array
     */
    public $clientOptions;

    /**
     * @var array 
     */
    public $injection = ['$scope', '$injector'];

    /**
     * @var boolean
     */
    public $remote;

    /**
     * @var string 
     */
    public $queryParam = '_ng-view';

    /**
     * @var array
     */
    public static $requireAssets = [
        'ui.bootstrap' => 'dee\angular\AngularBootstrapAsset',
        'dee.ui' => 'dee\angular\DeeAngularUiAsset',
        'dee.rest' => 'dee\angular\DeeAngularRestAsset',
        'ngRoute' => 'dee\angular\AngularRouteAsset',
        'ngResource' => 'dee\angular\AngularResourceAsset',
        'ngAnimate' => 'dee\angular\AngularAnimateAsset',
        'ngAria' => 'dee\angular\AngularAnimateAsset',
        'ngTouch' => 'dee\angular\AngularAnimateAsset',
        'validation' => 'dee\angular\AngularValidationAsset',
        'validation.rule' => 'dee\angular\AngularValidationAsset',
    ];
    private $_varName;

    /**
     *
     * @var static 
     */
    public static $instance;

    /**
     * @inheritdoc
     */
    public function init()
    {
        static::$instance = $this;
        $this->_varName = Inflector::variablize($this->name);

        $this->requires[] = 'ngRoute';
        if (!empty($this->resources)) {
            $this->requires[] = 'ngResource';
        }
        $this->requires = array_unique($this->requires);
    }

    /**
     * @inheritdoc
     */
    public function run()
    {
        $view = $this->getView();
        if (!$this->remote || Yii::$app->getRequest()->get($this->queryParam) === 'script') {
            $js = $this->generate();
            if ($this->remote) {
                $response = Yii::$app->getResponse();
                $response->format = 'raw';
                $response->getHeaders()->set('Content-Type', 'application/javascript');
                $response->content = $js;
                $response->send();
                Yii::$app->end();
            } else {
                $view->registerJs($js, WebView::POS_END);
            }
        }
	// Asset Dependency
        if (Yii::$app->getAssetManager()->bundles !== false) {
            $key = md5(Yii::$app->controller->route . $this->name);
            $bundle = [
                'baseUrl' => '',
                'depends' => [AngularAsset::className()],
                'js' => [],
            ];
            foreach ($this->requires as $module) {
                if (isset(static::$requireAssets[$module])) {
                    $bundle['depends'][] = static::$requireAssets[$module];
                }
            }
            if ($this->remote) {
                $key = md5(Yii::$app->controller->route . $this->name);
                $url = Url::current([$this->queryParam => 'script']);
                $bundle['js'][] = strncmp($url, '//', 2) === 0 ? $url : ltrim($url, '/');
            }
            Yii::$app->getAssetManager()->bundles[$key] = new AssetBundle($bundle);
            $view->registerAssetBundle($key);
        }

        static::$instance = null;
        return Html::tag($this->tag, '', ['ng-app' => $this->useNgApp ? $this->name : false, 'ng-view' => $this->tag != 'ng-view']);
    }

    protected function generate()
    {
        $view = $this->getView();

        $routeProviders = [];
        $controllers = [];
        $templates = [];
        foreach ($this->routes as $path => $route) {
            $visible = ArrayHelper::remove($route, 'visible', true);
            list($routeProvider, $controller, $template) = $this->applyRoute($route, $path);

            if ($path === 'otherwise') {
                $routeProviders[] = "\$routeProvider.otherwise({$routeProvider});";
            } elseif ($visible) {
                $p = Json::htmlEncode($path);
                $routeProviders[] = "\$routeProvider.when({$p},{$routeProvider});";
            }
            if ($controller) {
                $controllers[$controller[0]] = $controller[1];
            }
            if ($template) {
                $templates[$path] = $template;
            }
        }

        $js = [];
        $js[] = "{$this->_varName} = (function(options){";
        $js[] = $this->renderModule();
        $js[] = $this->renderTemplates($templates);
        $js[] = $this->renderRouteProviders($routeProviders);
        $js[] = $this->renderControllers($controllers);
        $js[] = $this->renderResources();
        if ($this->js !== null) {
            foreach ((array) $this->js as $file) {
                $js[] = "\n" . static::parseBlockJs($view->render($file));
            }
        }

        $options = empty($this->clientOptions) ? '{}' : Json::htmlEncode($this->clientOptions);
        $js[] = "\nreturn module;\n})({$options});";


        return implode("\n", $js);
    }

    protected function applyRoute($route, $path)
    {
        $view = $this->getView();
        $routeProvider = $controller = $template = null;

        if (is_string($route)) {
            $routeProvider = Json::htmlEncode(['redirectTo' => $route]);
        } elseif (isset($route['link'])) {
            $link = Json::htmlEncode($route['link']);
            unset($route['link'], $route['view'], $route['controller'], $route['visible']);
            $route = Json::htmlEncode($route);
            $routeProvider = "angular.extend({},module.templates[{$link}],{$route})";
        } else {
            $injection = ArrayHelper::remove($route, 'injection', []);

            if (empty($route['controller'])) {
                $route['controller'] = Inflector::camelize($path) . 'Ctrl';
            }
            $this->controller = $route['controller'];
            $controller = [$this->controller, $injection];

            if (isset($route['js'])) {
                $this->renderJs($route['js']);
                unset($route['js']);
            }
            if (isset($route['view'])) {
                $route['template'] = $view->render($route['view'], ['widget' => $this]);
                unset($route['view']);
            }
            $template = $route;

            $path = Json::htmlEncode($path);
            $routeProvider = "module.templates[{$path}]";
        }
        $this->controller = null;
        return [$routeProvider, $controller, $template];
    }

    /**
     * Render script create module. The result are
     * ```javascript
     * module = angular.module('appName',[requires,...]);
     * ```
     */
    protected function renderModule()
    {
        $js = "var module = angular.module('{$this->name}'," . Json::htmlEncode($this->requires) . ");\n"
            . "var {$this->_varName} = module;";
        return $js;
    }

    protected function renderTemplates($templates)
    {
        return "module.templates = " . Json::htmlEncode($templates) . ';';
    }

    /**
     * Render script config for $routeProvider
     * @param array $routeProviders
     */
    protected function renderRouteProviders($routeProviders)
    {
        $routeProviders = implode("\n", $routeProviders);
        return "module.config(['\$routeProvider',function(\$routeProvider){\n{$routeProviders}\n}]);";
    }

    /**
     * Render script create controllers
     * ```javascript
     * module.controller('CtrlName',['$scope',...,
     *     function($scope,...){
     *         ...
     *     }]);
     * ```
     * @param array $controllers
     */
    protected function renderControllers($controllers)
    {
        $js = [];
        $view = $this->getView();
        foreach ($controllers as $name => $injection) {
            $injection = array_unique(array_merge($this->injection, (array) $injection));
            $injectionStr = rtrim(Json::htmlEncode($injection), ']');
            $injectionVar = implode(", ", $injection);
            $function = implode("\n", ArrayHelper::getValue($view->js, $name, []));
            $js[] = "module.controller('$name',{$injectionStr},\nfunction($injectionVar){\n{$function}\n}]);";
        }
        return implode("\n", $js);
    }

    /**
     * Render script resource
     * ```javascript
     * module.factory(ResName,['$resource',function($resource){
     *     return ...;
     * }]);
     * ```
     */
    protected function renderResources()
    {
        if (!empty($this->resources)) {
            $js = [];
            foreach ($this->resources as $name => $config) {
                $url = Json::htmlEncode($config['url']);
                if (empty($config['paramDefaults'])) {
                    $paramDefaults = '{}';
                } else {
                    $paramDefaults = Json::htmlEncode($config['paramDefaults']);
                }
                if (empty($config['actions'])) {
                    $actions = '{}';
                } else {
                    $actions = Json::htmlEncode($config['actions']);
                }

                $js[] = <<<JS
module.factory('$name',['\$resource',function(\$resource){
    return \$resource({$url},{$paramDefaults},{$actions});
}]);
JS;
            }
            return implode("\n", $js);
        }
        return '';
    }

    /**
     * Only get script inner of `script` tag.
     * @param string $js
     * @return string
     */
    public static function parseBlockJs($js)
    {
        $jsBlockPattern = '|^<script[^>]*>(?P<block_content>.+?)</script>$|is';
        if (preg_match($jsBlockPattern, trim($js), $matches)) {
            $js = trim($matches['block_content']);
        }
        return $js;
    }

    /**
     * Register script to controller.
     * 
     * @param string $viewFile
     * @param array $params
     * @param integer|string $pos
     */
    public function renderJs($viewFile, $params = [], $pos = null)
    {
        $params['widget'] = $this;
        $js = $this->view->render($viewFile, $params);
        $this->registerJs($js, $pos);
    }

    /**
     * Register script to controller.
     *
     * @param string $js
     * @param integer|string $pos
     */
    public function registerJs($js, $pos = null)
    {
        $pos = $pos ? : ($this->controller ? : WebView::POS_END);
        $this->view->registerJs(static::parseBlockJs($js), $pos);
    }
}
