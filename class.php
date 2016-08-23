<?php
namespace DigitalWand\MVC;

use Bitrix\Main\Application;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\NotImplementedException;

include "AjaxException.php";
Loc::loadMessages(__FILE__);

/**
 * Class BaseComponent
 * @package DigitalWand\MVC
 *
 * Базовый компонент, предназначенный для простой реализации MVC. Основные функции:
 * <ul>
 * <li>Обработка ЧПУ "из коробки" (при условии, что параметры компонента правильно настроены при подключении)</li>
 * <li>Обработка входящих запросов в MVC-стиле</li>
 * <li>Обработка AJAX в коде компонента</li>
 * <li>Обработка ошибок, перехват исключений</li>
 * <li>Возможность наследования</li>
 * <li>Легковесность, для начала работы достаточно знать API битрикс и прочитать докуменацию к функциям класса</li>
 * </ul>
 *
 * Компонент принимает дополнительные параметры:
 * <ul>
 * <li>AJAX_CHECK_SESSID - Проверяет sessid при AJAX запросах.
 * Отправка сгенерированного sessid остаётся на совести программиста, поэтому по-умолчанию данная функция выключена.
 * К тому же на клиенте есть неприятности в IE8
 * </li>
 * <li>VERBOSE - Подробный вывод данных исключений. Отражается только на AJAX запросах.
 * По-умолчанию выключен, т.к. в production-режиме пользователю ни к чему знать, в какой строке какого кода было выброшено исключение</li>
 * <li>CACHE_ACTION - массив с описанием правил кеширования экшенов контроллера. Ключ - имя контроллера.
 * Значение: Y - если кешировать, N - не кешировть, любая другая строка или функция заполнят $additionalCacheId</li>
 * </ul>
 */
class BaseComponent extends \CBitrixComponent
{
    const ERR_404 = 0;
    const ERR_EXCEPTION = 1;
    /**
     * Флаг пропуска исполнения AJAX.
     */
    const SKIP_AJAX_EXECUTION = null;
    /**
     * @var array $internalComponentParams - параметры компонента по-умолчанию
     * При ыборе между заданием настроек компонента в данном масиве и в .parameters.php стоит руководствоваться
     * критерием: должен ли данный параметр меняться поьзователем/редактором из публичной части, или нет.
     * В первом случае стоит отдать предпочтение .parameters.php, во втором - данной переменной.
     *
     * Возможность переопределить эти параметры из публичной части всё равно остаётся, науке пока не изметно, баг это или фича...
     */
    static protected $internalComponentParams = array(
        'AJAX_CHECK_SESSID' => 'N',
        'VERBOSE' => 'N',
        'CACHE_ACTION' => [],
        'ACTION_CLASS' => []
    );
    static protected $arComponentParameters = null;
    /** @var \CMain $app */
    public $app;
    /** @var HttpRequest $request */
    public $request;
    protected $arUrlTemplates = array();
    protected $arVariableAliases = array();
    protected $componentRoute = 'index';
    protected $arVariables = array();
    protected $arComponentVariables = array();

    protected $componentRouteVariables = array();
    private $callable;

    /**
     * Инициализирует родной битриксовый класс и готовит полезные переменные
     * BaseComponent constructor.
     * @param \CBitrixComponent|null $component
     */
    public function __construct($component = null)
    {
        parent::__construct($component);

        /** @var \CMain $APPLICATION */
        global $APPLICATION;
        $this->app = $APPLICATION;
        $this->request = Application::getInstance()->getContext()->getRequest();
    }

    /**
     * Возвращает основные настройки для ипользования в .parameters.php
     * @return array
     */
    public static function getArComponentParameters()
    {
        return array(
            "PARAMETERS" => array(
                "CACHE_TIME" => array('DEFAULT' => 3600),
                "GREEDY_PARTS" => Array(
                    "PARENT" => "ADDITIONAL_SETTINGS",
                    "NAME" => Loc::getMessage("MVC_GREEDY_PARTS"),
                    "TYPE" => "STRING",
                    "MULTIPLE" => "N",
                    "DEFAULT" => "",
                ),
            )
        );
    }

    public function onPrepareComponentParams($arParams)
    {
        $arParams = parent::onPrepareComponentParams($arParams);
        $arParams = array_merge(static::$internalComponentParams, $arParams);
        if (!isset($this->arParams['MVC_GREEDY_PARTS'])) {
            $this->arParams['MVC_GREEDY_PARTS'] = array();
        } elseif (is_string($this->arParams['MVC_GREEDY_PARTS'])) {
            $this->arParams['MVC_GREEDY_PARTS'] = explode(',', $this->arParams['MVC_GREEDY_PARTS']);
        }

        return $arParams;
    }

    public function executeComponent()
    {
        if ($this->getSEF_Settings()) {
            $this->runAction();

        } else {
            $this->showError(self::ERR_404);
        }
    }

    /**
     * Инициализирует ЧПУ из настроек компонента
     * @return bool
     */
    protected function getSEF_Settings()
    {
        if ($this->arParams['SEF_MODE'] == 'Y') {

            $engine = new \CComponentEngine($this);

            foreach ($this->arParams['MVC_GREEDY_PARTS'] as $part) {
                $engine->addGreedyPart($placeholder);
            }

            $this->arUrlTemplates = \CComponentEngine::MakeComponentUrlTemplates(array(), $this->arParams["SEF_URL_TEMPLATES"]);
            $this->arVariableAliases = \CComponentEngine::MakeComponentVariableAliases(array(), $this->arParams["VARIABLE_ALIASES"]);
            $this->componentRoute = $engine->guessComponentPath($this->arParams["SEF_FOLDER"], $this->arUrlTemplates, $this->arVariables);

            if (!$this->componentRoute) {
                if (static::isPathsEqual($this->request->getRequestedPageDirectory(), $this->arParams["SEF_FOLDER"])) {
                    $this->genCallable(true);

                    return true;
                } else {
                    return false;
                }

            } else {
                \CComponentEngine::InitComponentVariables($this->componentRoute, $this->arComponentVariables, $this->arVariableAliases, $this->arVariables);

                //переставляем переменные в том порядке, к вотором они должны попасть в функцию
                $componentParameters = $this->getComponentParameters();
                if (isset($componentParameters['PARAMETERS']['SEF_MODE'][$this->componentRoute]['VARIABLES'])) {
                    $parametersVars = $componentParameters['PARAMETERS']['SEF_MODE'][$this->componentRoute]['VARIABLES'];
                    foreach ($parametersVars as $varName) {
                        if (isset($this->arVariables[$varName])) {
                            $this->componentRouteVariables[] = $this->arVariables[$varName];
                        } else {
                            $this->componentRouteVariables[] = null;
                        }
                    }
                }

                $this->genCallable();

                return true;
            }

        } else {
            $this->genCallable(true);

            return true;
        }
    }

    /**
     * Выполняет действие
     * @throws NotImplementedException
     */
    protected function runAction()
    {
        try {
            if ($this->request->isAjaxRequest() OR $this->request->isPost()) {
                if ($this->arParams['AJAX_CHECK_SESSID'] == 'Y' AND !check_bitrix_sessid()) {
                    throw new AjaxException('Session expired');
                }

                try {
                    $this->callable[1] .= 'Ajax';
                    if (is_callable($this->callable)) {

                        $response = $this->callActionFunction();
                        $this->arResult['AJAX'] = $response;
                        $this->sendAjaxResponse();

                    } else {
                        $this->throwNotImplemented();
                    }

                } catch (\Exception $e) {
                    throw new AjaxException($e->getMessage(), $e->getCode(), $e);
                }

            } elseif (is_callable($this->callable)) {
                $this->callActionFunction();

            } else {
                $this->throwNotImplemented();
            }

        } catch (AjaxException $e) {
            $response = array('success' => false);
            if ($this->arParams['VERBOSE'] == 'Y') {
                $response['exception'] = array(
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'code' => $e->getCode()
                );
            }

            $this->arResult['AJAX'] = $response;
            $this->sendAjaxResponse();

        } catch (\Exception $e) {
            if ($this->isDebugMode()) {
                throw $e;
            } else {
                $this->showError(self::ERR_EXCEPTION, $e);
            }
        }
    }

    /**
     * Пользовательский обработчик страницы ошибок.
     * Не работает для AJAX-режима.
     * @param string $type тип ошибки
     * @param mixed $data данные об ошибке
     */
    protected function showError($type, $data)
    {
        if ($this->app->RestartWorkarea()) {
            require(\Bitrix\Main\Application::getDocumentRoot() . "/404.php");
        }
    }

    /**
     * Сранивает два пути к каталогам.
     * Сделано во избежании ошибок с завершающими слешами
     * @see \CComponentEngine::guessComponentPath()
     * @param $path1
     * @param $path2
     * @return bool
     */
    final public static function isPathsEqual($path1, $path2)
    {
        $path1 = "/" . trim($path1, "/ \t\n\r\0\x0B") . "/";
        $path2 = "/" . trim($path2, "/ \t\n\r\0\x0B") . "/";

        return $path1 == $path2;
    }

    /**
     * Формирует callable-объект для вызова экшена.
     * Поддерживается аналог "Standalone actions" в Yii2: если в нстройках указано использовать другой класс в качестве экшена, то использует его.
     * @param bool $default - сбрасывает настройки для вызова экшена "по-умолчанию"
     */
    private function genCallable($default = false)
    {
        if ($default) {
            $this->componentRoute = 'index';
            $this->callable = array(
                $this,
                'action' . ucfirst($this->componentRoute)
            );

        } else {
            if (isset($this->arParams['ACTION_CLASS'][$this->componentRoute])) {
                $actionClass = $this->arParams['ACTION_CLASS'][$this->componentRoute];
                if (is_object($actionClass) OR class_exists($actionClass)) {
                    $this->callable = array(
                        $actionClass,
                        'run'
                    );
                }
            } else {
                $this->callable = array(
                    $this,
                    'action' . ucfirst($this->componentRoute)
                );
            }
        }
    }

    /**
     * Получает параетры компонента из .parameters.php
     * @return null|array
     */
    private function getComponentParameters()
    {
        if (is_null(static::$arComponentParameters)) {
            $this->reflection = new \ReflectionClass($this);
            $componentDir = dirname($this->reflection->getFileName()) . '/';
            include $componentDir . '.parameters.php';
            static::$arComponentParameters = $arComponentParameters;
        }

        return static::$arComponentParameters;
    }

    /**
     * Выполняет непосредственно вызов нужной функции, делает первичную обработку ошибок.
     * @return mixed результат выполнения action
     * @throws \Exception
     */
    private function callActionFunction()
    {
        $cacheOptions = $this->configureCacheAction();

        //Если кеш выключен, то выполняем так
        if ($cacheOptions === false) {
            $response = call_user_func_array($this->callable, $this->componentRouteVariables);
            if ($result === false) {
                throw new \Exception("Error executing route's {$this->componentRoute} action");
            }

        } elseif (($cacheOptions === true AND $this->startResultCache()) //Если включен кеш без дополнительных параметров
            OR (is_string($cacheOptions) AND $this->startResultCache($this->arParams['CACHE_TIME'], $cacheOptions)) //Или если сдополнительными параметрами
        ) {
            //То всё рвно выполняем, но кеширование уже началось ;-)
            $response = call_user_func_array($this->callable, $this->componentRouteVariables);

            if ($response === false) {
                throw new \Exception("Error executing route's {$this->componentRoute} action");

            } elseif (!$this->isTemplateRendered()) {
                $componentPage = $this->componentRoute ? $this->componentRoute : "";
                $this->includeComponentTemplate($componentPage);
            }
        }

        return $response;
    }

    /**
     * Отправляет результат запроса на клиент
     * @internal
     */
    protected function sendAjaxResponse()
    {
        $response = $this->arResult['AJAX'];
        if ($response !== self::SKIP_AJAX_EXECUTION) {

            if (is_array($response) AND !isset($response ['success'])) {
                $response ['success'] = true;
            } elseif (is_bool($this->arResult)) {
                $response = array('success' => $this->arResult);
            }

            $this->app->RestartBuffer();
            print json_encode($response);
            exit();
        }
    }

    /**
     * Выбрасывает исключение, когда нужный нам метод не реализован
     * @param bool $ajax
     * @throws NotImplementedException
     */
    private function throwNotImplemented($ajax = false)
    {
        $className = $this->callable[0];
        if (is_object($className)) {
            $className = get_class($className);
        }
        throw new NotImplementedException("Function {$className}::{$this->callable[1]} does not exists!");
    }

    /**
     * Определяет, включен ли режим отладки для сайта.
     * Можно переопределять функцию, в зависимости от устройства каждого конкретного сайта.
     * @return bool
     */
    public function isDebugMode()
    {
        if (defined('BX_DEBUG') AND BX_DEBUG == 'Y') {
            return true;
        }

        return false;
    }

    /**
     * Получает дополнительные параметры кеширования для отдельного действия
     * @return bool|mixed
     */
    private function configureCacheAction()
    {
        if (isset($this->arParams['CACHE_ACTION'][$this->componentRoute])) {
            $cacheOption = $this->arParams['CACHE_ACTION'][$this->componentRoute];
            if (is_callable($cacheOption)) {
                $cacheOption = call_user_func($cacheOption);

                return $this->getActionCacheID($cacheOption);

            } elseif (is_string($cacheOption)) {
                if ($cacheOption == 'N') {
                    return false;
                }
            }
        }

        return $this->getActionCacheID();
    }

    /**
     * Проверяет, не выводили ли мы шаблон ранее.
     * @return bool
     */
    protected function isTemplateRendered()
    {
        return !is_null($this->__template);
    }

    /**
     * Модифицирует ID кеша так, чтобы для каждого экшена кеш был свой.
     * @param $cacheID
     * @return string
     */
    private function getActionCacheID($cacheID)
    {
        return $this->componentRoute . serialize($this->componentRouteVariables) . (is_null($cacheID) ? "" : $cacheID);
    }
}